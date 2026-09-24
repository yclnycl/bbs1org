# AGENTS.md

面向编码代理的日常改码指南。完整文档以 [README.md](README.md)、[docs/DEV-LOCAL.md](docs/DEV-LOCAL.md)、[docs/DEPLOY-PRODUCTION.md](docs/DEPLOY-PRODUCTION.md) 为准，本文件只写改代码时必须知道的事。

## 项目概览

轻量 PHP 论坛（bbs1org，线上域名 cncttc.com）：无框架单入口 `index.php`，页面渲染用 Twig 3 模板，数据访问用 Eloquent ORM（illuminate/database 12），正文渲染用 league/commonmark，数据库固定 SQLite。无构建步骤、无自动化测试框架。

语法与依赖的最低版本是 PHP 8.2（composer platform 钉在 8.2.0），本地与线上实际跑的是 8.5（`serversideup/php:8.5-fpm`）——不要用 8.3+ 才有的语法。

## 常用命令

所有日常命令在 `docker/` 目录下执行；Windows 上用 Git Bash（脚本已处理路径转换）：

```bash
cd docker
cp .env.example .env        # 首次：站点名、管理员账号、端口
./dev.sh up                 # 起本地栈，访问 http://127.0.0.1:8090
./dev.sh smoke              # 冒烟：首页/登录/静态资源 200，敏感路径 403
./dev.sh logs php           # 看日志（应用错误另写进卷内 app/data/debug.log）
./dev.sh reset              # 停栈删卷，下次访问重新初始化
./dev.sh composer install   # Composer 一律在容器里跑，不在 Windows 宿主机跑
./env-check.sh              # 本地 ↔ 线上逐项比对，发布前的硬要求
```

- dev 栈默认开了 opcache 时间戳校验，改 PHP 代码立即生效；`./dev.sh up --strict`（生产同构）下改代码需 `./dev.sh restart php`。
- 模板改动不需要清缓存（Twig `auto_reload` 已开）。
- 管理员账号在 `docker/.env`；`ADMIN_PASSWORD` 留空则随机生成，写在卷内 `app/data/admin-password.txt` 并打印到 php 容器日志。
- 改动的最低验证标准：`./dev.sh smoke` 通过，并在 http://127.0.0.1:8090 手工过一遍受影响的页面。

## 代码结构

| 位置 | 职责 |
| --- | --- |
| `index.php` | 单一入口：autoloader、公共函数库、路由分发（`core_routes()`）、各页面函数（`*_page` / `*_route`）、`render_page()`/`twig()` 渲染出口 |
| `app/optional/Bootstrap.php` | 首次访问自动初始化；`schema()` 是唯一的建表建索引来源 |
| `app/optional/Admin.php` | 后台路由 `Admin::route()`（`/admin?tab=…`） |
| `app/optional/Markdown.php` | 正文渲染唯一入口 `Markdown::html()` |
| `app/optional/Search.php`、`DebugLog.php` | 搜索页与调试日志 |
| `app/optional/Db/` | `Database` 把 Eloquent 接到应用原有 PDO（一个连接、一份事务状态）；`SqlitePdo` 处理 `BEGIN IMMEDIATE` 等 SQLite 特有行为 |
| `app/optional/Model/` | Eloquent 模型，一表一模型，作者/版块等关联定义在模型上 |
| `templates/` | Twig 模板：`layout.html.twig`、`nav`、`macros/`、`partials/`、`admin/` |
| `app/assets/` | 前端 CSS/JS 与内置 EasyMDE（`app/assets/vendor/easymde/`），无构建 |
| `docker/` | 本地与生产同一套编排：`dev.sh`、`deploy.sh`、`env-check.sh`、`nginx.conf`、`opcache.ini` |

SQLite 库文件在 Docker 卷的 `app/data/`（不入库）；卷内 `app/data/db.php` 存在时优先于环境变量。线上数据的只读快照与导入见 [docs/DEV-LOCAL.md](docs/DEV-LOCAL.md)「用线上数据联调」。

## 硬性约定

