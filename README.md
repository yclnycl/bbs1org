# bbs1org

一个轻量的 PHP 论坛。页面渲染使用 Twig 模板（templates/ 目录），数据访问使用 Eloquent ORM（模型在 app/optional/Model/），数据库为 SQLite。适合社区站点、低成本部署和二次开发。

## 特点

- 页面渲染基于 Twig 3 模板（templates/ 目录，自动转义、模板缓存），路由函数只负责准备数据
- 数据访问统一走 Eloquent ORM，模型与关联集中在 `app/optional/Model/`，业务代码里没有手写 SQL
- 使用 SQLite 数据库，备份即复制文件
- 页面地址按页面区分（`/login`、`/topic/12`），不使用查询参数选页
- 包含首页、版块、主题、回帖、通知、个人主页和后台管理等完整论坛功能
- 支持用户组、版块权限、站点设置、注册控制、发帖限制
- 帖子正文按 Markdown 渲染（league/commonmark：标题、表格、列表、引用、代码块、删除线、裸链接自动识别，`@用户名` 与 `@用户名 #楼层` 自动变成提及链接），发帖与回帖使用 EasyMDE 编辑器（工具栏 + 预览，预览结果由后端同一渲染函数生成）
- 无安装向导，首次访问按环境变量自动初始化，适配 Docker 一键部署
- 站点设置、版块和用户组在请求内按需加载，数据库结构简单
- 支持 AJAX 交互和响应式布局，兼顾 PC 与移动端使用体验

## 环境

源码部署最低要求：

| 软件 | 版本要求 | 说明 |
| --- | --- | --- |
| PHP | 8.2 及以上 | Eloquent ORM 要求 PHP 8.2；当前 Docker 镜像为 8.2 |
| Composer | 2.x | 安装依赖（Twig + Eloquent）：`composer install` |
| PDO | 随 PHP 安装 | 启用 `pdo_sqlite` 扩展 |
| SQLite | SQLite 3 | 通过 `pdo_sqlite` 使用 |
| Web 服务 | Nginx 或 Apache | Apache 需启用 PHP-FPM/模块及 URL 重写 |

使用 Docker 部署还需要 Docker Engine 24 及以上和 Docker Compose v2。运行镜像为 `serversideup/php:8.5-fpm` 与 `nginx:alpine`，本地开发与线上生产用同一套编排，环境一致性由 `docker/env-check.sh` 校验；源码运行时以 PHP `8.2+` 为最低要求。

## Docker 部署（推荐）

服务器需先安装 Docker Engine 24+ 和 Docker Compose v2。Docker 可使用以下命令安装：

```bash
curl -fsSL https://get.docker.com -o install-docker.sh
sudo sh install-docker.sh
```

在本仓库目录执行：

```bash
cd docker
cp .env.example .env
# 按需编辑 .env：源码路径、端口、站点名、管理员账号
docker compose up -d
```

容器启动后访问默认 8080 端口：

```text
http://服务器地址:8080
```

无需安装向导：首次访问时程序会按环境变量自动完成初始化（建表、默认版块、管理员账号），数据保存在 `bbs1org_data` 数据卷中。管理员密码来自 `.env` 中的 `ADMIN_PASSWORD`；留空时自动生成随机密码，保存在数据卷 `app/data/admin-password.txt` 并打印到容器日志（`docker compose logs php`）。

数据库固定使用 `SQLite`，数据文件保存在 `bbs1org_data` 数据卷中。

常用操作（均在 `docker` 目录执行）：

```bash
docker compose ps                 # 查看状态
docker compose logs -f php        # 查看日志
docker compose restart php        # 重启 php（改了 PHP 代码或 ini 之后）
docker compose up -d --force-recreate   # 改了 nginx.conf / opcache.ini 之后（bind mount 按 inode，必须重建）
docker compose down               # 停止并保留数据卷
```

