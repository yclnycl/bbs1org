#!/bin/sh
# 生产发布脚本：在开发机上执行，通过 ssh 操作服务器（--host local 时在本机演练）。
#
#   ./deploy.sh status                 # 看线上容器状态与最近日志
#   ./deploy.sh db-backup              # 备份线上 SQLite（VACUUM INTO，可在线做）
#   ./deploy.sh release                # 发布：备份 → 打包 → 上传 → 同步配置 → 重建 → 健康检查
#   ./deploy.sh rollback               # 回滚到上一个版本（配置与源码指针都还原）
#   ./deploy.sh rollback --restore-db /opt/bbs1org-deploy/backups/db-xxx.sqlite
#
# 选项：
#   --host <目标>    默认 root@47.238.230.97；填 local 表示在本机执行（发布演练用）
#   --dir <部署根>   默认 /opt/bbs1org-deploy，内含 bbs1org_docker/、releases/、backups/
#   --project <名>   compose 项目名，决定数据卷前缀，默认 bbs1org
#   --port <端口>    nginx 对外端口，默认 8080
#   --sync-images    两边镜像 ID 不一致时，用 docker save | docker load 把本地镜像推过去
#   --skip-checks    跳过本地冒烟与环境校验
#   --dry-run        只打印将要执行的命令，不改任何东西
set -eu

case "$(uname -s)" in MINGW*|MSYS*|CYGWIN*) export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*' ;; esac

cd "$(dirname "$0")"
APP_ROOT=$(cd .. && pwd)

HOST=root@47.238.230.97
DEPLOY_DIR=/opt/bbs1org-deploy
PROJECT=bbs1org
PORT=8080
SYNC_IMAGES=0
SKIP_CHECKS=0
DRY_RUN=0
RESTORE_DB=
CMD=
STAMP=$(date +%Y%m%d-%H%M%S)
SSH_OPTS="-o BatchMode=yes -o ConnectTimeout=15"

while [ $# -gt 0 ]; do
    case "$1" in
        --host) HOST="$2"; shift 2 ;;
        --dir) DEPLOY_DIR="$2"; shift 2 ;;
        --project) PROJECT="$2"; shift 2 ;;
        --port) PORT="$2"; shift 2 ;;
        --sync-images) SYNC_IMAGES=1; shift ;;
        --skip-checks) SKIP_CHECKS=1; shift ;;
        --dry-run) DRY_RUN=1; shift ;;
        --restore-db) RESTORE_DB="$2"; shift 2 ;;
        -h|--help) sed -n '2,18p' "$0"; exit 0 ;;
        *) CMD="$1"; shift ;;
    esac
done
CMD="${CMD:-release}"

DOCKER_DIR="$DEPLOY_DIR/bbs1org_docker"
RELEASES="$DEPLOY_DIR/releases"
BACKUP_DIR="$DEPLOY_DIR/backups/$STAMP"

run() { # 在目标机执行；--host local 时直接在本机跑，便于发布演练
    if [ "$HOST" = local ]; then
        sh -c "$1"
    else
        ssh $SSH_OPTS "$HOST" "$1"
    fi
}

dcx() { # 在部署目录里执行 docker compose 子命令
    run "cd '$DOCKER_DIR' && docker compose $*"
}

say() { printf '\n=== %s\n' "$*"; }

fail() { printf '错误：%s\n' "$*" >&2; exit 1; }

php_ctr() { # 线上 php / nginx 容器名
    run "docker ps -q -f label=com.docker.compose.project=$PROJECT -f label=com.docker.compose.service=$1 | head -1"
}

db_file_in_container() { # 当前应用在用的库文件名（读容器里的 db.php）
    run "docker exec \$(docker ps -q -f label=com.docker.compose.project=$PROJECT -f label=com.docker.compose.service=php | head -1) \
         php -r '\\\$c = is_file(\"/var/www/html/app/data/db.php\") ? include \"/var/www/html/app/data/db.php\" : []; echo basename(\\\$c[\"database\"] ?? \\\$c[\"db_file\"] ?? \"forum.sqlite\");'"
}

