# 生产部署流程

线上服务器：`47.238.230.97`（Ubuntu 26.04 LTS / Docker 29.1.3 / Compose 2.40.3 / 2 核 3.4G / 系统盘 40G 用了 24%）。
站点域名 `cncttc.com`、`www.cncttc.com`，证书由 acme.sh 自动续期。

本地开发与线上跑的是**同一套编排、同一份镜像、同一份配置**，差别只有 `.env` 里的源码路径与端口。
两边是否真的一致，用 `docker/env-check.sh` 逐项比对，不靠人工记忆。

---

## 一、线上现状（2026-09 勘察）

### 目录

| 路径 | 说明 |
| --- | --- |
| `/opt/bbs1org-deploy/bbs1org` | 当前线上应用源码（旧版，bind mount 进容器） |
| `/opt/bbs1org-deploy/bbs1org_docker` | 编排与配置：`docker-compose.yml`、`nginx.conf`、`opcache.ini`、`cron.sh`、`.env` |
| `/opt/bbs1org-deploy/releases/<时间戳>` | 本流程新增：每次发布的源码目录，回滚就是切回上一个 |
| `/opt/bbs1org-deploy/backups/` | 本流程新增：发布前的配置备份与数据库快照 |

### 容器与数据卷

```
bbs1org-nginx-1        nginx:alpine              0.0.0.0:8080->80
bbs1org-php-1          serversideup/php:8.5-fpm  php-fpm:9000（healthy）
bbs1org-cron-1         serversideup/php:8.5-fpm  每 60 秒跑一次 php index.php cron（旧版 SEO 用，显示 unhealthy 是继承了镜像的 fpm 健康检查）
bbs1org-permissions-1  serversideup/php:8.5-fpm  一次性 chown 后退出
```

| 数据卷 | 挂载点 | 内容 |
| --- | --- | --- |
| `bbs1org_data` | `/var/www/html/app/data` | SQLite 库 `forum-114abe6f564aaf4b.sqlite`（7.5M）、`db.php`、`install.lock`、旧插件缓存 |
| `bbs1org_upload` | `/var/www/html/app/upload` | 空 |
| `bbs1org_avatars` | `/var/www/html/app/avatars` | 空 |
| `bbs1org_plugins` | `/var/www/html/app/plugins` | 旧版插件目录（新版本不使用，保留是为回滚） |

### PHP 运行时（线上实际生效值，本地必须一致）

| 项 | 值 |
| --- | --- |
| 版本 | 8.5.9（`serversideup/php:8.5-fpm`） |
| opcache | 开；`validate_timestamps=0`、`jit=disable`、内存 128M |
| 内存 / 执行时间 | `memory_limit=256M`、`max_execution_time=99` |
| 上传 | `upload_max_filesize=20M`、`post_max_size=128M`、`max_file_uploads=10` |
| 时区 / 报错 | `date.timezone=UTC`、`display_errors=Off`、`error_reporting=22527`、日志进 stderr |
| 扩展 | pdo_sqlite / sqlite3 / mbstring / json / tokenizer / ctype / dom / xml / zlib / openssl …（共 41 个） |

### 宿主 nginx（`/etc/nginx/sites-available/cncttc.com`）

80 端口只做 ACME 验证与 301；443 反代到 `127.0.0.1:8080`，带 HSTS、`client_max_body_size 128m`、
`X-Forwarded-Proto`（应用靠它决定 cookie 是否加 Secure）。

---

## 二、容器内的 Web 规则（`docker/nginx.conf`）

与线上旧配置同源，针对新版本的目录结构补了两类规则：

- 继续禁止访问：`/app/{data,cache,plugins,optional}`、`/app/**/*.php`、点文件
- **新增禁止**：`/vendor`（Composer 依赖里有可执行 PHP，不能经 `location ~ \.php$` 落到 php-fpm）、`/templates`、`/docs`、`composer.json|lock`、`README.md`
- 静态资源缓存从「按文件名白名单 + 1 年 immutable」改为 `/app/assets/**` 统一 1 小时：页面里的 `?v=` 只在 `app/version.php` 变更时才变，1 年 immutable 会让忘记改版本号的发布长期拿到旧 JS（本地开发时就踩过这个坑）

> 改 `nginx.conf` / `opcache.ini` 后**必须重建容器**：这两个文件是 bind mount，Compose 只比对挂载路径不比对内容，容器里看到的还是旧 inode。`deploy.sh` 已统一用 `--force-recreate`。

---

## 三、首次上线（从旧版切到新版本）