- 本地开发流程（含用线上数据联调）：[docs/DEV-LOCAL.md](docs/DEV-LOCAL.md)
- 生产发布、回滚、备份与环境一致性校验：[docs/DEPLOY-PRODUCTION.md](docs/DEPLOY-PRODUCTION.md)

## 虚拟机部署（已有 Nginx/Apache + PHP 环境）

环境要求：

- PHP 8.2+（开发与线上统一在 8.5；版本差异会用环境变量、扩展、ini 逐项比出来，见 `docker/env-check.sh`）
- 启用 PDO 的 `pdo_sqlite` 扩展（SQLite 3）
- 项目根目录执行 `composer install` 生成 `vendor/`（Twig 模板引擎 + Eloquent ORM）
- Web 服务运行用户对 `app/data/` 有写入权限；使用 SQLite 时数据库文件也保存在该目录
- **必须禁止 Web 直接访问 `app/data/`、`app/optional/`、`vendor/`、`templates/`**，这些目录包含数据库、配置、依赖与模板；部署完成后请确认访问 `https://你的域名/app/data/db.php` 返回 `403` 或 `404`。`docker/nginx.conf` 是线上在用的规则，可作为站点配置的参考

没有安装向导。程序在首次访问时按环境变量自动初始化；也可在 `app/data/db.php` 中手工写死数据库配置（存在时优先于环境变量）。可用的环境变量：

| 变量 | 默认值 | 说明 |
| --- | --- | --- |
| `SITE_NAME` | `FORUM` | 站点名（首次初始化生效） |
| `FORUM_NAME` | `默认版块` | 默认版块名 |
| `ADMIN_USERNAME` | `admin` | 管理员用户名 |
| `ADMIN_EMAIL` | `admin@example.com` | 管理员邮箱 |
| `ADMIN_PASSWORD` | 随机生成 | 留空时生成随机密码，保存到 `app/data/admin-password.txt` |

Nginx 站点配置应包含（与 `docker/nginx.conf` 一致的两组）：

```nginx
location ~ ^/app/(?:data|cache|plugins|optional)(?:/|$) {
    deny all;
}
# 依赖与模板里有可执行 PHP 与模板源码，不能让它们落到 php-fpm 或直接下载
location ~ ^/(?:vendor|templates|docs)(?:/|$) {
    deny all;
}
location ~ ^/app/.*\.php$ {
    deny all;
}
```

Apache 可在网站根目录的 `.htaccess` 中加入：

```apache
RewriteEngine On
RewriteRule ^app/(data|cache|plugins|optional)(/|$) - [F,L]
RewriteRule ^(vendor|templates|docs)(/|$) - [F,L]
RewriteRule ^app/.*\.php$ - [F,L]
```

页面地址形如 `/`、`/login`、`/topic/12`、`/admin?tab=groups`：路由名是路径首段，纯数字的第二段是 id，其余参数留在查询串上。**这要求 Web 服务把不存在的路径回落到 `index.php`**，否则所有内页都会 404。

Nginx 站点配置必须包含：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

