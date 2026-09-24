<?php

declare(strict_types=1);

namespace app\optional;

use PDO;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

if (!defined('APP_ROOT')) exit;

define('INSTALL_DATA_DIR', DATA_DIR);
define('INSTALL_DB_CONFIG_FILE', DB_CONFIG_FILE);
define('INSTALL_DEFAULT_DB_FILE', DATA_DIR . '/forum.sqlite');

final class Setup
{
public const TERMS_VERSION = '2026-08-26';

public static function terms_html(string $context = 'install'): string
{
    $title = $context === 'migrate' ? '迁入前请阅读软件使用条款' : '安装前请阅读软件使用条款';
    return '<details class="software-terms" open><summary>' . h($title) . '（版本 ' . self::TERMS_VERSION . '）</summary><div class="software-terms-body"><p>点击“我已阅读并同意”或继续安装、迁移，即表示您已阅读、理解并接受本条款。</p><h3>1. 授权与范围</h3><p>本软件按随附开源许可证授权。您可在许可证允许范围内使用、复制、修改和再分发，并应保留版权、许可证和免责声明。</p><h3>2. 使用者责任</h3><p>您应确保部署环境、域名、服务器、数据库及上传内容拥有合法权利并遵守适用法律。您对网站、发布的信息和用户活动独立负责。</p><h3>3. 内容与账号</h3><p>您应审核用户内容、处理侵权或违法信息，并保护账号、口令、密钥和个人数据。软件作者不对您的内容或账号管理负责。</p><h3>4. 无担保</h3><p>在法律允许的最大范围内，本软件按“现状”和“可用”基础提供，不作适销性、特定用途适用性、持续可用、无错误或数据准确性的担保。</p><h3>5. 风险与备份</h3><p>安装、迁移可能导致中断、覆盖、丢失或不兼容。操作前必须完成可恢复备份并在测试环境验证，相关风险由您承担。</p><h3>6. 责任限制</h3><p>在法律允许的最大范围内，作者及贡献者不对间接、附带、特殊、惩罚性或后果性损害，以及利润、收入、商誉、业务机会或数据损失负责。累计责任不超过您实际支付的金额；免费获得时责任上限为零。</p><h3>7. 第三方组件与服务</h3><p>第三方组件受其各自许可证和条款约束。作者不控制其可用性、收费、隐私或安全性，您应自行评估风险。</p><h3>8. 更新与变更</h3><p>手工更新程序文件可能覆盖定制修改，数据库结构可能随版本调整。您应核对来源、版本和文件清单；作者不保证更新成功或兼容定制代码。条款可随版本更新。</p><h3>9. 安全与隐私</h3><p>您应自行配置访问控制、加密、日志和数据删除策略。软件不承诺满足特定行业合规要求，也不替代安全或法律评估。</p><h3>10. 适用法律</h3><p>争议解决适用软件运营者所在地的强制性法律规定；限制按当地法律允许的最大范围执行，其余条款继续有效。</p><p class="software-terms-note">本条款是通用模板，不构成法律意见。部署前请让专业人士审阅并补充主体、联系方式、适用法律和隐私政策。</p></div></details>';
}

public static function terms_consent(string $context = 'install', string $form_id = ''): string
{
    $form = $form_id === '' ? '' : ' form="' . h($form_id) . '"';
    $checked = $context === 'install' ? '' : ' checked';
    return self::terms_html($context) . '<label class="terms-consent"><input type="checkbox" name="terms_agree" value="1" required' . $checked . $form . '><span>我已完整阅读、理解并同意以上软件使用条款（版本 ' . self::TERMS_VERSION . '）。</span></label><input type="hidden" name="terms_version" value="' . h(self::TERMS_VERSION) . '"' . $form . '>';
}

public static function terms_accepted(array $values): bool
{
    return isset($values['terms_agree'])
        && (string)$values['terms_agree'] === '1'
        && hash_equals(self::TERMS_VERSION, (string)($values['terms_version'] ?? ''));
}

public static function debug_log_write(string $message, ?Throwable $e = null): void
{
    $exception_text = $e ? exception_detail($e) : '';
    $fingerprint = hash('sha256', $message . "\n" . $exception_text);
    $now = time();
    $throttle = @fopen(DEBUG_LOG_FILE . '.throttle', 'c+');
    if (is_resource($throttle) && @flock($throttle, LOCK_EX)) {
        rewind($throttle);
        $recent = json_decode((string)stream_get_contents($throttle), true);
        $recent = is_array($recent) ? $recent : [];
        foreach ($recent as $key => $timestamp) {
            if ($now - (int)$timestamp >= DEBUG_LOG_DEDUP_SECONDS) unset($recent[$key]);
        }
        if (isset($recent[$fingerprint])) {
            @flock($throttle, LOCK_UN);
            @fclose($throttle);
            return;
        }
        $recent[$fingerprint] = $now;
        @ftruncate($throttle, 0);
        rewind($throttle);
        @fwrite($throttle, json_encode($recent, JSON_UNESCAPED_SLASHES));
        @flock($throttle, LOCK_UN);
        @fclose($throttle);
    } elseif (is_resource($throttle)) {
        @fclose($throttle);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . trim($message);
    $ip = ip_addr();
    if ($ip !== '') $line .= "\nIP: " . $ip;
    $uri = trim((string)($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . (string)($_SERVER['REQUEST_URI'] ?? ''));
    if ($uri !== '') $line .= "\n" . $uri;
    if ($exception_text !== '') $line .= "\n" . $exception_text;
    $line .= "\n\n";
    @file_put_contents(DEBUG_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

public static function setup_html(string $title, string $body, bool $project_modal = false): never
{
    if (PHP_SAPI === 'cli') {
        $text = preg_replace('#<(?:style|script)\b[^>]*>.*?</(?:style|script)>#is', '', $body) ?? $body;
        $text = preg_replace('#<\s*/?\s*(?:br|p|div|section|article|header|footer|h[1-6]|li|tr|ul|ol)\b[^>]*>#i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+\n/u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = trim($text);
        $output = $text === '' || $text === $title || str_starts_with($text, $title . "\n") ? $text : $title . "\n\n" . $text;
        fwrite(STDOUT, trim($output) . "\n");
        exit;
    }
    $meta = '<meta name="viewport" content="width=device-width,initial-scale=1"><meta charset="utf-8">';
    $project_assets = $project_modal ? '<link rel="stylesheet" href="' . h(app_url('app/assets/index.css')) . '?v=' . h(APP_VERSION) . '">' : '';
    $project_scripts = $project_modal ? \project_modal_html() . '<script src="' . h(app_url('app/assets/index.js')) . '?v=' . h(APP_VERSION) . '" defer></script>' : '';
    echo '<!doctype html><html lang="zh-CN"><head>' . $meta . '<title>' . h($title) . '</title><link rel="icon" type="image/svg+xml" href="app/assets/index.svg">' . $project_assets . '<style>
    :root{--bg:#eef2f7;--panel:#fff;--line:#dfe6ee;--line2:#edf1f5;--text:#1f2937;--muted:#6b7280;--brand:#2563eb;--brand2:#1d4ed8;--ok:#059669;--warn:#b45309;--danger:#dc2626;--line-soft:var(--line2);--text-muted:var(--muted);--text-subtle:var(--muted);--text-disabled:var(--muted);--brand-hover:var(--brand2);--brand-soft:#eff6ff;--inverse:#111827;--inverse-text:#fff;--color-dark-rgb:31,41,55;--backdrop:rgba(var(--color-dark-rgb),.42);--shadow-medium:rgba(15,23,42,.18);--font-size-sm:12px;--font-size-md:14px;--font-size-lg:15px;--radius:10px;--radius-sm:8px;--focus-ring:rgba(37,99,235,.2)}
    *{box-sizing:border-box}body{margin:0;color:var(--text);font:14px/1.6 -apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif}
    a{color:var(--brand);text-decoration:none}a:hover{color:var(--brand2)}.wrap{max-width:1060px;margin:0 auto;padding:24px 16px 40px}
    .hero{display:grid;gap:8px;margin-bottom:18px}.hero h1{margin:0;font-size:28px;line-height:1.2}.hero p{margin:0;color:var(--muted)}
    .grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,.85fr);gap:16px;align-items:start}.card{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);box-shadow:0 10px 24px rgba(15,23,42,.05)}
    .card .hd{padding:16px 18px;border-bottom:1px solid var(--line2)}.card .hd h2{margin:0;font-size:16px}.card .bd{padding:16px 18px}
    .note{margin:10px 0;padding:12px 14px;border:1px solid #dbeafe;background:#eff6ff;color:#1e3a8a;border-radius:8px}.warn{border-color:#fde68a;background:#fffbeb;color:#92400e}.ok{border-color:#bbf7d0;background:#f0fdf4;color:#166534}
    .form{display:grid;gap:12px}.row{display:grid;gap:6px}.row label{font-size:12px;color:var(--muted)}.row small{color:var(--muted);font-size:11px;line-height:1.4}.row.compact{grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:10px}.row.compact .field{display:grid;gap:6px}.db-fields[hidden]{display:none}input[type=text],input[type=password],input[type=email],select,textarea{width:100%;border:1px solid #d6dbe3;border-radius:8px;padding:10px 12px;font:inherit;background:#fff;color:var(--text)}textarea{min-height:128px;resize:vertical}
    input:focus,select:focus,textarea:focus{outline:0;border-color:#93c5fd;box-shadow:0 0 0 3px rgba(59,130,246,.12)}.db-fields{display:grid;gap:12px;padding:12px;border:1px solid var(--line2);border-radius:8px;background:#fafcff}.checks{display:grid;gap:10px}.check,.terms-consent{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid var(--line2);border-radius:8px;background:#fafcff}.terms-consent{margin:12px 0}.check input,.terms-consent input{margin-top:3px}.software-terms{border:1px solid var(--line);border-radius:8px;background:#fff}.software-terms summary{padding:11px 12px;cursor:pointer;font-weight:600}.software-terms-body{max-height:150px;overflow:auto;padding:0 12px 12px;border-top:1px solid var(--line2);color:#374151;font-size:13px}.software-terms-body h3{margin:16px 0 4px;font-size:14px}.software-terms-body p{margin:6px 0}.software-terms-note{color:var(--muted)}
    .actions{display:flex;gap:10px;align-items:center;justify-content:flex-end}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:0 16px;border:0;border-radius:8px;background:var(--brand);color:#fff;cursor:pointer;font:inherit;font-weight:600}.btn:hover{background:var(--brand2);color:#fff}.btn.alt{background:#fff;color:#374151;border:1px solid #d1d5db}.btn.alt:hover{background:#f8fafc;color:#111;border-color:#cbd5e1}
    .list{margin:0;padding-left:18px;color:#374151}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;overflow-wrap:anywhere;word-break:break-word}.kv{display:grid;width:100%;min-width:0;grid-template-columns:120px minmax(0,1fr);gap:8px 12px;font-size:13px}.kv div{min-width:0;max-width:100%;overflow-wrap:anywhere;word-break:break-word}.kv div:nth-child(odd){color:var(--muted)}.admin-pass{padding:14px;border:1px solid #fecaca;background:#fff1f2;color:#991b1b;border-radius:8px;word-break:break-all}.footer{margin-top:16px;color:var(--muted);font-size:12px;text-align:center}
    @media (max-width:860px){.grid{grid-template-columns:1fr}.hero h1{font-size:24px}.wrap{padding:18px 12px 30px}}
    </style></head><body><main class="wrap">' . $body . '</main>' . $project_scripts . '</body></html>';
    exit;
}

public static function app_db_config_source(array $config): string
{
    $saved = $config;
    unset($saved['path']);
    if (($saved['driver'] ?? '') === 'sqlite') $saved['db_file'] = $saved['database'];
    return "<?php\nif (!defined('APP_ROOT')) exit;\nreturn " . var_export($saved, true) . ";\n";
}

public static function app_db_schema(string $driver): array
{
    $types = app_db_types($driver);
    $id = $types['id'];
    $uint = $types['uint'];
    $short = $types['string'];
    $key = $types['key'];
    $long = $types['text'];
    $tables = [
        'app_groups' => "CREATE TABLE app_groups(id $id,name $key NOT NULL UNIQUE,allow_manage INTEGER NOT NULL DEFAULT 0,allow_admin INTEGER NOT NULL DEFAULT 0)",
        'app_users' => "CREATE TABLE app_users(id $id,username $key NOT NULL UNIQUE,password $short NOT NULL,email $key NOT NULL DEFAULT '',bio $long NOT NULL,avatar_style $short NOT NULL DEFAULT '',avatar_seed $short NOT NULL DEFAULT '',group_id $uint NOT NULL DEFAULT 2,points INTEGER NOT NULL DEFAULT 0,is_banned INTEGER NOT NULL DEFAULT 0,is_muted INTEGER NOT NULL DEFAULT 0,unread_notifications $uint NOT NULL DEFAULT 0,last_post_at $uint NOT NULL DEFAULT 0,created_at $uint NOT NULL)",
        'app_notifications' => "CREATE TABLE app_notifications(id $id,recipient_id $uint NOT NULL,sender_id $uint DEFAULT NULL,kind $short NOT NULL DEFAULT 'direct',content $long NOT NULL,topic_id $uint DEFAULT NULL,reply_id $uint DEFAULT NULL,read_at $uint NOT NULL DEFAULT 0,created_at $uint NOT NULL)",
        'app_forums' => "CREATE TABLE app_forums(id $id,name $short NOT NULL,description $long NOT NULL,sort $uint NOT NULL DEFAULT 0,allow_view_groups $short NOT NULL DEFAULT '',allow_post_groups $short NOT NULL DEFAULT '',allow_reply_groups $short NOT NULL DEFAULT '')",
        'app_topics' => "CREATE TABLE app_topics(id $id,forum_id $uint NOT NULL,user_id $uint NOT NULL,title $short NOT NULL,body $long NOT NULL,highlight_style $short NOT NULL DEFAULT '',reply_order INTEGER NOT NULL DEFAULT 0,reply_count $uint NOT NULL DEFAULT 0,view_count $uint NOT NULL DEFAULT 0,last_reply_at $uint NOT NULL DEFAULT 0,last_reply_user_id $uint NOT NULL DEFAULT 0,created_at $uint NOT NULL)",
        'app_topics_del' => "CREATE TABLE app_topics_del(id $id,topic_id $uint NOT NULL,reply_id $uint NOT NULL,created_at $uint NOT NULL)",
        'app_replies' => "CREATE TABLE app_replies(id $id,topic_id $uint NOT NULL,user_id $uint NOT NULL,body $long NOT NULL,created_at $uint NOT NULL,updated_at $uint NOT NULL)",
        'app_attachments' => "CREATE TABLE app_attachments(id $id,user_id $uint NOT NULL,hash $short NOT NULL,file_name $short NOT NULL,original_name $short NOT NULL DEFAULT '',ext $short NOT NULL DEFAULT '',mime $short NOT NULL DEFAULT '',size $uint NOT NULL DEFAULT 0,is_image INTEGER NOT NULL DEFAULT 0,created_at $uint NOT NULL)",
        'app_settings' => "CREATE TABLE app_settings(name $key PRIMARY KEY,value $long NOT NULL)",
    ];
    if ($driver === 'mysql') {
        foreach ($tables as &$sql) $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        unset($sql);
    }
    $indexes = [
        'idx_users_group' => 'app_users(group_id)', 'idx_users_email' => 'app_users(email)', 'idx_forums_sort' => 'app_forums(sort,id)',
        'idx_replies_topic_time' => 'app_replies(topic_id,created_at,id)',
        'idx_topics_del_topic' => 'app_topics_del(topic_id,created_at,id)',
        'idx_topics_del_reply' => 'app_topics_del(reply_id)',
        'idx_replies_user_time' => 'app_replies(user_id,created_at DESC,id DESC)',
        'idx_attachments_user' => 'app_attachments(user_id,created_at DESC,id DESC)',
        'idx_notifications_recipient_unread' => 'app_notifications(recipient_id,read_at)',
        'idx_notifications_recipient_time' => 'app_notifications(recipient_id,created_at DESC,id DESC)',
        'idx_notifications_sender_time' => 'app_notifications(sender_id,created_at DESC,id DESC)',
        'idx_topics_created' => 'app_topics(created_at DESC,id DESC)', 'idx_topics_last_reply' => 'app_topics(last_reply_at DESC,id DESC)',
        'idx_topics_user_created' => 'app_topics(user_id,created_at DESC,id DESC)', 'idx_topics_forum_created' => 'app_topics(forum_id,created_at DESC,id DESC)',
        'idx_topics_forum_last_reply' => 'app_topics(forum_id,last_reply_at DESC,id DESC)',
    ];
    foreach ($indexes as $name => &$target) $target = 'CREATE INDEX ' . $name . ' ON ' . $target;
    unset($target);
    $indexes['idx_attachments_user_hash'] = 'CREATE UNIQUE INDEX idx_attachments_user_hash ON app_attachments(user_id,hash)';
    return [$tables, $indexes];
}

public static function app_db_index_table(string $sql): string
{
    return preg_match('/\bON\s+[`"]?([A-Za-z_][A-Za-z0-9_]*)/i', $sql, $match) ? $match[1] : '';
}

public static function i_db_name(): string
{
    if (is_file(INSTALL_DB_CONFIG_FILE)) {
        $config = include INSTALL_DB_CONFIG_FILE;
        $name = is_array($config) ? basename((string)($config['db_file'] ?? '')) : '';
        if ($name !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.sqlite$/', $name)) return $name;
    }
    if (is_file(INSTALL_DEFAULT_DB_FILE)) return basename(INSTALL_DEFAULT_DB_FILE);
    return 'forum-' . bin2hex(random_bytes(8)) . '.sqlite';
}
public static function i_save_db_config(array $config): void
{
    if (!is_dir(INSTALL_DATA_DIR)) mkdir(INSTALL_DATA_DIR, 0755, true);
    if (file_put_contents(INSTALL_DB_CONFIG_FILE, self::app_db_config_source($config), LOCK_EX) === false) self::i_install_error('安装失败', '数据库配置文件写入失败。');
}

public static function i_require_writable_dirs(): void
{
    $dirs = [
        INSTALL_DATA_DIR => 'app/data/',
    ];
    foreach ($dirs as $dir => $label) {
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            self::i_install_error('安装环境检查未通过', $label . '目录无法创建，请检查目录权限。');
        }
        if (!is_writable($dir)) {
            self::i_install_error('安装环境检查未通过', $label . '目录不可写，请赋予 PHP 写入权限。');
        }
        $probe = tempnam($dir, '.install-');
        if ($probe === false || file_put_contents($probe, '1', LOCK_EX) === false) {
            if ($probe !== false) @unlink($probe);
            self::i_install_error('安装环境检查未通过', $label . '目录无法写入文件，请检查目录权限。');
        }
        @unlink($probe);
    }
}

public static function i_install_error(string $title, string $message): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $title . PHP_EOL . $message . PHP_EOL);
        exit(1);
    }
    self::setup_html($title, '<div class="hero"><h1>' . h($title) . '</h1><p>安装环境检查未通过。</p></div><div class="card"><div class="bd"><div class="note warn">' . h($message) . '</div></div></div>');
}
public static function i_db(array $config): PDO
{
    try {
        return app_db_connect($config);
    } catch (Throwable $e) {
        self::i_install_error('数据库初始化失败', '数据库连接失败：' . $e->getMessage());
    }
}
public static function i_database_install_state(PDO $db, string $driver): string
{
    if (!app_db_table_exists($db, $driver, 'app_users') || $db->query('SELECT id FROM app_users ORDER BY id LIMIT 1')->fetchColumn() === false) return 'empty';
    foreach (['app_settings', 'app_groups', 'app_forums', 'app_topics', 'app_replies'] as $table) if (!app_db_table_exists($db, $driver, $table)) return 'partial';
    $site = $db->query("SELECT value FROM app_settings WHERE name='site_name' LIMIT 1")->fetchColumn();
    return $site === false ? 'partial' : 'installed';
}
public static function i_restore_existing_install(array $config): never
{
    self::i_save_db_config($config);
    if (file_put_contents(INSTALL_LOCK_FILE, (string)now(), LOCK_EX) === false) self::i_install_error('安装失败', '安装锁文件写入失败。');
    header('Location: index.php?a=login', true, 303);
    exit;
}
public static function i_result(string $title, string $admin_user, string $admin_pass, string $admin_email, string $site_name, string $database, string $notice = ''): void
{
    $warning = $notice === '' ? '' : '<div class="note warn">' . h($notice) . '</div>';
    self::setup_html($title, '<div class="hero"><h1>安装完成</h1><p>站点已初始化，管理员账号已创建。</p></div><div class="grid"><section class="card"><div class="hd"><h2>安装结果</h2></div><div class="bd"><div class="note ok">可以直接进入论坛使用，建议立即登录后台修改密码。</div>' . $warning . '<div style="height:12px"></div><div class="kv"><div>站点名</div><div>' . h($site_name) . '</div><div>数据库</div><div class="mono">' . h($database) . '</div><div>管理员用户名</div><div class="mono">' . h($admin_user) . '</div><div>管理员邮箱</div><div class="mono">' . h($admin_email) . '</div><div>管理员密码</div><div class="admin-pass mono">' . h($admin_pass) . '</div></div><div style="height:14px"></div><div class="actions"><a class="btn alt" href="index.php">进入首页</a><a class="btn" href="index.php?a=admin">进入后台</a></div></div></section><aside class="card"><div class="hd"><h2>已完成内容</h2></div><div class="bd"><ul class="list"><li>创建数据库结构和可用索引</li><li>创建默认版块</li><li>创建第一个管理员</li><li>生成缓存文件</li><li>数据库密码仅保存在 app/data/db.php</li></ul></div></aside></div><div class="footer">请立即保存本页显示的管理员密码，离开后无法再次查看。</div>');
}
public static function i_locked(): void
{
    self::setup_html('安装已锁定', '<div class="hero"><h1>安装已锁定</h1><p>安装入口当前不可访问。</p></div><div class="card"><div class="bd"><div class="note warn">如需重新安装，请先删除安装锁文件后再访问。</div><div style="height:14px"></div><div class="actions"><a class="btn" href="index.php">进入首页</a></div></div></div>');
}
public static function i_form(string $site_name, string $admin_user, string $admin_email, string $admin_pass, string $default_forum, array $values = [], string $error = ''): void
{
    $type = in_array((string)($values['db_type'] ?? 'sqlite'), ['sqlite', 'mysql', 'pgsql'], true) ? (string)($values['db_type'] ?? 'sqlite') : 'sqlite';
    $option = fn(string $value, string $label): string => '<option value="' . $value . '"' . ($type === $value ? ' selected' : '') . '>' . $label . '</option>';
    $v = fn(string $name, string $default = ''): string => h((string)($values[$name] ?? $default));
    $default_host = $type === 'pgsql' ? 'postgres' : 'mysql';
    $default_port = $type === 'pgsql' ? '5432' : '3306';
    $db_fields = '<div class="db-fields" id="server-db-fields"' . ($type === 'sqlite' ? ' hidden' : '') . '><div class="row compact"><div class="field"><label>数据库地址</label><input type="text" name="db_host" value="' . $v('db_host', $default_host) . '"></div><div class="field"><label>端口</label><input type="text" name="db_port" value="' . $v('db_port', $default_port) . '"></div></div><div class="row"><label>数据库名</label><input type="text" name="db_name" value="' . $v('db_name') . '"><small>数据库需要提前创建，安装器会创建其中的数据表。</small></div><div class="row compact"><div class="field"><label>数据库用户</label><input type="text" name="db_user" value="' . $v('db_user') . '"></div><div class="field"><label>数据库密码</label><input type="password" name="db_password" value="' . $v('db_password') . '"></div></div></div>';
    $error_html = $error === '' ? '' : '<div class="note warn">' . h($error) . '</div>';
    $body = '<div class="hero"><h1>安装</h1><p>一页完成初始化，创建管理员和默认版块。</p></div>' . $error_html . '<div class="grid"><section class="card"><div class="hd"><h2>安装配置</h2></div><div class="bd"><form class="form" method="post"><input type="hidden" name="step" value="install"><div class="row"><label>数据库类型</label><select name="db_type" id="db-type">' . $option('sqlite', 'SQLite（默认）') . $option('mysql', 'MySQL') . $option('pgsql', 'PostgreSQL') . '</select></div>' . $db_fields . '<div class="row"><label>站点名称</label><input type="text" name="site_name" value="' . h($site_name) . '" required></div><div class="row"><label>管理员用户名</label><input type="text" name="admin_username" value="' . h($admin_user) . '" required></div><div class="row"><label>管理员邮箱</label><input type="email" name="admin_email" value="' . h($admin_email) . '" required><small>用于找回密码与通知。</small></div><div class="row"><label>管理员密码</label><input type="password" name="admin_password" value="' . h($admin_pass) . '" required></div><div class="row"><label>确认管理员密码</label><input type="password" name="admin_password2" value="' . h($admin_pass) . '" required></div><div class="row"><label>默认版块名称</label><input type="text" name="forum_name" value="' . h($default_forum) . '" required></div><div class="checks"><label class="check"><input type="checkbox" name="confirm_clean" value="1" required><span>我确认这是全新安装，数据将被清理。</span></label><label class="check"><input type="checkbox" name="confirm_admin" value="1" required><span>我确认需要手工设置第一个管理员密码。</span></label></div>' . self::terms_consent('install') . '<div class="actions"><button class="btn" type="submit">开始安装</button></div></form></div></section><aside class="card"><div class="hd"><h2>安装说明</h2></div><div class="bd"><ul class="list"><li>SQLite 无需填写连接信息</li><li>MySQL/PostgreSQL 数据库需提前创建</li><li>第一个管理员将拥有全部权限</li><li>管理员邮箱可用于找回密码</li></ul></div></aside></div><script>const type=document.getElementById("db-type"),fields=document.getElementById("server-db-fields"),host=fields.querySelector("[name=db_host]"),port=fields.querySelector("[name=db_port]"),defaults={mysql:["mysql","3306"],pgsql:["postgres","5432"]};function toggleDb(change){const values=defaults[type.value]||null;fields.hidden=!values;if(change&&values){host.value=values[0];port.value=values[1]}}type.addEventListener("change",()=>toggleDb(true));addEventListener("pageshow",()=>toggleDb(false));toggleDb(false);</script>';
    self::setup_html('安装', $body);
}

public static function setup_install_run(): never
{
    if (is_file(INSTALL_LOCK_FILE)) {
        self::i_locked();
    }
    self::i_require_writable_dirs();
    $step = (string)($_POST['step'] ?? '');
    if ($step !== 'install') {
        self::i_form('我的论坛', 'admin', '', '', '默认版块');
    }
    $form_values = $_POST;
    if (!isset($_POST['confirm_clean'], $_POST['confirm_admin'])) self::i_form('我的论坛', 'admin', '', '', '默认版块', $form_values);
    $driver = in_array((string)($_POST['db_type'] ?? 'sqlite'), ['sqlite', 'mysql', 'pgsql'], true) ? (string)($_POST['db_type'] ?? 'sqlite') : 'sqlite';
    $db_name = trim((string)($_POST['db_name'] ?? ''));
    $sqlite_name = $driver === 'sqlite' ? self::i_db_name() : '';
    $config = $driver === 'sqlite' ? [
        'driver' => 'sqlite', 'database' => $sqlite_name, 'path' => INSTALL_DATA_DIR . '/' . $sqlite_name,
    ] : [
        'driver' => $driver,
        'host' => trim((string)($_POST['db_host'] ?? '127.0.0.1')),
        'port' => max(1, (int)($_POST['db_port'] ?? ($driver === 'mysql' ? 3306 : 5432))),
        'database' => $db_name,
        'username' => (string)($_POST['db_user'] ?? ''),
        'password' => (string)($_POST['db_password'] ?? ''),
    ];
    if ($driver !== 'sqlite' && ($config['host'] === '' || $config['database'] === '' || $config['username'] === '')) self::i_form('我的论坛', 'admin', '', '', '默认版块', $form_values);
    $site_name = trim((string)($_POST['site_name'] ?? '我的论坛'));
    $admin_username = trim((string)($_POST['admin_username'] ?? 'admin'));
    $admin_email = trim((string)($_POST['admin_email'] ?? ''));
    $admin_password = (string)($_POST['admin_password'] ?? '');
    $admin_password2 = (string)($_POST['admin_password2'] ?? '');
    $forum_name = trim((string)($_POST['forum_name'] ?? '默认版块'));
    if ($site_name === '' || $admin_username === '' || $admin_email === '' || $admin_password === '' || $forum_name === '') self::i_form($site_name ?: '我的论坛', $admin_username ?: 'admin', $admin_email, $admin_password, $forum_name ?: '默认版块', $form_values);
    if ($admin_password !== $admin_password2) self::i_form($site_name, $admin_username, $admin_email, $admin_password, $forum_name, $form_values);
    if (!self::terms_accepted($form_values)) self::i_form($site_name, $admin_username, $admin_email, $admin_password, $forum_name, $form_values, '请先阅读完整软件使用条款并勾选同意。');
    if (is_file(INSTALL_LOCK_FILE)) self::i_locked();
    $db = self::i_db($config);
    $install_state = self::i_database_install_state($db, $driver);
    if ($install_state === 'installed') self::i_restore_existing_install($config);
    if ($install_state === 'partial') self::i_install_error('检测到已有数据', '数据库中已有用户数据，但安装记录不完整。请恢复原程序文件或使用空数据库安装。');
    self::i_save_db_config($config);
    [$tables, $indexes] = self::app_db_schema($driver);
    foreach ($tables as $table => $sql) if (!app_db_table_exists($db, $driver, $table)) $db->exec($sql);
    foreach ($indexes as $index => $sql) {
        if (app_db_index_exists($db, $driver, $index, self::app_db_index_table($sql))) continue;
        $db->exec($sql);
    }
    $seed = $db->prepare(app_db_upsert_sql($driver, 'app_groups', ['id', 'name', 'allow_manage', 'allow_admin'], ['id']));
    $seed->execute([1, '管理员', 1, 1]); $seed->execute([2, '会员', 0, 0]);
    $seed = $db->prepare(app_db_upsert_sql($driver, 'app_forums', ['id', 'name', 'description', 'sort'], ['id']));
    $seed->execute([1, $forum_name, '欢迎发帖', 0]);
    if ($driver === 'pgsql') {
        $db->exec("SELECT setval(pg_get_serial_sequence('app_groups','id'), (SELECT MAX(id) FROM app_groups))");
        $db->exec("SELECT setval(pg_get_serial_sequence('app_forums','id'), (SELECT MAX(id) FROM app_forums))");
    }
    $settings = default_settings();
    $settings['site_name'] = $site_name;
    $settings['software_terms_version'] = self::TERMS_VERSION;
    $settings['software_terms_accepted_at'] = (string)now();
    $stmt = $db->prepare(app_db_upsert_sql($driver, 'app_settings', ['name', 'value'], ['name']));
    foreach ($settings as $name => $value) $stmt->execute([$name, $value]);
    $admin_pass = $admin_password;
    $welcome_ts = now();
    $db->prepare("INSERT INTO app_users(username,password,email,bio,avatar_style,avatar_seed,group_id,last_post_at,created_at) VALUES(?,?,?,?,?,?,?,?,?)")->execute([$admin_username, password_hash($admin_pass, PASSWORD_DEFAULT), $admin_email, '站点管理员', '', '', 1, $welcome_ts, $welcome_ts]);
    forums_cache(true);
    groups_cache(true);
    if (file_put_contents(INSTALL_LOCK_FILE, (string)now(), LOCK_EX) === false) self::i_install_error('安装失败', '安装锁文件写入失败。');
    $database_label = $driver === 'sqlite' ? 'app/data/' . $config['database'] : strtoupper($driver === 'pgsql' ? 'PostgreSQL' : 'MySQL') . ' / ' . $config['database'];
    self::i_result('安装完成', $admin_username, $admin_pass, $admin_email, $site_name, $database_label);
}


public static function migrate_driver(string $driver): string
{
    if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) throw new RuntimeException('不支持的数据库类型。');
    return $driver;
}

public static function migrate_identifier(string $driver, string $name, string $context = ''): string
{
    if ($name === '' || str_contains($name, "\0")) {
        $reason = $name === '' ? '为空' : '包含 NUL 字符';
        $detail = '数据库标识符无效：' . $reason . '；驱动=' . $driver . '；原始值=' . var_export($name, true);
        if ($context !== '') $detail .= '；位置=' . $context;
        throw new InvalidArgumentException($detail . '。');
    }
    return $driver === 'mysql' ? '`' . str_replace('`', '``', $name) . '`' : '"' . str_replace('"', '""', $name) . '"';
}

public static function migrate_source_config(): array
{
    $driver = self::migrate_driver((string)($_POST['source_driver'] ?? 'sqlite'));
    if ($driver === 'sqlite') {
        $path = trim((string)($_POST['source_sqlite'] ?? ''));
        if ($path === '') throw new RuntimeException('SQLite 文件不能为空。');
        if ($path[0] !== DIRECTORY_SEPARATOR) $path = APP_ROOT . '/' . $path;
        return ['driver' => 'sqlite', 'database' => basename($path), 'path' => $path];
    }
    $config = [
        'driver' => $driver,
        'host' => trim((string)($_POST['source_host'] ?? '127.0.0.1')),
        'port' => max(1, (int)($_POST['source_port'] ?? ($driver === 'mysql' ? 3306 : 5432))),
        'database' => trim((string)($_POST['source_database'] ?? '')),
        'username' => (string)($_POST['source_username'] ?? ''),
        'password' => (string)($_POST['source_password'] ?? ''),
    ];
    if ($config['host'] === '' || $config['database'] === '' || $config['username'] === '') throw new RuntimeException('数据库连接信息不完整。');
    return $config;
}

public static function migrate_source_db(array $config): PDO
{
    if ($config['driver'] === 'sqlite' && !is_file((string)$config['path'])) throw new RuntimeException('SQLite 文件不存在：' . $config['path']);
    return app_db_connect($config);
}

public static function migrate_tables(PDO $db, string $driver): array
{
    if ($driver === 'sqlite') {
        $rows = $db->query("SELECT name,sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll();
        $virtual = [];
        foreach ($rows as $row) if (stripos((string)$row['sql'], 'CREATE VIRTUAL TABLE') === 0) $virtual[] = (string)$row['name'];
        $tables = [];
        foreach ($rows as $row) {
            $name = (string)$row['name'];
            foreach ($virtual as $prefix) if ($name === $prefix || str_starts_with($name, $prefix . '_')) continue 2;
            $tables[] = $name;
        }
        return $tables;
    }
    $stmt = $driver === 'mysql'
        ? $db->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY table_name")
        : $db->query('SELECT tablename FROM pg_tables WHERE schemaname=current_schema() ORDER BY tablename');
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

public static function migrate_columns(PDO $db, string $driver, string $table): array
{
    if ($driver === 'sqlite') {
        $rows = $db->query('PRAGMA table_info(' . self::migrate_identifier($driver, $table, '读取源表字段：' . $table) . ')')->fetchAll();
        return array_values(array_filter(array_map(fn(array $row): string => (string)($row['name'] ?? ''), $rows)));
    }
    return array_keys(app_db_columns($db, $driver, $table));
}

public static function migrate_column_schema(PDO $db, string $driver, string $table): array
{
    if ($driver === 'sqlite') {
        $rows = $db->query('PRAGMA table_info(' . self::migrate_identifier($driver, $table, '读取源表结构：' . $table) . ')')->fetchAll();
        return array_map(fn(array $row): array => [
            'name' => (string)$row['name'], 'type' => (string)$row['type'], 'nullable' => !(bool)$row['notnull'],
            'default' => $row['dflt_value'], 'auto' => false, 'pk' => (int)$row['pk'],
        ], $rows);
    }
    if ($driver === 'mysql') {
        $sql = 'SELECT column_name,data_type,column_type,is_nullable,column_default,extra FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? ORDER BY ordinal_position';
    } else {
        $sql = 'SELECT column_name,data_type,udt_name,is_nullable,column_default,is_identity FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=? ORDER BY ordinal_position';
    }
    $stmt = $db->prepare($sql);
    $stmt->execute([$table]);
    $columns = [];
    foreach ($stmt->fetchAll() as $row) {
        $columns[] = [
            'name' => (string)$row['column_name'],
            'type' => (string)($row[$driver === 'mysql' ? 'column_type' : 'udt_name'] ?? $row['data_type']),
            'nullable' => (string)$row['is_nullable'] === 'YES',
            'default' => $row['column_default'],
            'auto' => $driver === 'mysql' ? str_contains((string)$row['extra'], 'auto_increment') : (string)$row['is_identity'] === 'YES' || str_starts_with((string)$row['column_default'], 'nextval('),
            'pk' => 0,
        ];
    }
    return $columns;
}

public static function migrate_db_bool(mixed $value): bool
{
    return in_array($value, [true, 1, '1', 't', 'true'], true);
}

public static function migrate_index_schema(PDO $db, string $driver, string $table, array $columns): array
{
    $indexes = [];
    if ($driver === 'sqlite') {
        $primary = [];
        foreach ($columns as $column) if ($column['pk']) $primary[(int)$column['pk']] = $column['name'];
        if ($primary) {
            ksort($primary);
            $indexes['PRIMARY'] = ['name' => 'PRIMARY', 'unique' => true, 'primary' => true, 'columns' => array_values($primary)];
        }
        foreach ($db->query('PRAGMA index_list(' . self::migrate_identifier($driver, $table, '读取源表索引：' . $table) . ')')->fetchAll() as $index) {
            if ((string)$index['origin'] === 'pk') continue;
            $name = (string)$index['name'];
            $items = $db->query('PRAGMA index_info(' . self::migrate_identifier($driver, $name, '读取源索引字段：表=' . $table . '，索引=' . $name) . ')')->fetchAll();
            $names = array_values(array_filter(array_map(fn(array $row): string => (string)($row['name'] ?? ''), $items)));
            if ($names) $indexes[$name] = ['name' => $name, 'unique' => (bool)$index['unique'], 'primary' => false, 'columns' => $names];
        }
        return array_values($indexes);
    }
    if ($driver === 'mysql') {
        $rows = $db->query('SHOW INDEX FROM ' . self::migrate_identifier($driver, $table, '读取源表索引：' . $table))->fetchAll();
        foreach ($rows as $row) {
            $name = (string)$row['Key_name'];
            $indexes[$name] ??= ['name' => $name, 'unique' => !(bool)$row['Non_unique'], 'primary' => $name === 'PRIMARY', 'columns' => []];
            $indexes[$name]['columns'][(int)$row['Seq_in_index']] = (string)$row['Column_name'];
        }
    } else {
        $stmt = $db->prepare('SELECT oid FROM pg_class WHERE relname=? AND relnamespace=(SELECT oid FROM pg_namespace WHERE nspname=current_schema())');
        $stmt->execute([$table]);
        $table_oid = (int)$stmt->fetchColumn();
        if ($table_oid <= 0) return [];
        $stmt = $db->prepare('SELECT indexrelid,indisunique,indisprimary,indkey::text index_keys FROM pg_index WHERE indrelid=?');
        $stmt->execute([$table_oid]);
        $rows = $stmt->fetchAll();
        $index_ids = array_values(array_unique(array_filter(array_map('intval', array_column($rows, 'indexrelid')))));
        $index_names = [];
        if ($index_ids) {
            $marks = sql_marks(count($index_ids));
            $stmt = $db->prepare("SELECT oid,relname FROM pg_class WHERE oid IN ($marks)");
            $stmt->execute($index_ids);
            foreach ($stmt->fetchAll() as $index) $index_names[(int)$index['oid']] = $index;
        }
        $column_ids = [];
        foreach ($rows as $row) $column_ids = array_merge($column_ids, preg_split('/\s+/', trim((string)$row['index_keys'])) ?: []);
        $column_ids = array_values(array_unique(array_filter(array_map('intval', $column_ids))));
        $column_names = [];
        if ($column_ids) {
            $marks = sql_marks(count($column_ids));
            $stmt = $db->prepare("SELECT attnum,attname FROM pg_attribute WHERE attrelid=? AND attnum IN ($marks)");
            $stmt->execute(array_merge([$table_oid], $column_ids));
            foreach ($stmt->fetchAll() as $column) $column_names[(int)$column['attnum']] = (string)$column['attname'];
        }
        foreach ($rows as $row) {
            $index_id = (int)$row['indexrelid'];
            $name = (string)($index_names[$index_id]['relname'] ?? '');
            if ($name === '') continue;
            $keys = array_values(array_filter(array_map('intval', preg_split('/\s+/', trim((string)$row['index_keys'])) ?: [])));
            $names = array_values(array_filter(array_map(fn(int $key): string => $column_names[$key] ?? '', $keys)));
            if ($names) $indexes[$name] = ['name' => $name, 'unique' => self::migrate_db_bool($row['indisunique']), 'primary' => self::migrate_db_bool($row['indisprimary']), 'columns' => $names];
        }
    }
    foreach ($indexes as &$index) {
        ksort($index['columns']);
        $index['columns'] = array_values($index['columns']);
    }
    unset($index);
    return array_values($indexes);
}

public static function migrate_default_sql(PDO $db, string $source_driver, mixed $default, bool $expression = false): string
{
    if ($default === null) return '';
    $value = trim((string)$default);
    if (($value === '' && $source_driver !== 'mysql') || strcasecmp($value, 'NULL') === 0 || str_starts_with($value, 'nextval(')) return '';
    if ($value === '') $literal = $db->quote('');
    elseif (is_numeric($value)) $literal = $value;
    elseif (strcasecmp($value, 'true') === 0 || strcasecmp($value, 'false') === 0) $literal = strcasecmp($value, 'true') === 0 ? '1' : '0';
    if (preg_match('/^CURRENT_(?:TIMESTAMP|DATE|TIME)(?:\(\))?$/i', $value)) return ' DEFAULT ' . strtoupper(rtrim($value, '()'));
    if (!isset($literal)) {
        if ($source_driver === 'pgsql' && preg_match("/^'(.*)'(?:::[A-Za-z0-9_\[\] ]+)?$/s", $value, $match)) $value = str_replace("''", "'", $match[1]);
        elseif ($source_driver !== 'mysql' && strlen($value) >= 2 && (($value[0] === "'" && $value[-1] === "'") || ($value[0] === '"' && $value[-1] === '"'))) $value = str_replace($value[0] . $value[0], $value[0], substr($value, 1, -1));
        $literal = $db->quote($value);
    }
    return ' DEFAULT ' . ($expression ? '(' . $literal . ')' : $literal);
}

public static function migrate_column_type(string $source_type, string $target_driver, bool $indexed, bool $auto): string
{
    $type = strtolower($source_type);
    if ($auto) return match ($target_driver) {
        'mysql' => 'BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT',
        'pgsql' => 'BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY',
        default => 'INTEGER PRIMARY KEY AUTOINCREMENT',
    };
    if (preg_match('/int|serial|bool/', $type)) return $target_driver === 'sqlite' ? 'INTEGER' : 'BIGINT' . ($target_driver === 'mysql' && str_contains($type, 'unsigned') ? ' UNSIGNED' : '');
    if (preg_match('/real|float|double|decimal|numeric/', $type)) return match ($target_driver) {'mysql' => 'DOUBLE', 'pgsql' => 'DOUBLE PRECISION', default => 'REAL'};
    if (preg_match('/blob|binary|bytea/', $type)) return match ($target_driver) {'mysql' => 'LONGBLOB', 'pgsql' => 'BYTEA', default => 'BLOB'};
    if ($indexed) return $target_driver === 'mysql' ? 'VARCHAR(191)' : 'TEXT';
    return $target_driver === 'mysql' ? 'LONGTEXT' : 'TEXT';
}

public static function migrate_index_name(PDO $db, string $driver, string $table, string $name, array $columns, bool $unique): string
{
    if ($name === 'PRIMARY' || str_starts_with($name, 'sqlite_autoindex_') || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) $name = ($unique ? 'uidx_' : 'idx_') . $table . '_' . implode('_', $columns);
    $name = strlen($name) > 55 ? substr($name, 0, 44) . '_' . substr(hash('sha256', $name), 0, 10) : $name;
    if (!app_db_index_exists($db, $driver, $name, $table)) return $name;
    return substr($name, 0, 44) . '_' . substr(hash('sha256', $table . ':' . $name), 0, 10);
}

public static function migrate_install_table(PDO $source, string $source_driver, PDO $target, string $target_driver, string $source_table, string $target_table): void
{
    $columns = self::migrate_column_schema($source, $source_driver, $source_table);
    $indexes = self::migrate_index_schema($source, $source_driver, $source_table, $columns);
    $primary = [];
    $indexed = [];
    foreach ($indexes as $index) {
        if ($index['primary']) $primary = $index['columns'];
        foreach ($index['columns'] as $column) $indexed[$column] = true;
    }
    $auto_column = count($primary) === 1 ? $primary[0] : '';
    $definitions = [];
    $auto_primary = false;
    foreach ($columns as $column) {
        $auto = $column['name'] === $auto_column && ($column['auto'] || preg_match('/int|serial/i', $column['type']));
        if ($auto) $auto_primary = true;
        $type = self::migrate_column_type($column['type'], $target_driver, isset($indexed[$column['name']]), $auto);
        $definition = self::migrate_identifier($target_driver, (string)$column['name'], '创建目标表字段：表=' . $target_table . '，字段=' . (string)$column['name']) . ' ' . $type;
        if (!$auto) $definition .= (!$column['nullable'] ? ' NOT NULL' : '') . self::migrate_default_sql($target, $source_driver, $column['default'], $target_driver === 'mysql' && preg_match('/TEXT|BLOB/', $type));
        $definitions[] = $definition;
    }
    if ($primary && !$auto_primary) $definitions[] = 'PRIMARY KEY(' . implode(',', array_map(fn(string $name): string => self::migrate_identifier($target_driver, $name, '创建目标表主键：表=' . $target_table . '，字段=' . $name), $primary)) . ')';
    $sql = 'CREATE TABLE ' . self::migrate_identifier($target_driver, $target_table, '创建目标表：源表=' . $source_table . '，目标表=' . $target_table) . '(' . implode(',', $definitions) . ')';
    if ($target_driver === 'mysql') $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $target->exec($sql);
    foreach ($indexes as $index) {
        if ($index['primary'] || !$index['columns']) continue;
        $name = self::migrate_index_name($target, $target_driver, $target_table, $index['name'], $index['columns'], $index['unique']);
        $target->exec('CREATE ' . ($index['unique'] ? 'UNIQUE ' : '') . 'INDEX ' . self::migrate_identifier($target_driver, $name, '创建目标索引：表=' . $target_table . '，索引=' . $name) . ' ON ' . self::migrate_identifier($target_driver, $target_table, '创建目标索引所属表：' . $target_table) . '(' . implode(',', array_map(fn(string $column): string => self::migrate_identifier($target_driver, $column, '创建目标索引字段：表=' . $target_table . '，索引=' . $name . '，字段=' . $column), $index['columns'])) . ')');
    }
}

public static function migrate_database_identity(PDO $db, array $config): string
{
    if ($config['driver'] === 'sqlite') return (string)realpath((string)$config['path']);
    try {
        if ($config['driver'] === 'mysql') return (string)$db->query("SELECT CONCAT(@@server_uuid,':',DATABASE())")->fetchColumn();
        return (string)$db->query("SELECT current_database()||':'||system_identifier::text FROM pg_control_system()")->fetchColumn();
    } catch (Throwable $e) {
        return '';
    }
}

public static function migrate_same_database(PDO $source_db, array $source, PDO $target_db, array $target): bool
{
    if ($source['driver'] !== $target['driver']) return false;
    $source_identity = self::migrate_database_identity($source_db, $source);
    $target_identity = self::migrate_database_identity($target_db, $target);
    if ($source_identity !== '' && $target_identity !== '') return hash_equals($source_identity, $target_identity);
    return strtolower((string)$source['host']) === strtolower((string)$target['host'])
        && (int)$source['port'] === (int)$target['port']
        && (string)$source['database'] === (string)$target['database'];
}

public static function migrate_core_table_map(): array
{
    return [
        'groups' => 'app_groups',
        'users' => 'app_users',
        'forums' => 'app_forums',
        'topics' => 'app_topics',
        'replies' => 'app_replies',
        'attachments' => 'app_attachments',
        'notifications' => 'app_notifications',
        'settings' => 'app_settings',
    ];
}

public static function migrate_order_tables(array $tables): array
{
    $core = array_flip(array_values(self::migrate_core_table_map()));
    usort($tables, fn(string $a, string $b): int => (($core[self::migrate_target_table($a)] ?? PHP_INT_MAX) <=> ($core[self::migrate_target_table($b)] ?? PHP_INT_MAX)) ?: strcmp($a, $b));
    return $tables;
}

public static function migrate_target_table(string $table): string
{
    return self::migrate_core_table_map()[$table] ?? $table;
}

public static function migrate_value(mixed $value): mixed
{
    if (is_resource($value)) return stream_get_contents($value);
    return is_bool($value) ? (int)$value : $value;
}

public static function migrate_reset_sequences(PDO $db, string $driver, array $tables): void
{
    if ($driver !== 'pgsql') return;
    $sequence = $db->prepare("SELECT pg_get_serial_sequence(?, 'id')");
    $set = $db->prepare('SELECT setval(CAST(? AS regclass),?,?)');
    foreach ($tables as $table) {
        if (!in_array('id', self::migrate_columns($db, $driver, $table), true)) continue;
        $sequence->execute([$table]);
        $name = $sequence->fetchColumn();
        if (!$name) continue;
        $max = (int)$db->query('SELECT COALESCE(MAX(id),0) FROM ' . self::migrate_identifier($driver, $table, '重置序列：表=' . $table))->fetchColumn();
        $set->execute([$name, max(1, $max), $max > 0]);
    }
}

public static function migrate_run(PDO $source, array $source_config): array
{
    $target = db();
    $target_config = db_config();
    if (self::migrate_same_database($source, $source_config, $target, $target_config)) throw new RuntimeException('源数据库不能与当前数据库相同。');
    [$schema_tables] = self::app_db_schema($target_config['driver']);
    $source_tables = [];
    foreach (array_keys($schema_tables) as $target_table) {
        $source_candidates = [$target_table];
        foreach (self::migrate_core_table_map() as $source_table => $mapped_table) if ($mapped_table === $target_table) $source_candidates[] = $source_table;
        foreach (array_unique($source_candidates) as $source_table) if (app_db_table_exists($source, $source_config['driver'], $source_table)) $source_tables[] = $source_table;
    }
    $tables = self::migrate_order_tables($source_tables);
    if (!$tables) throw new RuntimeException('源数据库没有可迁入的系统数据表。');
    $target_tables = [];
    foreach (array_keys($schema_tables) as $table) if (app_db_table_exists($target, $target_config['driver'], $table)) $target_tables[$table] = true;
    $mapped_tables = [];
    foreach ($tables as $source_table) {
        $target_table = self::migrate_target_table($source_table);
        if (isset($mapped_tables[$target_table])) throw new RuntimeException('多个源表映射到同一目标表：' . $mapped_tables[$target_table] . '、' . $source_table);
        $mapped_tables[$target_table] = $source_table;
        if (isset($target_tables[$target_table])) continue;
        self::migrate_install_table($source, $source_config['driver'], $target, $target_config['driver'], $source_table, $target_table);
        $target_tables[$target_table] = true;
    }
    $plans = [];
    foreach ($tables as $source_table) {
        $target_table = self::migrate_target_table($source_table);
        $columns = array_values(array_intersect(self::migrate_columns($target, $target_config['driver'], $target_table), self::migrate_columns($source, $source_config['driver'], $source_table)));
        if ($columns) $plans[] = ['source' => $source_table, 'target' => $target_table, 'columns' => $columns];
    }
    if (!$plans) throw new RuntimeException('没有找到兼容的数据字段。');
    $counts = [];
    if ($source_config['driver'] === 'mysql') $source->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    if ($source_config['driver'] === 'pgsql') $source->exec('BEGIN ISOLATION LEVEL REPEATABLE READ');
    else $source->beginTransaction();
    $target->beginTransaction();
    try {
        foreach (array_reverse($plans) as $plan) $target->exec('DELETE FROM ' . self::migrate_identifier($target_config['driver'], $plan['target'], '清空目标表：' . $plan['target']));
        foreach ($plans as $plan) {
            $source_table = $plan['source'];
            $target_table = $plan['target'];
            $columns = $plan['columns'];
            $source_columns = implode(',', array_map(fn(string $name): string => self::migrate_identifier($source_config['driver'], $name, '读取源字段：表=' . $source_table . '，字段=' . $name), $columns));
            $target_columns = implode(',', array_map(fn(string $name): string => self::migrate_identifier($target_config['driver'], $name, '写入目标字段：表=' . $target_table . '，字段=' . $name), $columns));
            $order = in_array('id', $columns, true) ? ' ORDER BY ' . self::migrate_identifier($source_config['driver'], 'id', '读取源表排序字段：' . $source_table) : '';
            $read = $source->query('SELECT ' . $source_columns . ' FROM ' . self::migrate_identifier($source_config['driver'], $source_table, '读取源表：' . $source_table) . $order);
            $insert = 'INSERT INTO ' . self::migrate_identifier($target_config['driver'], $target_table, '写入目标表：源表=' . $source_table . '，目标表=' . $target_table) . '(' . $target_columns . ') VALUES(' . sql_marks(count($columns)) . ')';
            $insert = $target_config['driver'] === 'mysql' ? str_replace('INSERT INTO', 'INSERT IGNORE INTO', $insert) : $insert . ' ON CONFLICT DO NOTHING';
            $write = $target->prepare($insert);
            $count = 0;
            $attachment_hashes = [];
            while ($row = $read->fetch()) {
                if (in_array($source_table, ['topics', 'app_topics'], true) && array_key_exists('reply_order', $row)) $row['reply_order'] = strtolower(trim((string)$row['reply_order'])) === 'desc' || (string)$row['reply_order'] === '1' ? 1 : 0;
                if (in_array($source_table, ['attachments', 'app_attachments'], true) && isset($row['user_id'], $row['hash'])) {
                    $key = $row['user_id'] . ':' . $row['hash'];
                    if (isset($attachment_hashes[$key])) continue;
                    $attachment_hashes[$key] = true;
                }
                $write->execute(array_map(fn(string $column) => self::migrate_value($row[$column]), $columns));
                if ($write->rowCount() > 0) $count++;
            }
            $counts[$source_table] = $count;
        }
        search_index_rebuild();
        self::migrate_reset_sequences($target, $target_config['driver'], array_column($plans, 'target'));
        $source->commit();
        $target->commit();
    } catch (Throwable $e) {
        if ($source->inTransaction()) $source->rollBack();
        if ($target->inTransaction()) $target->rollBack();
        throw $e;
    }
    return $counts;
}

public static function migrate_refresh_caches(): void
{
    unset($GLOBALS['__settings_cache']);
    forums_cache(true);
    groups_cache(true);
}

public static function migrate_page(): void
{
    need_admin();
    set_time_limit(0);
    ignore_user_abort(true);
    $error = '';
    $counts = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if (!isset($_POST['confirm_replace'])) throw new RuntimeException('请确认清空当前新数据库。');
            if (!self::terms_accepted($_POST)) throw new RuntimeException('请先阅读完整软件使用条款并勾选同意。');
            $source_config = self::migrate_source_config();
            $counts = self::migrate_run(self::migrate_source_db($source_config), $source_config);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
    $target = db_config();
    $target_label = $target['driver'] === 'sqlite' ? 'SQLite / ' . basename((string)$target['path']) : strtoupper($target['driver'] === 'pgsql' ? 'PostgreSQL' : 'MySQL') . ' / ' . $target['database'];
    if (is_array($counts)) {
        $rows = '';
        foreach ($counts as $table => $count) $rows .= '<div>' . h($table) . '</div><div>' . (int)$count . '</div>';
        self::migrate_refresh_caches();
        self::setup_html('迁入完成', '<div class="hero"><h1>迁入完成</h1><p>旧数据库数据已写入当前数据库。</p></div><div class="card"><div class="hd"><h2>迁入结果</h2></div><div class="bd"><div class="note ok">共迁入 ' . array_sum($counts) . ' 条数据。</div><div style="height:14px"></div><div class="kv"><div>目标数据库</div><div class="mono">' . h($target_label) . '</div>' . $rows . '</div><div style="height:14px"></div><div class="actions"><a class="btn" href="' . h(route_url('home')) . '">进入首页</a></div></div></div>');
    }
    $driver = in_array((string)($_POST['source_driver'] ?? 'sqlite'), ['sqlite', 'mysql', 'pgsql'], true) ? (string)($_POST['source_driver'] ?? 'sqlite') : 'sqlite';
    $v = fn(string $name, string $default = ''): string => (string)($_POST[$name] ?? $default);
    $options = '<option value="sqlite"' . ($driver === 'sqlite' ? ' selected' : '') . '>SQLite</option><option value="mysql"' . ($driver === 'mysql' ? ' selected' : '') . '>MySQL</option><option value="pgsql"' . ($driver === 'pgsql' ? ' selected' : '') . '>PostgreSQL</option>';
    $message = $error !== '' ? '<div class="note warn">' . h($error) . '</div>' : '';
    $sqlite_fields = '<div class="db-fields" id="sqlite-fields"><div class="row"><label>SQLite 文件路径</label><input type="text" name="source_sqlite" value="' . h($v('source_sqlite', 'app/data/old.sqlite')) . '"></div></div>';
    $server_fields = '<div class="db-fields" id="server-fields"><div class="row compact"><div class="field"><label>数据库地址</label><input type="text" name="source_host" value="' . h($v('source_host', '127.0.0.1')) . '"></div><div class="field"><label>端口</label><input type="text" name="source_port" value="' . h($v('source_port', $driver === 'pgsql' ? '5432' : '3306')) . '"></div></div><div class="row"><label>数据库名</label><input type="text" name="source_database" value="' . h($v('source_database')) . '"></div><div class="row compact"><div class="field"><label>用户名</label><input type="text" name="source_username" value="' . h($v('source_username')) . '"></div><div class="field"><label>密码</label><input type="password" name="source_password"></div></div></div>';
    $form = '<form class="form" method="post" action="' . h(route_url('migrate')) . '" autocomplete="off">' . form_token() . '<div class="row"><label>旧数据库类型</label><select name="source_driver" id="source-driver">' . $options . '</select></div>' . $sqlite_fields . $server_fields . '<div class="checks"><label class="check"><input type="checkbox" name="confirm_replace" value="1" required><span>确认清空当前数据库中的同名数据表。</span></label></div>' . self::terms_consent('migrate') . '<div class="actions"><button class="btn" type="submit">开始迁入</button></div></form>';
    $body = '<div class="hero"><h1>数据迁入</h1><p>从旧数据库迁入当前已安装数据库。</p></div>' . $message . '<div class="grid"><section class="card"><div class="hd"><h2>旧数据库配置</h2></div><div class="bd">' . $form . '</div></section><aside class="card"><div class="hd"><h2>迁入说明</h2></div><div class="bd"><ul class="list"><li>目标数据库：' . h($target_label) . '</li><li>仅迁入系统定义的数据表</li><li>其他表不读取、不创建、不修改</li><li>缺少的数据表会自动创建</li><li>同名数据表将清空后替换</li><li>附件、头像文件需单独复制</li></ul></div></aside></div><script>const type=document.getElementById("source-driver"),sqlite=document.getElementById("sqlite-fields"),server=document.getElementById("server-fields"),port=document.querySelector("[name=source_port]");function toggle(change){sqlite.hidden=type.value!=="sqlite";server.hidden=type.value==="sqlite";if(change)port.value=type.value==="pgsql"?"5432":"3306"}type.addEventListener("change",()=>toggle(true));toggle(false);</script>';
    self::setup_html('数据迁入', $body);
}
}