切换的关键事实：新旧版本**共用同一个数据卷、同一个 `db.php`、同一个 SQLite 文件**，数据层已验证兼容（2671 条线上正文的渲染差分测试 + 25 项 HTTP 链路测试）。所以没有数据迁移，只有「换代码 + 重启」。

> 注意：新版没有旧版的插件体系（SEO、百度推送、markdown_textarea 等），切换后这些功能会消失。旧版的 `cron` 容器会被 `--remove-orphans` 清掉——同一份 SQLite 不允许两个应用同时写。

```bash
cd bbs1org/docker

# 0. 本地跑通
./dev.sh up && ./dev.sh smoke

# 1. 看两边差在哪（首次切换时必然有 7 项差异，都属于「新版还没上线」导致的，见下表）
./env-check.sh

# 2. 选个低峰时段，发布（镜像不一致时脚本会让两边各自 docker pull 对齐，不从本地上传）
./deploy.sh release
```

首次切换前 `env-check.sh` 会报 7 项差异，逐条对应关系如下（发布后应全部消失）：

| 差异项 | 原因 | 消除方式 |
| --- | --- | --- |
| `serversideup/php:8.5-fpm`、PHP 版本 8.5.10 vs 8.5.9 | 线上镜像 8 月拉取后仓库有补丁更新 | 脚本让两边各拉一次同一个 tag（`docker compose pull`） |
| `nginx:alpine` | 同上 | 同上 |
| 容器内/部署目录的 `nginx.conf` | 线上还是旧版应用的规则，没有 `/vendor`、`/templates` 拦截 | 发布时同步新配置 |
| `composer.lock`、`vendor` | 线上旧版应用没有 Composer 依赖 | 发布时带上 `vendor/` |

推进真正写入的是发布脚本，不是这个校验；发布完成后必须再跑一次，那时应当是「一致」。

> 服务器拉不到 Docker 仓库时（内网、镜像站不可用），加 `--sync-images`：脚本改用
> `docker save | docker load` 把本地镜像推过去，以本地这份为准，代价是走上传带宽。

`release` 依次做这些事，任何一步失败都会中止并保留现场：

| 步骤 | 动作 |
| --- | --- |
| 0 | 前置检查：ssh、目标机 docker、本地冒烟、工作区改动提示 |
| 1 | 镜像对齐：两边镜像 ID 不同就让各自 `docker pull` 同一个 tag（`--sync-images` 时改为把本地镜像推过去） |
| 2 | 备份：`VACUUM INTO` 快照线上库到 `backups/db-<时间戳>.sqlite`；`cp -a` 现有 `bbs1org_docker` 到 `backups/<时间戳>/` |
| 3 | 上传：`tar` 打包工作树（含 `vendor/`，服务器不必联外网）解到 `releases/<时间戳>/` |
| 4 | 同步配置：`docker-compose.yml`、`nginx.conf`、`opcache.ini` 覆盖到 `bbs1org_docker/`；`.env` 的 `BBS1ORG_PATH` 指向新版本 |
| 5 | 重建：`docker compose up -d --remove-orphans --force-recreate`（顺带清 opcache、清掉旧版 cron 容器） |
| 6 | 健康检查：首页/登录页/静态资源 200，`/app/data`、`/vendor`、`/templates` 403 |

发布后人工确认：

```bash
./deploy.sh status          # 容器状态 + 最近日志
# 浏览器：登录、打开一个老帖子（正文/Markdown 渲染）、发一条回复
./env-check.sh              # 现在应当是「一致」
```

### 回滚

```bash
./deploy.sh rollback                                        # 只回滚代码与配置
./deploy.sh rollback --restore-db /opt/bbs1org-deploy/backups/db-<时间戳>.sqlite   # 连库一起回滚
```

回滚会还原上一次发布前的整个 `bbs1org_docker`（含 `.env` 的源码指针），因此旧版应用立刻可用；
数据库恢复会把换下来的库留一份 `*.pre-rollback` 在卷里，并在恢复前先删掉 `-wal`/`-shm`。

---

## 四、日常更新

```bash
cd bbs1org/docker
./dev.sh up                 # 本地改完先在开发栈验证
./dev.sh smoke
./deploy.sh release         # 镜像不一致时脚本自动让两边各拉一次，不需要人工介入
```

几个必须知道的点：