1. **HTML 全部在 Twig 模板里**。路由函数只准备数据并交给 `render_page()`，不在 PHP 里拼接 HTML；PHP 侧少数输出 HTML 的 helper 一律经 `h()` 转义。
2. **业务代码不写 SQL**。数据访问统一走 `app/optional/Model/` 的模型与关联，列表页用 `with()` 预加载避免逐行查库。`CREATE TABLE` / `CREATE INDEX` 只允许出现在 `Bootstrap::schema()`。
3. **时间戳列是 INTEGER 的 unix 秒**，由调用方显式写值。不要打开 `$timestamps`，不要加日期 cast——cast 写入会格式化成 `Y-m-d H:i:s`，SQLite 对 INTEGER 列存不下就按 TEXT 存，新旧行混存后 `ORDER BY` 错乱（详见 `app/optional/Model/Base.php` 注释）。
4. **加表的生效方式**：`app/data/install.lock` 存在时 `Bootstrap::run()` 直接返回。新增表要删掉卷内 `install.lock` 再访问一次首页（建表是 `CREATE TABLE IF NOT EXISTS` 语义，只补缺不覆盖数据，标记会写回）；本地也可以直接 `./dev.sh reset`。没有列变更/数据迁移机制，改已有列结构需手写 SQL 并自行处理存量数据。
5. **新路由**注册在 `core_routes()`（index.php）或 `Admin::route()`。URL 是路径形式（`/login`、`/topic/12`），路由名不能与根目录真实文件/目录重名（如 `app`、`docker`、`vendor`、`templates`、`docs`），否则请求被 Web 服务器直接接管，不进入程序。
6. **Markdown 渲染只有一条路径**：`Markdown::html()`，模板里用 `{{ body|markdown(topic_id) }}`（楼层提及生成 `/topic/{id}?floor=n` 链接需要 topic id）。编辑器预览 POST `/preview` 调的是同一个函数——不要另写渲染逻辑，保证预览与发布后一致。
7. **改 `app/assets/` 下的文件要顺手把 `app/version.php` 的版本号 +1**：页面里静态资源带 `?v=`，nginx 缓存 1 小时，不改版本号用户可能长期拿到旧 JS/CSS。
8. **新增敏感目录要同步 Web 拦截规则**：`docker/nginx.conf` 与 README 里的 Apache `.htaccess` 段一起改。当前被拦：`app/{data,cache,plugins,optional}`、`app/**/*.php`、`vendor`、`templates`、`docs`。
9. **密钥（密码、API key、token、证书私钥）一律不写入代码、模板、文档或 commit**。运行时通过 Docker 环境变量注入容器：真实值只写在 `docker/.env`（已 gitignore），`.env.example` 只放非敏感占位。新增凭据的做法：compose 里补环境变量映射 → `.env.example` 加空占位（默认值留空）→ 代码里 `getenv()` 读取，留空时由程序生成随机值并落到数据卷（现有先例：`ADMIN_PASSWORD` 留空时随机生成，写入卷内 `app/data/admin-password.txt`）。数据库配置的 `app/data/db.php` 同理属于数据卷而非代码库。
10. **注释、commit message、文档都写中文**；commit 用 Conventional Commits 风格（`feat:` / `fix:` / `refactor:` / `style:` / `chore:` + 中文描述）。

## 开发

做完一个需求，先在本地 `./dev.sh up` 起栈，然后运行自动化测试（`./dev.sh smoke`）和手工测试，确认没问题之后提交代码 保证commit message 规范，推送到远程仓库。

## 发布

只通过 `docker/deploy.sh release` 发布（备份 → 上传 → 重建 → 健康检查，脚本自带 `--force-recreate`）。发布前 `./dev.sh smoke` 与 `./env-check.sh` 必须全部通过。两条易踩的坑：

- 线上 opcache `validate_timestamps=0`，改 PHP 代码必须重启 php 容器才生效（`deploy.sh` 已处理，手工操作别忘）。
- `nginx.conf` / `opcache.ini` 是 bind mount，按 inode 生效，改完必须 `up -d --force-recreate`，`restart` 不够。

回滚与数据库备份恢复：`./deploy.sh rollback`（可加 `--restore-db`），细节见 [docs/DEPLOY-PRODUCTION.md](docs/DEPLOY-PRODUCTION.md)。