Apache 必须启用 `mod_rewrite` 且允许 `.htaccess`（`AllowOverride FileInfo` 或 `All`），在网站根目录 `.htaccess` 中：

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L,QSA]
```

回落到 `index.php` 的前提是路径没有对应的真实文件，所以**新增路由名时不要与网站根目录下的真实文件或目录重名**（例如 `app`、`docker`、`vendor`、`templates`），否则请求会被 Web 服务直接处理，不会进入程序。

旧式的 `index.php?a=topic&id=12` 仍然可用（仅作为兼容，程序生成的都是路径形式）；这些地址的 `<link rel="canonical">` 指向对应的路径形式。

- 下载源码后解压，将目录内全部文件上传到网站目录，确保 `index.php` 位于网站根目录。
- 按上面的环境变量表设置 PHP 进程的环境变量（不设置则使用 SQLite 与默认管理员账号，随机密码见 `app/data/admin-password.txt`）。
- 访问站点域名，首次访问自动完成初始化。

## 面板部署

宝塔和 1Panel 可在面板终端执行“Docker 部署”中的克隆、配置和启动命令。

## 帖子正文与编辑器

正文渲染和编辑器都直接用开源组件，不自研解析器：

- 渲染：`league/commonmark`（`app/optional/Markdown.php`）。启用官方 CommonMark 核心 + GFM 表格 / 删除线 / 裸链接自动识别扩展，提及用官方 Mention 扩展实现，正文里的原始 HTML 一律转义输出、`javascript:` 之类的不安全链接不生成 `a` 标签。换行按“回车即换行”渲染成 `<br>`，与论坛原有书写习惯一致
- 编辑器：EasyMDE（`app/assets/vendor/easymde/`，MIT，版本见随包 LICENSE），自带工具栏、快捷键、并排预览与全屏；图标以内联 SVG 提供，不依赖 Font Awesome CDN，也不请求任何外部域名
- 预览：工具栏的“预览”把正文 POST 到 `/preview`，由后端调用同一个 `markdown_html()` 渲染后返回，所以预览结果与发出后的显示完全一致
- 静态资源按需加载：只有含编辑器的页面（发帖、编辑主题/回复、可回帖的主题页）才引入 EasyMDE 的 CSS/JS

`{{ body|markdown(topic_id) }}` 过滤器是正文渲染入口；模板里第二个参数是主题 id，楼层提及要它才能生成 `/topic/{id}?floor={n}` 链接。

## 人机验证（Cloudflare Turnstile）

登录、注册、发主题、回帖四个入口接入了 Cloudflare Turnstile。前端只负责拿 token，判定一律在后端：

- 前端：`templates/macros/form.html.twig` 的 `f.turnstile(action)` 输出组件容器，`f.turnstile_script()` 按页面引入 `api.js`。组件放在 `<form>` 内，Turnstile 会往容器里插入 `cf-turnstile-response` 隐藏域随表单提交；由 `app/assets/index.js` 显式渲染（`api.js?render=explicit`），并保留 widget id —— token 是一次性的，每次提交后按 id 重置，否则同一页面的第二次提交必然失败
- 后端：`index.php` 的 `turnstile_verify($action)` 在受保护的处理逻辑之前调用，与 `check()` 里的 CSRF 校验同一层。它要求 siteverify 返回 `success === true`，且 `action` 与页面一致、`hostname` 命中白名单；任何一项不通过都返回 403（表单走消息页、AJAX 走 JSON），受保护的处理逻辑不会执行。判定失败的原因会写进 `app/data/debug.log`（`reason=action:...`、`reason=hostname:...`、`reason=siteverify:invalid-input-secret` 等）
- 只在需要的页面加载：不含上述表单的页面不引入 `api.js`，也不输出组件

三个环境变量**同时有值**才启用；任一留空即整体关闭（页面不输出组件、后端也不校验），未配置的环境与接入前完全一致：

| 变量 | 说明 |
| --- | --- |
| `TURNSTILE_SITE_KEY` | 组件 site key，公开值，会渲染进页面 |
| `TURNSTILE_SECRET` | 后端 secret，只用于调 siteverify |
| `TURNSTILE_HOSTNAMES` | siteverify 返回的 `hostname` 白名单，逗号分隔 |

```bash
# docker/.env
TURNSTILE_SITE_KEY=0x4AAAAAAFCcUMBGK8Qf0NC7
TURNSTILE_SECRET=<密钥>
TURNSTILE_HOSTNAMES=localhost,127.0.0.1     # 线上填 cncttc.com,www.cncttc.com
```

- 只配一半是危险状态（以为开着其实没开），程序会往调试日志写一条提醒
- 白名单只写本站自己的域名。线上那份**不能**包含 `localhost` / `127.0.0.1`，否则别的机器可以拿本地的 token 来通过校验
- 想本地联调就用同一对 site key / secret：把 `localhost`、`127.0.0.1` 加进 Cloudflare 上该组件的域名列表即可。Cloudflare 的测试 site key（`1x00000000000000000000AA` 等）产生的固定 token 不带 `action`，过不了 `action` 校验，只适合验证组件渲染

## MCP 接入（AI 客户端）

`/mcp` 提供一套带鉴权与审计的 MCP（Model Context Protocol）服务，传输为 Streamable HTTP 的无会话实现（单端点 JSON-RPC，不需要 SSE）。**登录与鉴权分离**：网页登录仍是 Cookie 会话；MCP 只认 `Authorization: Bearer` 令牌，而令牌必须先在网页端登录后才能创建。

### 获取令牌

1. 正常登录论坛，进入 **个人设置 → API 令牌（MCP）**
2. 填一个便于识别的名称（会出现在审计日志里），点「创建令牌」
3. 明文令牌（`bbs1_` 开头）**只在创建时显示一次**，请立即保存；库里只存 SHA-256 摘要，丢失只能吊销重建
4. 不用时在列表里点「吊销」，立即失效

### 客户端配置

```json
{
  "mcpServers": {
    "forum": {
      "url": "https://<你的域名>/mcp",
      "headers": { "Authorization": "Bearer bbs1_xxxxxxxx..." }
    }
  }
}
```

内置工具：`site_info`（站点与版块权限概况）、`list_topics`（主题列表，支持版块/用户/标题关键词筛选与分页）、`get_topic`（正文 + 分页回帖，不累计浏览量）、`create_topic` / `create_reply`（发主题/回帖）、`my_info`（当前账号与令牌信息）。

### 权限与安全

- 令牌的权限与所属账号**完全一致**：禁言、版块用户组限制（浏览/发帖/回帖）、发帖间隔、站点关闭状态全部照常生效
- 令牌只存摘要，服务端不落明文；`last_used_at` 按分钟节流记录，可在个人设置里看到是否被盗用
- **每次调用（含鉴权失败与被拒绝）都写入 `app_api_logs` 审计表**：用户、令牌、工具、参数与结果摘要（截断保存）、来源 IP、耗时、状态（成功/被拒绝/未授权/错误）。后台「MCP日志」标签页可按状态/工具/用户筛选查看，管理员可一键清空
- 令牌创建与吊销本身也记入审计日志
- MCP 端点不受 CSRF 双提交约束（不依赖 Cookie 鉴权），但同样不豁免任何业务权限检查

## 数据层

数据访问统一通过 Eloquent 完成，业务代码里不再有手写 SQL：

- 模型位于 `app/optional/Model/`，一张表一个模型；作者、版块、回帖、通知收发人之类的关联都定义在模型上，列表页用 `with()` 预加载，避免逐行查库
- `app/optional/Db/Database.php` 把 Eloquent 接在应用原有的 PDO 上。复用同一个连接意味着 `WAL`、`busy_timeout=5000`、`foreign_keys=ON` 这些 PRAGMA 和异常模式全部保持不变，新旧代码也共享同一份事务状态
- `app/optional/Db/SqlitePdo.php` 处理两件 SQLite 特有的事：把事务改成 `BEGIN IMMEDIATE`（默认的 deferred 事务在“先读后写”时会撞上锁升级失败，而 `busy_timeout` 对这种情况无效，因为那是死锁而非等待），并让 PDO 的事务记账与裸 SQL 保持一致，否则 `commit()` 会抛“没有活动事务”、`rollBack()` 会静默失效
- 表结构（`CREATE TABLE` / `CREATE INDEX`）由 `app/optional/Bootstrap.php` 的 `schema()` 统一维护，是唯一的建表来源；首次访问时按需建表，并按环境变量播种默认用户组与管理员
- 时间戳列存的是 INTEGER 的 unix 秒：模型上关闭了 `$timestamps`，也不要给它们加日期 cast——日期 cast 在写入时会格式化成 `Y-m-d H:i:s`，SQLite 对 INTEGER 列存不进数字就按 TEXT 存，新旧行混在一起后 `ORDER BY` 会错乱

## 数据备份

SQLite 数据目录 `app/data/` 需要定期备份。
