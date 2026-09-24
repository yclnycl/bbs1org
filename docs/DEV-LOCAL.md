# 本地开发环境

本地开发不是「另搭一套环境」，而是**同一套编排换一个源码目录**：镜像、`nginx.conf`、`opcache.ini`、
数据卷路径全部与线上相同。这样本地能跑通的东西，线上不会因为 PHP 版本、扩展、ini 限制而翻车。

```
docker/
  docker-compose.yml       # 唯一的运行时定义，本地与线上共用
  docker-compose.dev.yml   # 本地覆盖：只把 opcache 时间戳校验打开
  nginx.conf               # 本地与线上同一份
  opcache.ini              # 本地与线上同一份（生产原值）
  .env.example             # 复制成 .env 后按需改
  dev.sh                   # 本地日常命令
  env-check.sh             # 与线上逐项比对环境
  deploy.sh                # 生产发布（见 docs/DEPLOY-PRODUCTION.md）
  env-snapshot.php         # 环境快照，env-check.sh 的两边采集都靠它
  db-backup.php            # SQLite 在线快照，发布前备份用
```

## 前置

- Docker Desktop（Windows 需要 WSL2 后端）与 Docker Compose v2
- Windows 上用 Git Bash 执行脚本（脚本里已处理 Git Bash 的路径转换问题）
- 仓库里 `*.sh` 都以 LF 提交（见 `.gitattributes`），克隆后不用管换行

## 起停

```bash
cd bbs1org/docker
cp .env.example .env      # 首次：站点名、管理员账号、端口
./dev.sh up               # 起栈（后台）
```

- 访问 http://127.0.0.1:8090（端口取自 `.env` 的 `HTTP_PORT`）
- 管理员账号见 `.env`；`ADMIN_PASSWORD` 留空时随机生成，写在数据卷的 `app/data/admin-password.txt` 并打印到容器日志
- 首次访问自动初始化：建表、默认版块、管理员账号，写 `app/data/install.lock`

```bash
./dev.sh ps            # 容器状态
./dev.sh logs php      # 跟日志（php 与 nginx 都往 stderr 打）
./dev.sh restart php   # 重启 php（改 ini、改 compose 后用）
./dev.sh sh            # 进 php 容器
./dev.sh down          # 停止，保留数据卷
./dev.sh reset         # 停止并删卷，下次访问重新初始化
./dev.sh smoke         # 冒烟：首页/登录/静态资源 200，敏感路径 403
```

## 与生产的唯一差异

`docker-compose.dev.yml` 只做一件事：把 `opcache.validate_timestamps` 改成 1，改完 PHP 代码立刻生效。
生产是 0（靠重启生效，跑得更快）。这个文件由 `dev.sh` 从 `opcache.ini` 现场派生（只改那一行），
所以不存在两份 ini 各自演化的可能。

要复现「线上才有的问题」时，按生产原样启动：

```bash
./dev.sh up --strict    # 不加 dev 覆盖；改代码后需 ./dev.sh restart php
```

`./env-check.sh` 会把这一项标成「已知差异」，其它项有任何不同都会报错。

## 依赖（vendor）

Composer 依赖在**生产同款镜像里**安装，不在 Windows 上装，避免平台差异：

```bash
./dev.sh composer install            # 首次或有依赖变动时
./dev.sh composer require xxx/yyy    # 加依赖（记得提交 composer.json / composer.lock）
```

`vendor/` 不入库；发布时由 `deploy.sh` 一起打包上传，所以服务器不需要联外网。

## 用线上数据联调

`.live-db/` 下是线上库的只读快照（`VACUUM INTO` 导出，不要直接改）。
导入后本地就是真实数据：真实帖子、真实 Markdown、真实分页与权限。

```bash
./dev.sh up
./dev.sh import-db ../../.live-db/live-data/forum-114abe6f564aaf4b.sqlite forum-114abe6f564aaf4b.sqlite
```

导入做三件事：把文件写进数据卷、写 `app/data/db.php` 指向它、写 `install.lock`（有标记就不会重新初始化，
站点设置与用户都按快照来）。导入后的登录账号是**线上账号**，不是 `.env` 里的管理员。
导入不会删掉原来的库文件，想切回来把 `db.php` 改回去即可。

## 环境一致性校验

```bash
./env-check.sh                # 本地 ↔ 线上逐项比对
./env-check.sh --local-only   # 只看本地
```

比对内容与判定标准见 [DEPLOY-PRODUCTION.md](DEPLOY-PRODUCTION.md) 第六节。发布前跑一次是硬要求。

## 常见问题

| 现象 | 原因 / 处理 |
| --- | --- |
| 端口被占用起不来 | 改 `.env` 的 `HTTP_PORT`（本机 8080 常被别的服务占用，默认给了 8090） |
| 改 PHP 代码不生效 | 用 `--strict` 起栈时是生产配置（不校验时间戳），`./dev.sh restart php` |
| 改了 nginx.conf / opcache.ini 不生效 | bind mount 按 inode，必须重建：`docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --force-recreate nginx php` |
| 前端 JS/CSS 还是旧的 | 浏览器缓存；脚本带 `?v=<APP_VERSION>`，强刷（Ctrl+F5）或改 `app/version.php` |
| 上传大文件失败 | 线上限制 20M，本地同款 `opcache.ini`；要放宽就两边一起改，别只改本地 |
| 数据库锁/写不进去 | SQLite 放在命名卷里（不是 Windows 盘），别把 `app/data` 改成仓库目录挂载 |
| 旧实验残留的卷 | `docker volume ls | grep bbs` 里非 `bbs1org_*` 的（如早前的 `bbs_dev_data`、`forum_data`）可以删 |
| 首次访问提示初始化失败 | `./dev.sh logs php` 看权限或目录错误；`./dev.sh reset` 重来一次 |

## 目录约定（与应用相关）

| 容器内路径 | 宿主 | 说明 |
| --- | --- | --- |
| `/var/www/html` | `.env` 的 `BBS1ORG_PATH`（本地即仓库根） | 源码只读挂进 nginx、读写挂进 php |
| `/var/www/html/app/data` | 卷 `bbs1org_data` | SQLite 库、`db.php`、`install.lock`、Twig 缓存、debug.log |
| `/var/www/html/app/upload`、`app/avatars` | 卷 `bbs1org_upload`、`bbs1org_avatars` | 附件与头像（新版暂未使用，保留同名卷） |
| `/var/www/html/app/plugins` | 卷 `bbs1org_plugins` | 旧版插件目录，新版不使用 |
