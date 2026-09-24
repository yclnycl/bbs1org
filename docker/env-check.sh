#!/bin/sh
# 环境一致性校验：把本地开发栈与线上生产栈逐项比对（只读，不改任何东西）。
#
#   ./env-check.sh                                        # 比对默认线上
#   ./env-check.sh --host root@1.2.3.4 --remote-dir /opt/bbs1org-deploy/bbs1org_docker
#   ./env-check.sh --local-only                           # 不上服务器，只打印本地环境
#
# 判定标准：镜像、PHP 版本、扩展、ini、nginx 配置、composer.lock 必须一致；
# opcache.validate_timestamps 是本地唯一允许的差异（开发态打开、线上关闭）。
set -eu

# Git Bash 会把 /var/www/html 这类容器内路径改写成 Windows 路径，先关掉这个转换
case "$(uname -s)" in MINGW*|MSYS*|CYGWIN*) export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*' ;; esac

cd "$(dirname "$0")"

HOST=root@47.238.230.97
REMOTE_DIR=/opt/bbs1org-deploy/bbs1org_docker
PROJECT=bbs1org
LOCAL_ONLY=0

while [ $# -gt 0 ]; do
    case "$1" in
        --host) HOST="$2"; shift 2 ;;
        --remote-dir) REMOTE_DIR="$2"; shift 2 ;;
        --project) PROJECT="$2"; shift 2 ;;
        --local-only) LOCAL_ONLY=1; shift ;;
        *) echo "未知参数：$1" >&2; exit 2 ;;
    esac
done

fails=0
notes=0
SSH="ssh -o BatchMode=yes -o ConnectTimeout=10 $HOST"
LOCAL_COMPOSE="docker compose -f docker-compose.yml -f docker-compose.dev.yml"