- **改 PHP 代码必须重启 php 容器**：线上 `opcache.validate_timestamps=0`，不重启就一直跑旧字节码。`deploy.sh` 用 `--force-recreate` 覆盖了这一点，手工发布时别忘。
- **模板改动不需要清缓存**：应用给 Twig 设了 `auto_reload=true`，模板变更会自己重编译。
- **前端资源**：nginx 缓存 1 小时，改 `app/assets` 下的文件建议顺手把 `app/version.php` 的版本号 +1，让页面里的 `?v=` 变化。
- **给数据库加表**：`Bootstrap::run()` 只在 `app/data/install.lock` 不存在时执行，所以新增表不会自动建。做法是把标记移开再访问一次首页——建表语句是 `CREATE TABLE IF NOT EXISTS` 语义，检测到已有数据时只补表、只补写标记，不会覆盖数据：
  ```bash
  cd /opt/bbs1org-deploy/bbs1org_docker
  docker compose exec php rm -f /var/www/html/app/data/install.lock
  curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8080/   # 触发补表，标记会被写回
  docker compose exec php cat /var/www/html/app/data/install.lock
  ```

---

## 五、备份与恢复

```bash
./deploy.sh db-backup        # 在线快照，SQLite 的 VACUUM INTO，不锁库不中断服务
```

产物在 `/opt/bbs1org-deploy/backups/db-<时间戳>.sqlite`（只有几 M，可以长期留）。

建议在服务器上挂个每日任务（发布脚本之外的第二道保险）：

```cron
# crontab -e
30 4 * * * cd /opt/bbs1org-deploy/bbs1org_docker && docker compose exec -T php php /var/www/html/docker/db-backup.php /var/www/html/app/data/daily.sqlite && docker compose cp php:/var/www/html/app/data/daily.sqlite /opt/bbs1org-deploy/backups/db-daily-$(date +\%F).sqlite && docker compose exec -T php rm -f /var/www/html/app/data/daily.sqlite
```

整卷备份（含上传/头像等附件）：

```bash
docker run --rm -v bbs1org_data:/data -v /opt/bbs1org-deploy/backups:/backup nginx:alpine \
  tar czf /backup/bbs1org_data-$(date +%F).tar.gz -C /data .
```

恢复：优先用上面的 `rollback --restore-db`；手工恢复就用同款一次性容器把文件放回卷里并 `chown 33:33`。

---

## 六、环境一致性校验

```bash
cd bbs1org/docker
./env-check.sh                # 本地开发栈 ↔ 线上
./env-check.sh --local-only   # 只看本地，不上服务器
```

它会比对：镜像 ID、PHP 版本、18 项 ini、扩展清单（含应用依赖的 12 个必需扩展）、容器内 `nginx.conf`/`opcache.ini`
与仓库文件是否同一份、`composer.lock`、应用版本、`vendor` 是否就位、`app/data` 可写。

判定：全部必须一致，只有 `opcache.validate_timestamps` 允许不同（本地 1 = 改代码立即生效，线上 0 = 靠重启生效）。
有差异时脚本会打印两条对齐命令：本地 `docker compose pull`、线上 `docker compose pull`；直接跑一次 `./deploy.sh release` 也会自动对齐。

---

## 七、排障

| 现象 | 排查 |
| --- | --- |
| 502 | `docker compose ps` 看 php 是否 healthy；nginx 上游固定是服务名 `php:9000` |
| 500 | `docker compose logs --tail=50 php`；应用把致命错误也写进 `app/data/debug.log` |
| 页面白屏/样式旧 | 静态资源缓存 1 小时；强刷或等一会儿，必要时 `app/version.php` 加版本号 |
| 改了 PHP 不生效 | `validate_timestamps=0`，重启 php 容器：`docker compose restart php`（配置类改动要 `up -d --force-recreate`） |
| 改了 nginx / opcache 配置不生效 | bind mount 按 inode，必须 `up -d --force-recreate nginx php` |
| 首页提示初始化失败 | `app/data` 权限：部署脚本里的 `permissions` 服务负责 chown 33:33；手工检查 `docker compose logs permissions` |
| 磁盘 | `df -h`、`docker system df`；`docker image prune` 清旧镜像 |

### 建议顺手收紧的地方

- 线上 nginx 端口现在监听 `0.0.0.0:8080`，宿主机 nginx 反代走的是 `127.0.0.1:8080`。把 `docker-compose.yml` 里改成
  `"127.0.0.1:${HTTP_PORT:-8080}:80"` 就只对本机开放。注意云厂商安全组也要一并只放 80/443。
- `bbs1org_docker/.env` 与备份目录含口令与数据库快照，权限保持 `600`/`700`。

---

## 八、证书与宿主 nginx

acme.sh 已装好并有续期任务（`17 4,10,16,22 * * * /root/.acme.sh/acme.sh --cron`），证书在 `/etc/nginx/ssl/cncttc.com/`。
改完宿主 nginx 配置：`nginx -t && systemctl reload nginx`。
发布不需要动宿主 nginx：容器始终监听 8080，域名与证书在宿主层。
