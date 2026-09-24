#!/bin/sh
# 本地开发环境入口。生产环境请用 docker/deploy.sh，不要在这里加生产逻辑。
#
#   ./dev.sh up            起开发栈（默认打开 opcache 时间戳校验，改代码立即生效）
#   ./dev.sh up --strict   起开发栈，但用生产原样的 opcache 配置
#   ./dev.sh down          停止（保留数据卷）
#   ./dev.sh reset         停止并删除数据卷，下次访问重新初始化
#   ./dev.sh ps|logs|sh|restart
#   ./dev.sh composer <参数>  在 php 容器里跑 composer（依赖按生产镜像的 PHP 解析）
#   ./dev.sh import-db <文件> [卷内文件名]  把线上快照导入数据卷，用真实数据联调
#   ./dev.sh smoke         跑一遍 HTTP 冒烟与安全自检
set -eu

# Git Bash 会把 /var/www/html 这类容器内路径改写成 Windows 路径，先关掉这个转换
case "$(uname -s)" in MINGW*|MSYS*|CYGWIN*) export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*' ;; esac

cd "$(dirname "$0")"

COMPOSE="docker compose -f docker-compose.yml"

gen_dev_ini() {
    # 从 opcache.ini 派生开发用 ini：只有 validate_timestamps 一行不同，
    # 保证本地与生产的差异是「看得见的一行」而不是两份文件各自漂移。
    sed 's/^opcache\.validate_timestamps=0/opcache.validate_timestamps=1/' opcache.ini > .opcache.dev.ini
}

ensure_env() {
    [ -f .env ] || { cp .env.example .env; echo "已生成 docker/.env（管理员账号见该文件）"; }
    # 旧版 .env 只写了 FORUM_PORT 和站点信息：把端口迁到新键，其余缺什么补什么，
    # 已有值一律不动（.env 是本机配置，脚本不该覆盖用户写过的内容）。
    if ! grep -q '^HTTP_PORT=' .env; then
        old_port=$(sed -n 's/^FORUM_PORT=//p' .env | head -1)
        env_set HTTP_PORT "${old_port:-8090}"
    fi
    env_set BBS1ORG_PATH ..
    env_set COMPOSE_PROJECT_NAME bbs1org
}

env_set() { # env_set <键> <默认值>：仅在键不存在时追加
    grep -q "^$1=" .env || printf '%s=%s\n' "$1" "$2" >> .env
}

cmd="${1:-up}"
[ $# -gt 0 ] && shift

case "$cmd" in
    up)
        strict=0
        for arg in "$@"; do [ "$arg" = "--strict" ] && strict=1; done
        ensure_env
        if [ "$strict" -eq 1 ]; then
            # 不加 dev 覆盖：完全按生产原样启动，用于复现线上才有的问题
            $COMPOSE up -d
            echo "已按生产原样启动（validate_timestamps=0，改 PHP 代码后需 ./dev.sh restart php）"
        else
            gen_dev_ini
            $COMPOSE -f docker-compose.dev.yml up -d
            echo "已启动（本地开发模式：validate_timestamps=1）"
        fi
        port=$(sed -n 's/^HTTP_PORT=//p' .env | head -1)
        echo "访问 http://127.0.0.1:${port:-8080}    管理员：$(sed -n 's/^ADMIN_USERNAME=//p' .env | head -1)"
        ;;
    down)
        $COMPOSE -f docker-compose.dev.yml down
        ;;
    reset)
        $COMPOSE -f docker-compose.dev.yml down -v
        echo "数据卷已删除，下次访问会重新初始化。"
        ;;
    restart)
        $COMPOSE -f docker-compose.dev.yml restart "$@"
        ;;
    ps)
        $COMPOSE -f docker-compose.dev.yml ps
        ;;
    logs)
        $COMPOSE -f docker-compose.dev.yml logs -f --tail=100 "$@"
        ;;
    sh)
        $COMPOSE -f docker-compose.dev.yml exec php sh
        ;;
    composer)
        $COMPOSE -f docker-compose.dev.yml run --rm --no-deps --entrypoint composer php "$@"
        ;;
    import-db)
        file="${1:?用法: ./dev.sh import-db <sqlite 文件> [卷内文件名]}"
        name="${2:-imported.sqlite}"
        [ -f "$file" ] || { echo "找不到文件：$file" >&2; exit 1; }
        $COMPOSE -f docker-compose.dev.yml up -d
        # 直接写进数据卷（容器里就是 app/data），避免 Windows 盘符路径转换的坑
        $COMPOSE -f docker-compose.dev.yml exec -T php sh -c "cat > /var/www/html/app/data/$name" < "$file"
        # db.php 指向这个文件；install.lock 存在时程序不会重新初始化，站点设置与用户都按快照来
        $COMPOSE -f docker-compose.dev.yml exec -T php sh -c "cat > /var/www/html/app/data/db.php" <<EOF
<?php
if (!defined('APP_ROOT')) exit;
return array (
  'driver' => 'sqlite',
  'database' => '$name',
  'db_file' => '$name',
);
EOF
        $COMPOSE -f docker-compose.dev.yml exec -T php sh -c "date +%s > /var/www/html/app/data/install.lock"
        $COMPOSE -f docker-compose.dev.yml exec -u 0 -T php sh -c 'chown -R www-data:www-data /var/www/html/app/data'
        echo "已导入：$name（原库若在数据卷里仍然保留）"
        ;;
    smoke)
        port=$(sed -n 's/^HTTP_PORT=//p' .env | head -1)
        base="http://127.0.0.1:${port:-8080}"
        body=$(mktemp)
        fail=0
        expect() { # expect <期望码> <路径> <说明>
            # 正文写到临时文件而不是 /dev/null：Git Bash 下关了路径转换后，curl 写 /dev/null 会报错，
            # 那会让下面的回退把状态码拼成 200000
            out=$(curl -s -o "$body" -w '%{http_code}' "$base$2" 2>/dev/null) || true
            code=$(printf '%s' "$out" | head -c 3)
            [ -n "$code" ] || code=000
            if [ "$code" = "$1" ]; then
                printf '  ok   %-3s %s\n' "$code" "$3"
            else
                printf '  FAIL 期望 %s 实际 %s  %s\n' "$1" "$code" "$3"
                fail=1
            fi
        }
        echo "冒烟检查 $base"
        expect 200 / 首页
        expect 200 /login 登录页
        expect 200 /app/assets/index.js 前端脚本
        expect 200 /app/assets/vendor/easymde/easymde.min.js 编辑器脚本
        expect 403 /app/data/db.php 数据目录不可访问
        expect 403 /app/optional/Markdown.php 类库不可访问
        expect 403 /vendor/autoload.php 依赖目录不可访问
        expect 403 /templates/layout.html.twig 模板不可访问
        expect 404 /no-such-page 未知地址回落
        rm -f "$body"
        [ "$fail" -eq 0 ] && echo "全部通过" || { echo "有失败项" >&2; exit 1; }
        ;;
    *)
        sed -n '2,12p' "$0"
        exit 1
        ;;
esac