# 线上容器：按 compose 项目 + service 标签找，不写死容器名
remote_ctr() { # remote_ctr <service> [容器内命令...]
    service="$1"; shift
    $SSH "c=\$(docker ps -q -f label=com.docker.compose.project=$PROJECT -f label=com.docker.compose.service=$service | head -1)
          [ -n \"\$c\" ] || { echo NO_CONTAINER; exit 9; }
          docker exec -i \$c $*"
}

# ---------- 采集 ----------

snapshot_local() {
    $LOCAL_COMPOSE exec -T php php /var/www/html/docker/env-snapshot.php 2>/dev/null \
    || $LOCAL_COMPOSE run --rm --no-deps php php /var/www/html/docker/env-snapshot.php
}

snapshot_remote() {
    # 优先用发布目录里已经带过去的脚本；线上还没有这个文件时，从本地塞一份到 /tmp 再跑
    if remote_ctr php "php /var/www/html/docker/env-snapshot.php" 2>/dev/null | grep -q '^php_version='; then
        return 0
    fi
    $SSH "c=\$(docker ps -q -f label=com.docker.compose.project=$PROJECT -f label=com.docker.compose.service=php | head -1)
          docker exec -i \$c sh -c 'cat > /tmp/env-snapshot.php' && APP_ROOT=/var/www/html docker exec -i -e APP_ROOT=/var/www/html \$c php /tmp/env-snapshot.php" < env-snapshot.php
}

value_of() { [ -f "$1" ] && sed -n "s/^$2=//p" "$1" | head -1 || true; }

compare() { # compare <说明> <本地值> <线上值> [allowed]
    label="$1"; local_v="$2"; remote_v="$3"; mode="${4:-strict}"
    if [ "$LOCAL_ONLY" -eq 1 ]; then
        printf '        %-30s %s\n' "$label" "$local_v"
        return
    fi
    if [ "$local_v" = "$remote_v" ]; then
        printf '  一致   %-29s %s\n' "$label" "$local_v"
    elif [ "$mode" = "allowed" ]; then
        notes=$((notes + 1))
        printf '  已知差异 %-27s 本地 %s / 线上 %s\n' "$label" "$local_v" "$remote_v"
    else
        fails=$((fails + 1))
        printf '  不一致 %-27s 本地 %s / 线上 %s\n' "$label" "$local_v" "$remote_v"
    fi
}

hash16() { sha256sum "$1" 2>/dev/null | cut -c1-16; }

local_snap=$(mktemp)
remote_snap=$(mktemp)
local_ext_file=$(mktemp)
remote_ext_file=$(mktemp)
trap 'rm -f "$local_snap" "$remote_snap" "$local_ext_file" "$remote_ext_file"' EXIT

echo "环境一致性校验"
echo "  本地  $(docker compose version --short 2>/dev/null || echo 'docker compose') / $(uname -s)"
[ "$LOCAL_ONLY" -eq 1 ] || echo "  线上  $HOST:$REMOTE_DIR（compose 项目 $PROJECT）"
echo

snapshot_local > "$local_snap"

if [ "$LOCAL_ONLY" -eq 0 ]; then
    if ! snapshot_remote > "$remote_snap" 2>/dev/null || ! grep -q '^php_version=' "$remote_snap"; then
        echo "线上采集失败：检查 ssh $HOST、compose 项目 $PROJECT 是否在运行。" >&2
        exit 1
    fi
fi

echo "【镜像】"
for image in serversideup/php:8.5-fpm nginx:alpine; do
    local_id=$(docker image inspect "$image" --format '{{.Id}}' 2>/dev/null | cut -c8-19 || echo n/a)
    if [ "$LOCAL_ONLY" -eq 1 ]; then
        remote_id=""
    else
        remote_id=$($SSH "docker image inspect '$image' --format '{{.Id}}' 2>/dev/null | cut -c8-19" || echo n/a)
    fi
    compare "$image" "$local_id" "${remote_id:-n/a}"
done
echo

echo "【PHP 运行时】"
compare "PHP 版本" "$(value_of "$local_snap" php_version)" "$(value_of "$remote_snap" php_version)"
for key in max_execution_time memory_limit upload_max_filesize post_max_size max_input_vars max_file_uploads date.timezone display_errors error_reporting log_errors realpath_cache_size realpath_cache_ttl opcache.enable opcache.memory_consumption opcache.save_comments opcache.jit session.cookie_secure expose_php; do
    compare "ini:$key" "$(value_of "$local_snap" "ini:$key")" "$(value_of "$remote_snap" "ini:$key")"
done
# 本地开发态要打开时间戳校验（改代码立即生效），线上必须关闭（靠重启 php 生效）
compare "ini:opcache.validate_timestamps" "$(value_of "$local_snap" 'ini:opcache.validate_timestamps')" "$(value_of "$remote_snap" 'ini:opcache.validate_timestamps')" allowed
echo

echo "【扩展】"
local_exts=$(value_of "$local_snap" extensions)
remote_exts=$(value_of "$remote_snap" extensions)
if [ "$LOCAL_ONLY" -eq 0 ]; then
    # 扩展名里有空格（Zend OPcache），按行比对，不能按词切分
    printf '%s' "$local_exts" | tr ';' '\n' | sort > "$local_ext_file"
    printf '%s' "$remote_exts" | tr ';' '\n' | sort > "$remote_ext_file"
    compare "扩展数量" "$(grep -c . "$local_ext_file")" "$(grep -c . "$remote_ext_file")"
    while IFS= read -r ext; do
        [ -n "$ext" ] || continue
        grep -Fxq "$ext" "$local_ext_file" || { fails=$((fails + 1)); printf '  缺失   %-29s 线上有、本地没有\n' "$ext"; }
    done < "$remote_ext_file"
    while IFS= read -r ext; do
        [ -n "$ext" ] || continue
        grep -Fxq "$ext" "$remote_ext_file" || { notes=$((notes + 1)); printf '  多出   %-29s 本地有、线上没有\n' "$ext"; }
    done < "$local_ext_file"
fi
for ext in pdo_sqlite sqlite3 mbstring json tokenizer ctype fileinfo session dom xml zlib openssl; do
    compare "ext:$ext" "$(value_of "$local_snap" "ext:$ext")" "$(value_of "$remote_snap" "ext:$ext")"
done
echo

echo "【配置与依赖】"
compare "仓库 nginx.conf ↔ 线上容器" "$(hash16 nginx.conf)" "$([ "$LOCAL_ONLY" -eq 1 ] && echo '' || remote_ctr nginx "sha256sum /etc/nginx/conf.d/default.conf" | cut -c1-16)"
compare "仓库 opcache.ini ↔ 线上容器" "$(hash16 opcache.ini)" "$([ "$LOCAL_ONLY" -eq 1 ] && echo '' || remote_ctr php "sha256sum /usr/local/etc/php/conf.d/zzz-opcache.ini" | cut -c1-16)"
compare "线上部署目录的 nginx.conf" "$(hash16 nginx.conf)" "$([ "$LOCAL_ONLY" -eq 1 ] && echo '' || $SSH "sha256sum $REMOTE_DIR/nginx.conf" 2>/dev/null | cut -c1-16 || echo n/a)"
compare "线上部署目录的 opcache.ini" "$(hash16 opcache.ini)" "$([ "$LOCAL_ONLY" -eq 1 ] && echo '' || $SSH "sha256sum $REMOTE_DIR/opcache.ini" 2>/dev/null | cut -c1-16 || echo n/a)"
compare "composer.lock" "$(value_of "$local_snap" composer_lock | cut -c1-16)" "$(value_of "$remote_snap" composer_lock | cut -c1-16)"
compare "应用版本" "$(value_of "$local_snap" app_version)" "$(value_of "$remote_snap" app_version)"
compare "vendor 已安装" "$(value_of "$local_snap" vendor)" "$(value_of "$remote_snap" vendor)"
compare "app/data 可写" "$(value_of "$local_snap" data_dir_writable)" "$(value_of "$remote_snap" data_dir_writable)"
echo

[ "$LOCAL_ONLY" -eq 1 ] && exit 0

if [ "$fails" -gt 0 ]; then
    echo "结论：$fails 项不一致。先对齐再发布："
    echo "  本地： docker compose pull && ./dev.sh up"
    echo "  线上： 见 docs/DEPLOY-PRODUCTION.md 「镜像对齐」一节"
    exit 1
fi
printf '结论：一致（另有 %s 项已知差异／信息项，见上）\n' "$notes"