do_db_backup() { # 发布前/手动：把线上库快照到部署目录的 backups/
    say "备份线上数据库"
    ctr=$(php_ctr php)
    if [ -z "$ctr" ]; then
        echo "  线上没有运行中的 php 容器，跳过"
        return 0
    fi
    # 全新部署时还没有库文件，这不是错误
    if ! run "docker exec $ctr sh -c 'ls /var/www/html/app/data/*.sqlite >/dev/null 2>&1'"; then
        echo "  线上还没有数据库文件（全新部署），跳过"
        return 0
    fi
    if [ "$DRY_RUN" -eq 1 ]; then
        echo "  [dry-run] VACUUM INTO + cp 出容器"
        return 0
    fi
    dcx "exec -T php php /var/www/html/docker/db-backup.php /var/www/html/app/data/publish-backup-$STAMP.sqlite"
    run "mkdir -p '$DEPLOY_DIR/backups'"
    dcx "cp php:/var/www/html/app/data/publish-backup-$STAMP.sqlite '$DEPLOY_DIR/backups/db-$STAMP.sqlite'"
    dcx "exec -T php rm -f /var/www/html/app/data/publish-backup-$STAMP.sqlite"
    echo "  已保存：$DEPLOY_DIR/backups/db-$STAMP.sqlite"
}

check_target() {
    [ "$HOST" = local ] || run "true" || fail "无法 ssh 到 $HOST"
    run "docker --version >/dev/null" || fail "目标机没有 docker"
    if [ ! -f "$APP_ROOT/vendor/autoload.php" ]; then
        fail "本地没有 vendor/：先执行 ./dev.sh composer install（发布包会带着 vendor 一起上传）"
    fi
    if ! run "test -f '$DOCKER_DIR/docker-compose.yml'"; then
        echo "  首次发布：$DOCKER_DIR 还不存在，本次会创建"
    fi
}

check_images() {
    for image in serversideup/php:8.5-fpm nginx:alpine; do
        local_id=$(docker image inspect "$image" --format '{{.Id}}' 2>/dev/null || echo missing)
        remote_id=$(run "docker image inspect '$image' --format '{{.Id}}' 2>/dev/null || echo missing")
        if [ "$local_id" = "$remote_id" ]; then
            echo "  一致  $image"
            continue
        fi
        [ "$SYNC_IMAGES" -eq 1 ] || fail "镜像不一致：$image（本地 ${local_id#sha256:} / 目标 ${remote_id#sha256:}）
  两边跑同一份镜像，环境才算一致。二选一：
    1) 本次顺带推送：./deploy.sh $CMD --sync-images
    2) 让目标机自己拉：ssh $HOST 'cd $DOCKER_DIR && docker compose pull'，再在本地 docker compose pull 对齐"
        say "推送本地镜像到目标机：$image"
        if [ "$DRY_RUN" -eq 1 ]; then
            echo "  [dry-run] docker save $image | (目标机) docker load"
        elif [ "$HOST" = local ]; then
            echo "  本机就是目标机，无需传输"
        else
            docker save "$image" | run "docker load"
        fi
    done
}

package_release() {
    say "上传源码 → $RELEASES/$STAMP"
    if [ "$DRY_RUN" -eq 1 ]; then
        echo "  [dry-run] tar czf - | (目标机) tar xzf -"
        return 0
    fi
    # 只排除运行数据与本机配置；vendor 一起传（服务器不联外网也能发布，依赖完全一致）
    tar czf - -C "$APP_ROOT" \
        --exclude=./.git \
        --exclude=./docker/.env \
        --exclude=./docker/.opcache.dev.ini \
        --exclude=./app/data \
        --exclude='./*.log' \
        . | run "mkdir -p '$RELEASES/$STAMP' && tar xzf - -C '$RELEASES/$STAMP'"
    run "test -f '$RELEASES/$STAMP/index.php'" || fail "上传后没找到 index.php，检查 tar 是否正常"
}

