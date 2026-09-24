<?php

declare(strict_types=1);

namespace app\optional;

use PDO;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

if (!defined('APP_ROOT')) exit;

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

public static function app_db_schema(): array
{
    $types = app_db_types();
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

public static function bootstrap_env(string $name): string
{
    $value = getenv($name);
    return is_string($value) ? trim($value) : '';
}

public static function bootstrap_fail(string $message): never
{
    error_log('[bbs1org] 初始化失败：' . $message);
    self::setup_html('初始化失败', '<div class="hero"><h1>初始化失败</h1><p>程序无法自动完成初始化。</p></div><div class="card"><div class="bd"><div class="note warn">' . h($message) . '</div></div></div>');
}

public static function bootstrap_admin_password(): string
{
    $password = (string)(getenv('ADMIN_PASSWORD') ?: '');
    if ($password !== '') return $password;
    $password = bin2hex(random_bytes(8));
    @file_put_contents(DATA_DIR . '/admin-password.txt', $password . "\n", LOCK_EX);
    error_log('[bbs1org] 未设置 ADMIN_PASSWORD，已生成随机管理员密码并保存到 app/data/admin-password.txt，请尽快登录后修改。');
    return $password;
}

public static function bootstrap_state(PDO $db): string
{
    if (!app_db_table_exists($db, 'app_users') || $db->query('SELECT id FROM app_users ORDER BY id LIMIT 1')->fetchColumn() === false) return 'empty';
    foreach (['app_settings', 'app_groups', 'app_forums', 'app_topics', 'app_replies'] as $table) if (!app_db_table_exists($db, $table)) return 'partial';
    $site = $db->query("SELECT value FROM app_settings WHERE name='site_name' LIMIT 1")->fetchColumn();
    return $site === false ? 'partial' : 'installed';
}

public static function bootstrap_run(): void
{
    if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0755, true) && !is_dir(DATA_DIR)) self::bootstrap_fail('app/data/ 目录无法创建，请检查目录权限。');
    if (!is_writable(DATA_DIR)) self::bootstrap_fail('app/data/ 目录不可写，请赋予 PHP 写入权限。');
    $lock = @fopen(DATA_DIR . '/.bootstrap.lock', 'c+');
    if (!is_resource($lock)) self::bootstrap_fail('app/data/.bootstrap.lock 无法创建，请检查目录权限。');
    flock($lock, LOCK_EX);
    try {
        if (is_file(INSTALL_LOCK_FILE)) return;
        try {
            $db = db();
        } catch (Throwable $e) {
            self::bootstrap_fail('数据库连接失败：' . $e->getMessage());
        }
        [$tables, $indexes] = self::app_db_schema();
        foreach ($tables as $table => $sql) if (!app_db_table_exists($db, $table)) $db->exec($sql);
        foreach ($indexes as $index => $sql) {
            if (app_db_index_exists($db, $index, self::app_db_index_table($sql))) continue;
            $db->exec($sql);
        }
        $state = self::bootstrap_state($db);
        if ($state === 'partial') self::bootstrap_fail('数据库中已有用户数据，但初始化记录不完整。请恢复原数据或改用空数据库。');
        if ($state === 'empty') {
            $seed = $db->prepare(app_db_upsert_sql('app_groups', ['id', 'name', 'allow_manage', 'allow_admin'], ['id']));
            $seed->execute([1, '管理员', 1, 1]); $seed->execute([2, '会员', 0, 0]);
            $seed = $db->prepare(app_db_upsert_sql('app_forums', ['id', 'name', 'description', 'sort'], ['id']));
            $seed->execute([1, self::bootstrap_env('FORUM_NAME') ?: '默认版块', '欢迎发帖', 0]);
            $settings = default_settings();
            $settings['site_name'] = self::bootstrap_env('SITE_NAME') ?: 'FORUM';
            $settings['software_terms_version'] = self::TERMS_VERSION;
            $settings['software_terms_accepted_at'] = (string)now();
            $stmt = $db->prepare(app_db_upsert_sql('app_settings', ['name', 'value'], ['name']));
            foreach ($settings as $name => $value) $stmt->execute([$name, $value]);
            $welcome_ts = now();
            $db->prepare("INSERT INTO app_users(username,password,email,bio,avatar_style,avatar_seed,group_id,last_post_at,created_at) VALUES(?,?,?,?,?,?,?,?,?)")->execute([
                self::bootstrap_env('ADMIN_USERNAME') ?: 'admin',
                password_hash(self::bootstrap_admin_password(), PASSWORD_DEFAULT),
                self::bootstrap_env('ADMIN_EMAIL') ?: 'admin@example.com',
                '站点管理员', '', '', 1, $welcome_ts, $welcome_ts,
            ]);
            error_log('[bbs1org] 初始化完成：已创建默认版块和管理员账号。');
        } else {
            error_log('[bbs1org] 检测到已有数据，已补写初始化标记。');
        }
        if (file_put_contents(INSTALL_LOCK_FILE, (string)now(), LOCK_EX) === false) self::bootstrap_fail('初始化标记写入失败，请检查 app/data/ 目录权限。');
        forums_cache(true);
        groups_cache(true);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

public static function migrate_identifier(string $name, string $context = ''): string
{
    if ($name === '' || str_contains($name, "\0")) {
        $reason = $name === '' ? '为空' : '包含 NUL 字符';
        $detail = '数据库标识符无效：' . $reason . '；原始值=' . var_export($name, true);
        if ($context !== '') $detail .= '；位置=' . $context;
        throw new InvalidArgumentException($detail . '。');
    }
    return '"' . str_replace('"', '""', $name) . '"';
}

public static function migrate_source_config(): array
{
    $path = trim((string)($_POST['source_sqlite'] ?? ''));
    if ($path === '') throw new RuntimeException('SQLite 文件路径不能为空。');
    if ($path[0] !== DIRECTORY_SEPARATOR) $path = APP_ROOT . '/' . $path;
    return ['driver' => 'sqlite', 'database' => basename($path), 'path' => $path];
}

public static function migrate_source_db(array $config): PDO
{
    if (!is_file((string)$config['path'])) throw new RuntimeException('SQLite 文件不存在：' . $config['path']);
    return app_db_connect($config);
}

public static function migrate_tables(PDO $db): array
{
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

public static function migrate_columns(PDO $db, string $table): array
{
    $rows = $db->query('PRAGMA table_info(' . self::migrate_identifier($table, '读取源表字段：' . $table) . ')')->fetchAll();
    return array_values(array_filter(array_map(fn(array $row): string => (string)($row['name'] ?? ''), $rows)));
}

public static function migrate_column_schema(PDO $db, string $table): array
{
    $rows = $db->query('PRAGMA table_info(' . self::migrate_identifier($table, '读取源表结构：' . $table) . ')')->fetchAll();
    return array_map(fn(array $row): array => [
        'name' => (string)$row['name'], 'type' => (string)$row['type'], 'nullable' => !(bool)$row['notnull'],
        'default' => $row['dflt_value'], 'auto' => false, 'pk' => (int)$row['pk'],
    ], $rows);
}

public static function migrate_index_schema(PDO $db, string $table, array $columns): array
{
    $indexes = [];
    $primary = [];
    foreach ($columns as $column) if ($column['pk']) $primary[(int)$column['pk']] = $column['name'];
    if ($primary) {
        ksort($primary);
        $indexes['PRIMARY'] = ['name' => 'PRIMARY', 'unique' => true, 'primary' => true, 'columns' => array_values($primary)];
    }
    foreach ($db->query('PRAGMA index_list(' . self::migrate_identifier($table, '读取源表索引：' . $table) . ')')->fetchAll() as $index) {
        if ((string)$index['origin'] === 'pk') continue;
        $name = (string)$index['name'];
        $items = $db->query('PRAGMA index_info(' . self::migrate_identifier($name, '读取源索引字段：表=' . $table . '，索引=' . $name) . ')')->fetchAll();
        $names = array_values(array_filter(array_map(fn(array $row): string => (string)($row['name'] ?? ''), $items)));
        if ($names) $indexes[$name] = ['name' => $name, 'unique' => (bool)$index['unique'], 'primary' => false, 'columns' => $names];
    }
    return array_values($indexes);
}

public static function migrate_default_sql(PDO $db, mixed $default, bool $expression = false): string
{
    if ($default === null) return '';
    $value = trim((string)$default);
    if ($value === '' || strcasecmp($value, 'NULL') === 0 || str_starts_with($value, 'nextval(')) return '';
    if (is_numeric($value)) $literal = $value;
    elseif (strcasecmp($value, 'true') === 0 || strcasecmp($value, 'false') === 0) $literal = strcasecmp($value, 'true') === 0 ? '1' : '0';
    if (preg_match('/^CURRENT_(?:TIMESTAMP|DATE|TIME)(?:\(\))?$/i', $value)) return ' DEFAULT ' . strtoupper(rtrim($value, '()'));
    if (!isset($literal)) {
        if (strlen($value) >= 2 && (($value[0] === "'" && $value[-1] === "'") || ($value[0] === '"' && $value[-1] === '"'))) $value = str_replace($value[0] . $value[0], $value[0], substr($value, 1, -1));
        $literal = $db->quote($value);
    }
    return ' DEFAULT ' . ($expression ? '(' . $literal . ')' : $literal);
}

public static function migrate_column_type(string $source_type, bool $auto): string
{
    $type = strtolower($source_type);
    if ($auto) return 'INTEGER PRIMARY KEY AUTOINCREMENT';
    if (preg_match('/int|serial|bool/', $type)) return 'INTEGER';
    if (preg_match('/real|float|double|decimal|numeric/', $type)) return 'REAL';
    if (preg_match('/blob|binary|bytea/', $type)) return 'BLOB';
    return 'TEXT';
}

public static function migrate_index_name(PDO $db, string $table, string $name, array $columns, bool $unique): string
{
    if ($name === 'PRIMARY' || str_starts_with($name, 'sqlite_autoindex_') || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) $name = ($unique ? 'uidx_' : 'idx_') . $table . '_' . implode('_', $columns);
    $name = strlen($name) > 55 ? substr($name, 0, 44) . '_' . substr(hash('sha256', $name), 0, 10) : $name;
    if (!app_db_index_exists($db, $name, $table)) return $name;
    return substr($name, 0, 44) . '_' . substr(hash('sha256', $table . ':' . $name), 0, 10);
}

public static function migrate_install_table(PDO $source, PDO $target, string $source_table, string $target_table): void
{
    $columns = self::migrate_column_schema($source, $source_table);
    $indexes = self::migrate_index_schema($source, $source_table, $columns);
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
        $type = self::migrate_column_type($column['type'], $auto);
        $definition = self::migrate_identifier((string)$column['name'], '创建目标表字段：表=' . $target_table . '，字段=' . (string)$column['name']) . ' ' . $type;
        if (!$auto) $definition .= (!$column['nullable'] ? ' NOT NULL' : '') . self::migrate_default_sql($target, $column['default']);
        $definitions[] = $definition;
    }
    if ($primary && !$auto_primary) $definitions[] = 'PRIMARY KEY(' . implode(',', array_map(fn(string $name): string => self::migrate_identifier($name, '创建目标表主键：表=' . $target_table . '，字段=' . $name), $primary)) . ')';
    $sql = 'CREATE TABLE ' . self::migrate_identifier($target_table, '创建目标表：源表=' . $source_table . '，目标表=' . $target_table) . '(' . implode(',', $definitions) . ')';
    $target->exec($sql);
    foreach ($indexes as $index) {
        if ($index['primary'] || !$index['columns']) continue;
        $name = self::migrate_index_name($target, $target_table, $index['name'], $index['columns'], $index['unique']);
        $target->exec('CREATE ' . ($index['unique'] ? 'UNIQUE ' : '') . 'INDEX ' . self::migrate_identifier($name, '创建目标索引：表=' . $target_table . '，索引=' . $name) . ' ON ' . self::migrate_identifier($target_table, '创建目标索引所属表：' . $target_table) . '(' . implode(',', array_map(fn(string $column): string => self::migrate_identifier($column, '创建目标索引字段：表=' . $target_table . '，索引=' . $name . '，字段=' . $column), $index['columns'])) . ')');
    }
}

public static function migrate_same_database(array $source, array $target): bool
{
    $source_identity = (string)realpath((string)$source['path']);
    $target_identity = (string)realpath((string)$target['path']);
    return $source_identity !== '' && hash_equals($source_identity, $target_identity);
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

public static function migrate_run(PDO $source, array $source_config): array
{
    $target = db();
    $target_config = db_config();
    if (self::migrate_same_database($source_config, $target_config)) throw new RuntimeException('源数据库不能与当前数据库相同。');
    [$schema_tables] = self::app_db_schema();
    $source_tables = [];
    foreach (array_keys($schema_tables) as $target_table) {
        $source_candidates = [$target_table];
        foreach (self::migrate_core_table_map() as $source_table => $mapped_table) if ($mapped_table === $target_table) $source_candidates[] = $source_table;
        foreach (array_unique($source_candidates) as $source_table) if (app_db_table_exists($source, $source_table)) $source_tables[] = $source_table;
    }
    $tables = self::migrate_order_tables($source_tables);
    if (!$tables) throw new RuntimeException('源数据库没有可迁入的系统数据表。');
    $target_tables = [];
    foreach (array_keys($schema_tables) as $table) if (app_db_table_exists($target, $table)) $target_tables[$table] = true;
    $mapped_tables = [];
    foreach ($tables as $source_table) {
        $target_table = self::migrate_target_table($source_table);
        if (isset($mapped_tables[$target_table])) throw new RuntimeException('多个源表映射到同一目标表：' . $mapped_tables[$target_table] . '、' . $source_table);
        $mapped_tables[$target_table] = $source_table;
        if (isset($target_tables[$target_table])) continue;
        self::migrate_install_table($source, $target, $source_table, $target_table);
        $target_tables[$target_table] = true;
    }
    $plans = [];
    foreach ($tables as $source_table) {
        $target_table = self::migrate_target_table($source_table);
        $columns = array_values(array_intersect(self::migrate_columns($target, $target_table), self::migrate_columns($source, $source_table)));
        if ($columns) $plans[] = ['source' => $source_table, 'target' => $target_table, 'columns' => $columns];
    }
    if (!$plans) throw new RuntimeException('没有找到兼容的数据字段。');
    $counts = [];
    $source->beginTransaction();
    $target->beginTransaction();
    try {
        foreach (array_reverse($plans) as $plan) $target->exec('DELETE FROM ' . self::migrate_identifier($plan['target'], '清空目标表：' . $plan['target']));
        foreach ($plans as $plan) {
            $source_table = $plan['source'];
            $target_table = $plan['target'];
            $columns = $plan['columns'];
            $source_columns = implode(',', array_map(fn(string $name): string => self::migrate_identifier($name, '读取源字段：表=' . $source_table . '，字段=' . $name), $columns));
            $target_columns = implode(',', array_map(fn(string $name): string => self::migrate_identifier($name, '写入目标字段：表=' . $target_table . '，字段=' . $name), $columns));
            $order = in_array('id', $columns, true) ? ' ORDER BY ' . self::migrate_identifier('id', '读取源表排序字段：' . $source_table) : '';
            $read = $source->query('SELECT ' . $source_columns . ' FROM ' . self::migrate_identifier($source_table, '读取源表：' . $source_table) . $order);
            $insert = 'INSERT INTO ' . self::migrate_identifier($target_table, '写入目标表：源表=' . $source_table . '，目标表=' . $target_table) . '(' . $target_columns . ') VALUES(' . sql_marks(count($columns)) . ') ON CONFLICT DO NOTHING';
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
    $target_label = 'SQLite / ' . basename((string)$target['path']);
    if (is_array($counts)) {
        $rows = '';
        foreach ($counts as $table => $count) $rows .= '<div>' . h($table) . '</div><div>' . (int)$count . '</div>';
        self::migrate_refresh_caches();
        self::setup_html('迁入完成', '<div class="hero"><h1>迁入完成</h1><p>旧数据库数据已写入当前数据库。</p></div><div class="card"><div class="hd"><h2>迁入结果</h2></div><div class="bd"><div class="note ok">共迁入 ' . array_sum($counts) . ' 条数据。</div><div style="height:14px"></div><div class="kv"><div>目标数据库</div><div class="mono">' . h($target_label) . '</div>' . $rows . '</div><div style="height:14px"></div><div class="actions"><a class="btn" href="' . h(route_url('home')) . '">进入首页</a></div></div></div>');
    }
    $v = fn(string $name, string $default = ''): string => (string)($_POST[$name] ?? $default);
    $message = $error !== '' ? '<div class="note warn">' . h($error) . '</div>' : '';
    $sqlite_fields = '<div class="db-fields" id="sqlite-fields"><div class="row"><label>SQLite 文件路径</label><input type="text" name="source_sqlite" value="' . h($v('source_sqlite', 'app/data/old.sqlite')) . '"></div></div>';
    $form = '<form class="form" method="post" action="' . h(route_url('migrate')) . '" autocomplete="off">' . form_token() . $sqlite_fields . '<div class="checks"><label class="check"><input type="checkbox" name="confirm_replace" value="1" required><span>确认清空当前数据库中的同名数据表。</span></label></div>' . self::terms_consent('migrate') . '<div class="actions"><button class="btn" type="submit">开始迁入</button></div></form>';
    $body = '<div class="hero"><h1>数据迁入</h1><p>从旧 SQLite 数据库迁入当前已安装数据库。</p></div>' . $message . '<div class="grid"><section class="card"><div class="hd"><h2>旧数据库配置</h2></div><div class="bd">' . $form . '</div></section><aside class="card"><div class="hd"><h2>迁入说明</h2></div><div class="bd"><ul class="list"><li>目标数据库：' . h($target_label) . '</li><li>仅迁入系统定义的数据表</li><li>其他表不读取、不创建、不修改</li><li>缺少的数据表会自动创建</li><li>同名数据表将清空后替换</li><li>附件、头像文件需单独复制</li></ul></div></aside></div>';
    self::setup_html('数据迁入', $body);
}
}
