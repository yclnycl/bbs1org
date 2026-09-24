# bbs1org

一个轻量的 PHP 论坛。页面渲染使用 Twig 模板（templates/ 目录），数据访问使用 Eloquent ORM（模型在 app/optional/Model/），数据库为 SQLite。适合社区站点、低成本部署和二次开发。

## 特点

- 页面渲染基于 Twig 3 模板（templates/ 目录，自动转义、模板缓存），路由函数只负责准备数据
- 数据访问统一走 Eloquent ORM，模型与关联集中在 `app/optional/Model/`，业务代码里没有手写 SQL
- 使用 SQLite 数据库，备份即复制文件
- 包含首页、版块、主题、回帖、通知、个人主页和后台管理等完整论坛功能
- 支持用户组、版块权限、站点设置、注册控制、发帖限制
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

使用 Docker 部署还需要 Docker Engine 24 及以上和 Docker Compose v2。Docker 镜像基于 PHP fpm-alpine 和 Nginx Alpine；源码运行时以 PHP `8.2+` 为最低要求。

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
# 按需编辑 .env：站点名、管理员账号、端口
docker compose up -d
```

容器启动后访问默认 8080 端口：

```text
http://服务器地址:8080
```

无需安装向导：首次访问时程序会按环境变量自动完成初始化（建表、默认版块、管理员账号），数据保存在 `forum_data` 数据卷中。管理员密码来自 `.env` 中的 `ADMIN_PASSWORD`；留空时自动生成随机密码，保存在数据卷 `app/data/admin-password.txt` 并打印到容器日志（`docker compose logs forum`）。

数据库固定使用 `SQLite`，数据文件保存在 `forum_data` 数据卷中。

常用操作（均在 `docker` 目录执行）：

```bash
docker compose ps                 # 查看状态
docker compose logs -f forum      # 查看日志
docker compose restart            # 重启
docker compose down               # 停止并保留数据卷
```

## 虚拟机部署（已有 Nginx/Apache + PHP 环境）

环境要求：

- PHP 8.1+
- 启用 PDO 的 `pdo_sqlite` 扩展（SQLite 3）
- 项目根目录执行 `composer install` 生成 `vendor/`（Twig 模板引擎 + Eloquent ORM）
- Web 服务运行用户对 `app/data/` 有写入权限；使用 SQLite 时数据库文件也保存在该目录
- **必须禁止 Web 直接访问 `app/data/`**，该目录包含数据库、配置和运行缓存；部署完成后请确认访问 `https://你的域名/app/data/` 返回 `403` 或 `404`

没有安装向导。程序在首次访问时按环境变量自动初始化；也可在 `app/data/db.php` 中手工写死数据库配置（存在时优先于环境变量）。可用的环境变量：

| 变量 | 默认值 | 说明 |
| --- | --- | --- |
| `SITE_NAME` | `FORUM` | 站点名（首次初始化生效） |
| `FORUM_NAME` | `默认版块` | 默认版块名 |
| `ADMIN_USERNAME` | `admin` | 管理员用户名 |
| `ADMIN_EMAIL` | `admin@example.com` | 管理员邮箱 |
| `ADMIN_PASSWORD` | 随机生成 | 留空时生成随机密码，保存到 `app/data/admin-password.txt` |

Nginx 站点配置应包含：

```nginx
location ~ ^/app/(?:data|cache|optional)(?:/|$) {
    deny all;
}
```

Apache 可在网站根目录的 `.htaccess` 中加入：

```apache
RewriteEngine On
RewriteRule ^app/(data|cache|optional)(/|$) - [F,L]
```

启用伪静态（Rewrite）时，Nginx 在站点配置中使用：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

Apache 在启用 `mod_rewrite` 且允许 `.htaccess`（`AllowOverride FileInfo` 或 `All`）后，在网站根目录 `.htaccess` 中追加：

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L,QSA]
```

- 下载源码后解压，将目录内全部文件上传到网站目录，确保 `index.php` 位于网站根目录。
- 按上面的环境变量表设置 PHP 进程的环境变量（不设置则使用 SQLite 与默认管理员账号，随机密码见 `app/data/admin-password.txt`）。
- 访问站点域名，首次访问自动完成初始化。

## 面板部署

宝塔和 1Panel 可在面板终端执行“Docker 部署”中的克隆、配置和启动命令。

## 数据层

数据访问统一通过 Eloquent 完成，业务代码里不再有手写 SQL：

- 模型位于 `app/optional/Model/`，一张表一个模型；作者、版块、回帖、通知收发人之类的关联都定义在模型上，列表页用 `with()` 预加载，避免逐行查库
- `app/optional/Db/Database.php` 把 Eloquent 接在应用原有的 PDO 上。复用同一个连接意味着 `WAL`、`busy_timeout=5000`、`foreign_keys=ON` 这些 PRAGMA 和异常模式全部保持不变，新旧代码也共享同一份事务状态
- `app/optional/Db/SqlitePdo.php` 处理两件 SQLite 特有的事：把事务改成 `BEGIN IMMEDIATE`（默认的 deferred 事务在“先读后写”时会撞上锁升级失败，而 `busy_timeout` 对这种情况无效，因为那是死锁而非等待），并让 PDO 的事务记账与裸 SQL 保持一致，否则 `commit()` 会抛“没有活动事务”、`rollBack()` 会静默失效
- 表结构（`CREATE TABLE` / `CREATE INDEX`）由 `app/optional/Bootstrap.php` 的 `schema()` 统一维护，是唯一的建表来源；首次访问时按需建表，并按环境变量播种默认用户组与管理员
- 时间戳列存的是 INTEGER 的 unix 秒：模型上关闭了 `$timestamps`，也不要给它们加日期 cast——日期 cast 在写入时会格式化成 `Y-m-d H:i:s`，SQLite 对 INTEGER 列存不进数字就按 TEXT 存，新旧行混在一起后 `ORDER BY` 会错乱

## 数据备份

SQLite 数据目录 `app/data/` 需要定期备份。