sync_config() {
    say "同步编排与配置 → $DOCKER_DIR"
    if [ "$DRY_RUN" -eq 1 ]; then
        echo "  [dry-run] docker-compose.yml / nginx.conf / opcache.ini / .env"
        return 0
    fi
    run "mkdir -p '$DOCKER_DIR'"
    for file in docker-compose.yml nginx.conf opcache.ini; do
        run "cp '$RELEASES/$STAMP/docker/$file' '$DOCKER_DIR/$file'"
    done
    if ! run "test -f '$DOCKER_DIR/.env'"; then
        run "cp '$RELEASES/$STAMP/docker/.env.example' '$DOCKER_DIR/.env'"
    fi
    # .env 是服务器自己的：只改源码路径，缺的键补上，已有值不动
    if run "grep -q '^BBS1ORG_PATH=' '$DOCKER_DIR/.env'"; then
        run "sed -i 's|^BBS1ORG_PATH=.*|BBS1ORG_PATH=$RELEASES/$STAMP|' '$DOCKER_DIR/.env'"
    else
        run "echo 'BBS1ORG_PATH=$RELEASES/$STAMP' >> '$DOCKER_DIR/.env'"
    fi
    # 对外端口与项目名由命令行参数决定（这两个写错的后果分别是端口冲突和指向另一个空库）
    if run "grep -q '^HTTP_PORT=' '$DOCKER_DIR/.env'"; then
        run "sed -i 's|^HTTP_PORT=.*|HTTP_PORT=$PORT|' '$DOCKER_DIR/.env'"
    else
        run "echo 'HTTP_PORT=$PORT' >> '$DOCKER_DIR/.env'"
    fi
    if run "grep -q '^COMPOSE_PROJECT_NAME=' '$DOCKER_DIR/.env'"; then
        run "sed -i 's|^COMPOSE_PROJECT_NAME=.*|COMPOSE_PROJECT_NAME=$PROJECT|' '$DOCKER_DIR/.env'"
    else
        run "echo 'COMPOSE_PROJECT_NAME=$PROJECT' >> '$DOCKER_DIR/.env'"
    fi
    echo "  当前 .env："
    run "sed 's/^/    /' '$DOCKER_DIR/.env'"
}

compose_up() {
    say "重建容器（配置是 bind mount，必须重建才会读到新文件，同时清掉旧 opcache）"
    if [ "$DRY_RUN" -eq 1 ]; then
        echo "  [dry-run] docker compose up -d --remove-orphans --force-recreate"
        return 0
    fi
    dcx "up -d --remove-orphans --force-recreate"
}

health_check() {
    say "健康检查"
    fails=0
    check() { # check <期望码> <路径> <说明>
        code=$(run "c=\$(docker ps -q -f label=com.docker.compose.project=$PROJECT -f label=com.docker.compose.service=nginx | head -1)
                   [ -n \"\$c\" ] && docker exec \$c wget -q -O /dev/null -S http://127.0.0.1$2 2>&1 | sed -n 's|.*HTTP/[0-9.]* \([0-9][0-9][0-9]\).*|\1|p' | head -1" || true)
        if [ "$code" = "$1" ]; then
            printf '  ok    %-4s %s\n' "$code" "$3"
        else
            printf '  FAIL  期望 %s 实际 %s  %s\n' "$1" "${code:-无响应}" "$3"
            fails=$((fails + 1))
        fi
    }
    check 200 / 首页
    check 200 /login 登录页
    check 200 /app/assets/index.js 前端脚本
    check 403 /app/data/db.php 数据目录不可访问
    check 403 /vendor/autoload.php 依赖目录不可访问
    check 403 /templates/layout.html.twig 模板不可访问
    if [ "$fails" -gt 0 ]; then
        say "最近 PHP 日志"
        dcx "logs --tail=40 php" || true
        return 1
    fi
    return 0
}

restore_db() { # restore_db <宿主机上的 sqlite 文件>
    file="$1"
    run "test -f '$file'" || fail "找不到要恢复的文件：$file"
    say "恢复数据库：$file"
    # 卷里现存的库就是目标（db.php 读不到时以卷内容为准，避免把恢复写进一个应用不用的新文件）
    name=$(run "docker run --rm -v ${PROJECT}_data:/data nginx:alpine sh -c '
        f=\$(ls /data/*.sqlite 2>/dev/null | grep -v pre-rollback | head -1)
        [ -n \"\$f\" ] && basename \"\$f\"'" || true)
    [ -n "$name" ] || name=$(db_file_in_container 2>/dev/null || true)
    [ -n "$name" ] || name=forum.sqlite
    echo "  目标库文件名：$name"
    # 直接在数据卷里换文件，换下来的旧库留一份 .pre-rollback
    run "docker run --rm -v ${PROJECT}_data:/data -v '$file':/restore.sqlite nginx:alpine sh -c '
        set -e
        [ -f /data/$name ] && cp -a /data/$name /data/$name.pre-rollback
        cp /restore.sqlite /data/$name
        rm -f /data/$name-wal /data/$name-shm
        chown 33:33 /data/$name
        ls -l /data/$name /data/$name.pre-rollback 2>/dev/null | sed \"s/^/    /\"'"
}

case "$CMD" in
    status)
        dcx "ps"
        say "最近日志"
        dcx "logs --tail=30 php" || true
        ;;

    db-backup)
        do_db_backup
        ;;

    release)
        say "0/6 前置检查"
        check_target
        if [ "$SKIP_CHECKS" -eq 0 ]; then
            if docker compose -f docker-compose.yml -f docker-compose.dev.yml ps --status running 2>/dev/null | grep -q php; then
                ./dev.sh smoke || fail "本地冒烟没通过，先修好（或加 --skip-checks）"
            else
                echo "  本地开发栈没在跑，跳过冒烟（先 ./dev.sh up 可以打开）"
            fi
            if [ -n "$(git -C "$APP_ROOT" status --porcelain 2>/dev/null | head -1)" ]; then
                echo "  注意：工作区有未提交的改动，这次发布包含它们"
            fi
        fi

        say "1/6 镜像对齐"
        check_images

        say "2/6 备份线上数据库与现有配置"
        do_db_backup
        if run "test -d '$DOCKER_DIR'"; then
            if [ "$DRY_RUN" -eq 1 ]; then
                echo "  [dry-run] 备份 $DOCKER_DIR → $BACKUP_DIR"
            else
                run "mkdir -p '$BACKUP_DIR' && cp -a '$DOCKER_DIR/.' '$BACKUP_DIR/'"
                echo "  已备份：$BACKUP_DIR"
            fi
        fi

        say "3/6 上传源码"
        package_release

        say "4/6 同步配置"
        sync_config

        say "5/6 重建容器"
        compose_up

        say "6/6 健康检查"
        if [ "$DRY_RUN" -eq 1 ]; then
            echo "  [dry-run] 跳过"
        elif health_check; then
            printf '\n发布完成：%s\n' "$RELEASES/$STAMP"
            printf '回滚命令：./deploy.sh rollback --host %s --dir %s\n' "$HOST" "$DEPLOY_DIR"
        else
            printf '\n健康检查未通过，建议回滚：\n  ./deploy.sh rollback --host %s --dir %s\n' "$HOST" "$DEPLOY_DIR" >&2
            exit 1
        fi
        ;;

    rollback)
        say "还原部署配置与源码指针"
        latest=$(run "ls -1dt '$DEPLOY_DIR'/backups/*/ 2>/dev/null | head -1" || true)
        [ -n "$latest" ] || fail "没有找到 $DEPLOY_DIR/backups/ 下的备份"
        echo "  使用备份：$latest"
        if [ "$DRY_RUN" -eq 1 ]; then
            echo "  [dry-run] cp -a ${latest}. → $DOCKER_DIR/"
        else
            run "cp -a '${latest}.' '$DOCKER_DIR/'"
        fi
        [ -z "$RESTORE_DB" ] || restore_db "$RESTORE_DB"
        compose_up
        if [ "$DRY_RUN" -eq 1 ]; then exit 0; fi
        health_check && printf '\n回滚完成\n'
        ;;

    *)
        sed -n '2,18p' "$0"
        exit 2
        ;;
esac
