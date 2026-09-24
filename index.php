<?php
declare(strict_types=1);
use app\optional\Admin;
use app\optional\Search;
use app\optional\Setup;
if (!is_file(__DIR__ . '/vendor/autoload.php')) {
    header('Content-Type: text/plain; charset=utf-8');
    exit("缺少 Twig 依赖：请先在项目根目录执行 composer install 后再运行本程序。\n");
}
require __DIR__ . '/vendor/autoload.php';
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
date_default_timezone_set('Asia/Shanghai');
require __DIR__ . '/app/version.php';
define('APP_ROOT', __DIR__);
define('APP_DIR', APP_ROOT . '/app');
define('DATA_DIR', APP_DIR . '/data');
define('TEMPLATE_DIR', APP_ROOT . '/templates');
define('DB_CONFIG_FILE', DATA_DIR . '/db.php');
define('INSTALL_LOCK_FILE', DATA_DIR . '/install.lock');
define('DEBUG_LOG_FILE', DATA_DIR . '/debug.log');
define('DEBUG_LOG_DEDUP_SECONDS', 600);
define('PASSWORD_MIN_LENGTH', 4);
define('DB_STRING_MAX_LENGTH', 255);
define('DB_TEXT_MAX_LENGTH', 4294967295);
define('COOKIE_TTL', 15552000);
define('AUTH_COOKIE_NAME', 'bbs_auth');
define('AUTH_COOKIE_TTL', COOKIE_TTL);
define('CSRF_COOKIE_NAME', 'bbs_csrf');
spl_autoload_register(static function (string $class_name): void {
    $class_file = APP_ROOT . '/' . str_replace('\\', '/', $class_name) . '.php';
    if (is_file($class_file)) require_once $class_file;
});
function app_db_config(string $file, string $data_dir): array
{
    $config = is_file($file) ? include $file : [];
    $config = is_array($config) ? $config : [];
    $name = basename((string)($config['database'] ?? $config['db_file'] ?? 'forum.sqlite'));
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.sqlite$/', $name)) $name = 'forum.sqlite';
    return ['driver' => 'sqlite', 'database' => $name, 'path' => $data_dir . '/' . $name];
}
function app_db_connect(array $config): PDO
{
    if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('当前 PHP 环境缺少 PDO sqlite 驱动。');
    }
    $dir = dirname((string)$config['path']);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $db = new PDO('sqlite:' . $config['path'], null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    foreach (['PRAGMA journal_mode=WAL', 'PRAGMA synchronous=NORMAL', 'PRAGMA temp_store=MEMORY', 'PRAGMA busy_timeout=5000', 'PRAGMA foreign_keys=ON'] as $sql) $db->exec($sql);
    return $db;
}
function app_db_identifier(string $name): string
{
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) throw new InvalidArgumentException('无效的数据库标识符。');
    return '"' . $name . '"';
}
function sql_marks(int $count): string
{
    return implode(',', array_fill(0, $count, '?'));
}
function app_db_types(): array
{
    return [
        'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        'uint' => 'INTEGER',
        'key' => 'TEXT',
        'string' => 'TEXT',
        'text' => 'TEXT',
    ];
}
function app_db_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}
function app_db_index_exists(PDO $db, string $index, string $table = ''): bool
{
    $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='index' AND name=?" . ($table !== '' ? ' AND tbl_name=?' : ''));
    $stmt->execute($table !== '' ? [$index, $table] : [$index]);
    return (bool)$stmt->fetchColumn();
}
function app_db_upsert_sql(string $table, array $columns, array $keys): string
{
    $marks = sql_marks(count($columns));
    $base = 'INSERT INTO ' . app_db_identifier($table) . '(' . implode(',', $columns) . ') VALUES(' . $marks . ')';
    $updates = array_values(array_diff($columns, $keys));
    if (!$updates) return $base . ' ON CONFLICT(' . implode(',', $keys) . ') DO NOTHING';
    return $base . ' ON CONFLICT(' . implode(',', $keys) . ') DO UPDATE SET ' . implode(',', array_map(fn($c) => $c . '=excluded.' . $c, $updates));
}
function app_db_last_insert_id(string $table): int
{
    return (int)db()->lastInsertId();
}
function db_config(): array
{
    static $config;
    return $config ??= app_db_config(DB_CONFIG_FILE, DATA_DIR);
}
function db(): PDO
{
    static $db;
    if ($db) return $db;
    return $db = app_db_connect(db_config());
}
function h(string|int|float|bool|null $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function sql_query_count(bool $increment = false): int
{
    static $count = 0;
    if ($increment) $count++;
    return $count;
}
function db_row_cache_clear(): void
{
    $GLOBALS['__db_row_cache'] = [];
}
function row(string $table, string $key, mixed $value): ?array
{
    $cache =& $GLOBALS['__db_row_cache'];
    if (!is_array($cache)) $cache = [];
    $cache_key = $table . "\0" . $key . "\0" . serialize($value);
    if (!array_key_exists($cache_key, $cache)) {
        $table = app_db_identifier($table);
        $key = app_db_identifier($key);
        $cache[$cache_key] = one("SELECT * FROM $table WHERE $key=?", [$value]) ?: false;
    }
    return $cache[$cache_key] ?: null;
}
function q(string $sql, array $p = []): PDOStatement
{
    if (strncasecmp(ltrim($sql), 'SELECT', 6) !== 0) db_row_cache_clear();
    sql_query_count(true);
    $s = db()->prepare($sql);
    $s->execute($p);
    $GLOBALS['__sql_queries'][] = [$sql, $p];
    return $s;
}
function one(string $sql, array $p = []): ?array
{
    $r = q($sql, $p)->fetch();
    return $r ?: null;
}
function val(string $sql, array $p = []): mixed
{
    return q($sql, $p)->fetchColumn();
}
function tx(callable $fn): mixed
{
    $db = db();
    if ($db->inTransaction()) return $fn();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $result = $fn();
        $db->exec('COMMIT');
        return $result;
    } catch (Throwable $e) {
        try { $db->exec('ROLLBACK'); } catch (Throwable) {}
        db_row_cache_clear();
        throw $e;
    }
}
function auth_cookie_secure(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}
function app_cookie(string $name, string $value, int $expires, bool $httponly = true, bool $secure = true): void
{
    setcookie($name, $value, ['expires' => $expires, 'path' => '/', 'secure' => $secure && auth_cookie_secure(), 'httponly' => $httponly, 'samesite' => 'Lax']);
}
function auth_cookie_clear(): void
{
    app_cookie(AUTH_COOKIE_NAME, '', time() - 3600);
    unset($_COOKIE[AUTH_COOKIE_NAME]);
}
function csrf_cookie_clear(): void
{
    app_cookie(CSRF_COOKIE_NAME, '', time() - 3600);
    unset($_COOKIE[CSRF_COOKIE_NAME]);
}
function auth_cookie_set(int $user_id, string $password_hash): void
{
    $expire = time() + AUTH_COOKIE_TTL;
    $payload = $user_id . '|' . $expire;
    $signature = hash_hmac('sha256', $payload, $password_hash);
    app_cookie(AUTH_COOKIE_NAME, $user_id . '.' . $expire . '.' . $signature, $expire);
}
function auth_cookie_parts(): ?array
{
    $value = (string)($_COOKIE[AUTH_COOKIE_NAME] ?? '');
    if (!preg_match('/^(\d+)\.(\d+)\.([a-f0-9]{64})$/D', $value, $m)) return null;
    $id = (int)$m[1];
    $expire = (int)$m[2];
    return $id > 0 && $expire > 0 ? ['id' => $id, 'expire' => $expire, 'signature' => $m[3]] : null;
}
function csrf_token(): string
{
    static $token = null;
    if ($token !== null) return $token;
    $token = (string)($_COOKIE[CSRF_COOKIE_NAME] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
        $token = bin2hex(random_bytes(32));
        app_cookie(CSRF_COOKIE_NAME, $token, time() + COOKIE_TTL);
        $_COOKIE[CSRF_COOKIE_NAME] = $token;
    }
    return $token;
}
function rows_by_ids(string $table, array $ids, string $cols = '*'): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    if (in_array($table, ['app_users', 'app_topics', 'app_replies'], true) && count($ids) === 1) {
        $row = row($table, 'id', $ids[0]);
        if (!$row) return [];
        return [$ids[0] => $row];
    }
    $marks = sql_marks(count($ids));
    $rows = q("SELECT $cols FROM $table WHERE id IN ($marks)", $ids)->fetchAll();
    $map = [];
    foreach ($rows as $row) $map[(int)$row['id']] = $row;
    return $map;
}
function user_summary_defaults(string $username = ''): array
{
    return ['username' => $username, 'group_id' => 0, 'is_muted' => 0];
}
function attach_users(array $rows, string $key = 'user_id', string $fallback = '用户删除'): array
{
    $users = rows_by_ids('app_users', array_column($rows, $key), 'id,username,group_id,is_muted');
    foreach ($rows as &$row) $row += ($users[(int)($row[$key] ?? 0)] ?? user_summary_defaults($fallback));
    unset($row);
    return $rows;
}
function attach_topic_list_users(array $rows): array
{
    $user_ids = array_merge(array_column($rows, 'user_id'), array_column($rows, 'last_reply_user_id'));
    $users = rows_by_ids('app_users', $user_ids, 'id,username,group_id,is_muted');
    foreach ($rows as &$row) {
        $row += ($users[(int)($row['user_id'] ?? 0)] ?? user_summary_defaults());
        $last_reply_uid = (int)($row['last_reply_user_id'] ?? 0);
        $row['last_reply_username'] = $last_reply_uid > 0 ? (string)($users[$last_reply_uid]['username'] ?? '') : '';
    }
    unset($row);
    return $rows;
}
function db_schema_ready(): bool
{
    return is_file(INSTALL_LOCK_FILE);
}
function default_settings(): array
{
    return [
        'site_name' => 'FORUM',
        'allow_register' => '1',
        'default_group_id' => '2',
        'pc_nav_forum_count' => '6',
        'topics_per_page' => '30',
        'replies_per_page' => '50',
        'max_pagination_pages' => '50',
        'username_min_length' => '2', 'username_max_length' => '20',
        'email_min_length' => '7', 'email_max_length' => '150',
        'bio_min_length' => '0', 'bio_max_length' => '10000',
        'search_min_length' => '2', 'search_max_length' => '80',
        'title_min_length' => '1', 'title_max_length' => (string)DB_STRING_MAX_LENGTH,
        'topic_body_min_length' => '1', 'topic_body_max_length' => (string)DB_TEXT_MAX_LENGTH,
        'reply_body_min_length' => '1', 'reply_body_max_length' => (string)DB_TEXT_MAX_LENGTH,
        'excerpt_length' => '200',
        'post_interval_seconds' => '5',
    ];
}
function settings_cache(): array
{
    return $GLOBALS['__settings_cache'] ??= array_merge(default_settings(), array_column(q("SELECT name,value FROM app_settings")->fetchAll(), 'value', 'name'));
}
function setting(string $key, string $default = ''): string
{
    $settings = settings_cache();
    return (string)($settings[$key] ?? $default);
}
function length_limit(string $type, string $bound): int
{
    $hard_max = in_array($type, ['username', 'email', 'search', 'title'], true) ? DB_STRING_MAX_LENGTH : DB_TEXT_MAX_LENGTH;
    $key = $bound === 'value' ? $type . '_length' : $type . '_' . $bound . '_length';
    $value = min($hard_max, max(0, (int)setting($key, '0')));
    if ($bound === 'max') $value = max($value, min($hard_max, max(0, (int)setting($type . '_min_length', '0'))));
    return $value;
}
function excerpt_length(): int { return length_limit('excerpt', 'value'); }
function require_length(string $value, int $minimum, string $label): void
{
    if ($minimum > 0 && preg_match_all('/./us', $value) < $minimum) err($label . '至少' . $minimum . '个字符');
}
function save_settings_values(array $values): void
{
    $stmt = db()->prepare(app_db_upsert_sql('app_settings', ['name', 'value'], ['name']));
    foreach ($values as $name => $value) $stmt->execute([$name, $value]);
    db_row_cache_clear();
    if (is_array($GLOBALS['__settings_cache'] ?? null)) {
        foreach ($values as $name => $value) $GLOBALS['__settings_cache'][(string)$name] = (string)$value;
    }
}
function settings_rows_cache(string $key, string $sql, bool $refresh): array
{
    $rows = $refresh ? null : json_decode(setting($key), true);
    if (is_array($rows)) return $rows;
    $rows = q($sql)->fetchAll();
    save_settings_values([$key => json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    return $rows;
}
function exception_detail(Throwable $e): string
{
    $parts = [];
    do {
        $parts[] = get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString();
        $e = $e->getPrevious();
    } while ($e);
    return implode("\n\nPrevious:\n", $parts);
}
function debug_mode_enabled(): bool
{
    try {
        return db_schema_ready() && setting('debug_mode', '0') === '1';
    } catch (Throwable $e) {
        return false;
    }
}
function debug_log_write(string $message, ?Throwable $e = null): void
{
    if (!debug_mode_enabled()) return;
    Setup::debug_log_write($message, $e);
}
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() === 0) return false;
    debug_log_write('PHP error [' . $severity . '] ' . $message . "\n" . $file . ':' . $line);
    return false;
});
register_shutdown_function(function (): void {
    $error = error_get_last();
    if (!$error || !in_array((int)$error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    debug_log_write('PHP fatal [' . (int)$error['type'] . '] ' . (string)$error['message'] . "\n" . (string)$error['file'] . ':' . (int)$error['line']);
});
function pinned_topic_ids(): array
{
    return array_values(array_unique(array_filter(array_map('intval', preg_split('/\s*,\s*/', setting('pinned_topic_ids'), -1, PREG_SPLIT_NO_EMPTY) ?: []))));
}
function set_pinned_topic(int $tid, bool $pin): void
{
    $ids = pinned_topic_ids();
    $ids = $pin ? array_values(array_unique(array_merge([$tid], $ids))) : array_values(array_diff($ids, [$tid]));
    save_settings_values(['pinned_topic_ids' => implode(',', $ids)]);
}
function clean_ip(string $value): string
{
    $value = trim($value, " \t\n\r\0\x0B\"'");
    if ($value === '') return '';
    if (in_array(strtolower($value), ['unknown', 'null', 'undefined'], true)) return '';
    if (($p = strpos($value, ';')) !== false) $value = substr($value, 0, $p);
    if (stripos($value, 'for=') === 0) $value = substr($value, 4);
    $value = trim($value, " \t\n\r\0\x0B\"'");
    if (str_starts_with($value, '[')) {
        $end = strpos($value, ']');
        if ($end === false) return '';
        $port = substr($value, $end + 1);
        if ($port !== '' && !preg_match('/^:\d+$/D', $port)) return '';
        $value = substr($value, 1, $end - 1);
    } elseif (substr_count($value, ':') === 1) {
        [$host, $port] = explode(':', $value, 2);
        if ($port !== '' && ctype_digit($port)) $value = $host;
    }
    if (($p = strpos($value, '%')) !== false) $value = substr($value, 0, $p);
    $packed = inet_pton($value);
    if ($packed === false) return '';
    $normalized = inet_ntop($packed);
    return is_string($normalized) ? strtolower($normalized) : '';
}
function ip_addr(): string
{
    foreach (['HTTP_CLIENT_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'] as $key) {
        foreach (explode(',', (string)($_SERVER[$key] ?? '')) as $value) {
            $ip = clean_ip($value);
            if ($ip !== '') return $ip;
        }
    }
    return '0.0.0.0';
}
function post_interval_seconds(): int
{
    return min(3600, max(0, (int)setting('post_interval_seconds', '5')));
}
function check_post_interval(): void
{
    $seconds = post_interval_seconds();
    if ($seconds <= 0 || !uid()) return;
    $wait = $seconds - (time() - (int)(row('app_users', 'id', uid())['last_post_at'] ?? 0));
    if ($wait > 0) err('操作太频繁，请 ' . $wait . ' 秒后再试');
}
function clean_site_base_url(string $url): string
{
    $url = rtrim(trim($url), '/');
    if ($url === '') return '';
    $parts = parse_url($url);
    if (!is_array($parts)) return '';
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = (string)($parts['host'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') return '';
    return $url;
}
function forums_cache(bool $refresh = false): array
{
    if ($refresh) unset($GLOBALS['__forums_cache'], $GLOBALS['__forum_by_id_map']);
    return $GLOBALS['__forums_cache'] ??= settings_rows_cache('cache_forums', "SELECT id,name,description,sort,allow_view_groups,allow_post_groups,allow_reply_groups FROM app_forums ORDER BY sort,id", $refresh);
}
function forum_by_id(int $id): ?array
{
    if (!is_array($GLOBALS['__forum_by_id_map'] ?? null)) {
        $GLOBALS['__forum_by_id_map'] = [];
        foreach (forums_cache() as $forum) $GLOBALS['__forum_by_id_map'][(int)$forum['id']] = $forum;
    }
    return $GLOBALS['__forum_by_id_map'][$id] ?? null;
}
function forum_group_ids(array $forum, string $field): array
{
    $raw = trim((string)($forum[$field] ?? ''));
    if ($raw === '') return [];
    $ids = array_values(array_unique(array_filter(array_map('intval', preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: []))));
    return $ids;
}
function forum_group_allowed(?array $forum, string $field): bool
{
    if (!$forum) return false;
    $ids = forum_group_ids($forum, $field);
    if (!$ids) return true;
    $me = me();
    $gid = (int)($me['group_id'] ?? 0);
    if (in_array($gid, $ids, true)) return true;
    return false;
}
function groups_cache(bool $refresh = false): array
{
    if ($refresh) unset($GLOBALS['__groups_cache'], $GLOBALS['__group_by_id_map']);
    return $GLOBALS['__groups_cache'] ??= settings_rows_cache('cache_groups', "SELECT id,name,allow_manage,allow_admin FROM app_groups ORDER BY id", $refresh);
}
function group_by_id(int $id): ?array
{
    if (!is_array($GLOBALS['__group_by_id_map'] ?? null)) {
        $GLOBALS['__group_by_id_map'] = [];
        foreach (groups_cache() as $group) $GLOBALS['__group_by_id_map'][(int)$group['id']] = $group;
    }
    return $GLOBALS['__group_by_id_map'][$id] ?? null;
}
function notification_badge_html(int $count): string
{
    return $count > 0 ? '<span class="notify-badge">' . (int)$count . '</span>' : '';
}
function content_excerpt(string $body, ?int $max = null): string
{
    $body = trim(preg_replace('/\s+/u', ' ', $body) ?? '');
    return cut($body, $max ?? excerpt_length());
}
function content_preview_source_text(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $lines = [];
    $in_code = false;
    foreach (explode("\n", $body) as $line) {
        if (preg_match('/^\s*```\s*[\w-]*\s*$/u', $line)) {
            $in_code = !$in_code;
            continue;
        }
        if ($in_code) continue;
        $lines[] = $line;
    }
    return implode("\n", $lines);
}
function notification_excerpt(string $body, ?int $max = null): string
{
    $body = preg_replace('/^\s*>.*(?:\n|$)/mu', '', $body) ?? $body;
    return content_excerpt($body, $max);
}
function notification_targets(string $body): array
{
    if (!preg_match_all('/@([^\s@,，。！？!?；;:：<>]+)/u', $body, $m)) return [];
    $targets = [];
    foreach ($m[1] as $name) {
        $name = trim((string)$name);
        if ($name !== '') $targets[$name] = true;
    }
    return array_keys($targets);
}
function create_notification(int $recipient_id, int $sender_id, string $kind, string $content, int $topic_id = 0, int $reply_id = 0): bool
{
    $content = trim($content);
    if ($recipient_id <= 0 || $content === '') return false;
    if ($recipient_id === $sender_id) return false;
    $topic_id = $topic_id > 0 ? $topic_id : null;
    $reply_id = $reply_id > 0 ? $reply_id : null;
    $created_at = now();
    q("INSERT INTO app_notifications(recipient_id,sender_id,kind,content,topic_id,reply_id,created_at,read_at) VALUES(?,?,?,?,?,?,?,0)", [$recipient_id, $sender_id, $kind, $content, $topic_id, $reply_id, $created_at]);
    $notification_id = app_db_last_insert_id('app_notifications');
    q("UPDATE app_users SET unread_notifications=COALESCE(unread_notifications,0)+1 WHERE id=?", [$recipient_id]);
    return true;
}
function create_mention_notifications(int $topic_id, int $reply_id, string $body, int $sender_id): void
{
    $topic = row('app_topics', 'id', $topic_id);
    if (!$topic) return;
    $usernames = notification_targets($body);
    $targets = [];
    if ($usernames) {
        $marks = sql_marks(count($usernames));
        $users = q("SELECT id FROM app_users WHERE username IN ($marks)", $usernames)->fetchAll();
        foreach ($users as $user) $targets[(int)$user['id']] = true;
    }
    unset($targets[$sender_id]);
    $excerpt = notification_excerpt($body);
    foreach (array_keys($targets) as $uid) {
        create_notification((int)$uid, $sender_id, 'mention', '在主题《' . (string)$topic['title'] . '》中提到你：' . $excerpt, $topic_id, $reply_id);
    }
}
function create_topic_notifications(int $topic_id, string $body, int $sender_id): void
{
    create_mention_notifications($topic_id, 0, $body, $sender_id);
}
function create_reply_notifications(int $topic_id, int $reply_id, string $body, int $sender_id): void
{
    create_mention_notifications($topic_id, $reply_id, $body, $sender_id);
}
function notifications_list(int $uid, int $limit, int $offset = 0): array
{
    $rows = q("SELECT * FROM app_notifications WHERE recipient_id=? ORDER BY created_at DESC,id DESC LIMIT ? OFFSET ?", [$uid, $limit, $offset])->fetchAll();
    $users = rows_by_ids('app_users', array_column($rows, 'sender_id'), 'id,username');
    foreach ($rows as &$row) {
        $u = $users[(int)($row['sender_id'] ?? 0)] ?? null;
        $row['sender_username'] = (string)($u['username'] ?? '');
    }
    unset($row);
    return $rows;
}
function notifications_total(int $uid): int
{
    return (int)val('SELECT COUNT(*) FROM app_notifications WHERE recipient_id=?', [$uid]);
}
function notifications_unread_total(int $uid): int
{
    $m = me();
    if ($m && (int)$m['id'] === $uid) return (int)($m['unread_notifications'] ?? 0);
    return (int)val('SELECT COUNT(*) FROM app_notifications WHERE recipient_id=? AND read_at=0', [$uid]);
}
function mark_notifications_read(int $uid, int $unread): void
{
    if ($unread <= 0) return;
    q("UPDATE app_notifications SET read_at=? WHERE recipient_id=? AND read_at=0", [now(), $uid]);
    q("UPDATE app_users SET unread_notifications=0 WHERE id=?", [$uid]);
    if (is_array($GLOBALS['__me_cache'] ?? null) && (int)$GLOBALS['__me_cache']['id'] === $uid) $GLOBALS['__me_cache']['unread_notifications'] = 0;
}
function notification_link(array $n): string
{
    if ((int)($n['topic_id'] ?? 0) > 0) {
        return route_url('topic', ['id' => (int)$n['topic_id'], 'replyid' => (int)($n['reply_id'] ?? 0) ?: null]);
    }
    if ((int)($n['sender_id'] ?? 0) > 0) return route_url('user', ['id' => (int)$n['sender_id']]);
    return route_url('home');
}
function notification_row_html(array $n): string
{
    $sender_id = (int)($n['sender_id'] ?? 0);
    $sender_name = trim((string)($n['sender_username'] ?? '')) ?: '系统';
    $body = (string)($n['content'] ?? '');
    $content_html = markdown_html($body);
    if ((int)($n['topic_id'] ?? 0) > 0 || (int)($n['reply_id'] ?? 0) > 0) {
        $url = notification_link($n);
        if ($url !== '') {
            $title_marker = "\x1ENOTIFICATION_TOPIC_TITLE\x1E";
            $topic_title = '';
            $source = preg_replace_callback('/《((?:[^《》]|(?R))*)》/u', static function (array $match) use (&$topic_title, $title_marker): string {
                $topic_title = $match[1];
                return '《' . $title_marker . '》';
            }, $body, 1, $count);
            if (is_string($source) && $count > 0) {
                // Render the title as one link so mentions in it cannot create nested anchors.
                $content_html = str_replace($title_marker, '<a href="' . h($url) . '">' . h($topic_title) . '</a>', markdown_html($source));
            } else {
                $view_link = '<a href="' . h($url) . '">查看主题</a>';
                $content_html = preg_replace('/<\/p>\s*$/u', ' ' . $view_link . '</p>', $content_html, 1, $paragraph_count) ?? $content_html;
                if ($paragraph_count < 1) $content_html .= ' ' . $view_link;
            }
        }
    }
    $kind = match ((string)($n['kind'] ?? '')) { 'mention' => '提及', default => '通知' };
    $unread = (int)($n['read_at'] ?? 0) === 0;
    $sender_title = $sender_id > 0 ? '<a class="post-title" href="' . h(route_url('user', ['id' => $sender_id])) . '">' . h($sender_name) . '</a>' : '<span class="post-title">' . h($sender_name) . '</span>';
    return '<li class="post-item notification-item' . ($unread ? ' unread' : '') . '"><div class="post-avatar">' . avatar_link_tag($sender_id ?: 0, $sender_name) . '</div><div class="post-body"><div class="post-title-row notification-head">' . $sender_title . '<span class="post-user-group notification-kind">' . h($kind) . '</span>' . ($unread ? '<span class="notification-unread">未读</span>' : '') . '</div><div class="post-meta"><span>' . human_time((int)$n['created_at']) . '</span></div><div class="post-content notification-content">' . $content_html . '</div></div></li>';
}
function user_state_tag_html(array $u): string
{
    $tags = [];
    if ((int)($u['is_muted'] ?? 0) === 1) $tags[] = '<span class="user-state-tag danger">禁言</span>';
    return $tags ? '<span class="user-state-tags">' . implode('', $tags) . '</span>' : '';
}
function mark_viewed(int $tid): bool
{
    $seen = array_values(array_unique(array_filter(array_map('intval', explode('.', (string)($_COOKIE['__viewed_topics'] ?? ''))))));
    if (in_array($tid, $seen, true)) return false;
    $seen[] = $tid;
    $value = implode('.', array_slice($seen, -64));
    app_cookie('__viewed_topics', $value, time() + COOKIE_TTL, false);
    $_COOKIE['__viewed_topics'] = $value;
    return true;
}
function sidebar_notice_card_html(string $title, array $items): string
{
    $html = '<div class="card sidebar-card quick-card"><div class="quick-wrap"><div class="quick-title">' . h($title) . '</div><ul class="quick-links notice-links">';
    foreach ($items as $item) $html .= '<li>' . h($item) . '</li>';
    return $html . '</ul></div></div>';
}
function mobile_menu_content_html(?array $mine = null, ?array $forums = null): string
{
    $forums ??= array_values(array_filter(forums_cache(), fn($forum) => forum_group_allowed($forum, 'allow_view_groups')));
    $forum_links = [['text' => '全部', 'url' => route_url('home')]];
    foreach ($forums as $f) {
        $forum_links[] = ['text' => (string)$f['name'], 'url' => route_url('forum', ['id' => (int)$f['id']])];
    }
    $my_links = [];
    if ($mine) {
        $uid = (int)$mine['id'];
        $my_links[] = ['text' => '我的主页', 'url' => route_url('user', ['id' => $uid])];
        $my_links[] = ['text' => '我的主题', 'url' => route_url('user', ['id' => $uid, 'tab' => 'topics'])];
        $my_links[] = ['text' => '我的回帖', 'url' => route_url('user', ['id' => $uid, 'tab' => 'replies'])];
        $my_links[] = ['text' => '我的通知', 'url' => route_url('user', ['id' => $uid, 'tab' => 'notifications'])];
        $my_links[] = ['text' => '个人设置', 'url' => route_url('profile')];
        if (can_access_admin()) $my_links[] = ['text' => '后台面板', 'url' => route_url('admin')];
    } else {
        $my_links[] = ['text' => '登录', 'url' => route_url('login')];
        if (setting('allow_register', '1') === '1') $my_links[] = ['text' => '注册', 'url' => route_url('register')];
    }
    return template('mobile_menu.html.twig', ['sections' => [
        ['title' => '版块列表', 'links' => $forum_links],
        ['title' => '我的菜单', 'links' => $my_links],
    ]]);
}
function shell_html(string $main, string $sidebar, string $class = ''): string
{
    $layout_class = 'forum-layout' . ($sidebar !== '' ? ' forum-layout-has-sidebar' : '');
    return '<div class="home-shell' . ($class !== '' ? ' ' . h($class) : '') . '"><div class="' . $layout_class . '"><div class="forum-main"><div class="main-panel" >' . $main . '</div></div>' . $sidebar . '</div></div>';
}
function tab_bar_html(array $items, string $active, string $class = ''): string
{
    $html = '<div class="tab-bar' . ($class !== '' ? ' ' . $class : '') . '">';
    foreach ($items as $key => $item) {
        $label = is_array($item) ? (string)($item['label'] ?? '') : (string)$item;
        $href = is_array($item) ? (string)($item['href'] ?? '#') : '#';
        $extra = is_array($item) ? (string)($item['class'] ?? '') : '';
        $html .= '<a class="tab' . ($active === $key ? ' active' : '') . ($extra !== '' ? ' ' . $extra : '') . '" href="' . h($href) . '">' . $label . '</a>';
    }
    return $html . '</div>';
}
function auth_tabs_html(string $active): string
{
    return tab_bar_html([
        'login' => ['label' => '登录', 'href' => route_url('login')],
        'register' => ['label' => '注册', 'href' => route_url('register')],
    ], $active, 'auth-tabs');
}
function sidebar_stack_html(array $parts): string
{
    $html = '<aside class="sidebar">';
    foreach ($parts as $part) if ($part !== '') $html .= $part;
    return $html . '</aside>';
}
function sidebar_user_card_html(?array $m = null, bool $reply_button = false, int $fid = 0): string
{
    $m = $m ?: me();
    if (!$m) return '<div class="card sidebar-card user-card"><div class="user-wrap"><div class="user-header"><div class="user-header-info"><div class="user-avatar-big visitor-avatar">P</div><div><div class="user-name">访客</div><div class="user-rank">请登录后发帖</div></div></div></div><div class="side-auth' . (setting('allow_register', '1') === '1' ? '' : ' single') . '"><a href="' . h(route_url('login')) . '">登录</a>' . (setting('allow_register', '1') === '1' ? '<a href="' . h(route_url('register')) . '">注册</a>' : '') . '</div></div></div>';
    $is_self = uid() && (int)$m['id'] === uid();
    $prefix = $is_self ? '我的' : 'TA的';
    $unread = $is_self ? (int)($m['unread_notifications'] ?? 0) : 0;
    $links = '<a href="' . h(route_url('user', ['id' => (int)$m['id'], 'tab' => 'topics'])) . '">' . svg_icon('topic') . $prefix . '主题</a><a href="' . h(route_url('user', ['id' => (int)$m['id'], 'tab' => 'replies'])) . '">' . svg_icon('reply') . $prefix . '回帖</a>';
    if ($is_self) $links .= '<a href="' . h(route_url('user', ['id' => (int)$m['id'], 'tab' => 'notifications'])) . '">' . svg_icon('notify') . $prefix . '通知' . notification_badge_html($unread) . '</a><a href="' . h(route_url('profile')) . '">' . svg_icon('settings') . '个人设置</a>' . (can_access_admin() ? '<a href="' . h(route_url('admin')) . '">' . svg_icon('admin') . '后台面板</a>' : '');
    $user_url = route_url('user', ['id' => (int)$m['id']]);
    $rank = h($m['group_name'] ?? '用户');
    $state_tags = user_state_tag_html($m);
    $html = '<div class="card sidebar-card user-card"><div class="user-wrap"><div class="user-header"><div class="user-header-info"><a class="user-avatar-big" href="' . $user_url . '">' . avatar_tag((int)$m['id'], (string)$m['username']) . '</a><div><a class="user-name" href="' . $user_url . '">' . h($m['username']) . '</a><div class="user-rank">' . $rank . $state_tags . '</div></div></div></div><div class="user-links" >' . $links . '</div></div>';
    if (can_speak() && ($is_self || $reply_button)) $html .= '<a class="btn-post" href="' . h($reply_button ? '#reply' : route_url('topic_edit', ['fid' => $fid ?: null])) . '">' . ($reply_button ? '回帖' : '+ 发帖') . '</a>';
    return $html . '</div>';
}
function topic_user_group_html(array $row): string
{
    $gid = (int)($row['group_id'] ?? 0);
    $default_gid = (int)setting('default_group_id', '2');
    if ($gid <= 0 || $gid === $default_gid) return '';
    $g = group_by_id($gid);
    return $g ? '<span class="post-user-group"><span class="post-user-group-icon" aria-hidden="true">' . svg_icon('user') . '</span>' . h($g['name']) . '</span>' : '';
}
function form_shell(string $body, ?array $m = null): string
{
    return shell_html($body, sidebar_stack_html([sidebar_user_card_html($m)]));
}
function now(): int
{
    return time();
}
function uid(): int
{
    if (array_key_exists('__request_uid', $GLOBALS)) return (int)$GLOBALS['__request_uid'];
    $user = me();
    return $user ? (int)$user['id'] : 0;
}
function is_super_user(): bool
{
    return uid() === 1;
}
function clear_auth_cookie(): void
{
    auth_cookie_clear();
    csrf_cookie_clear();
    $GLOBALS['__request_uid'] = 0;
    $GLOBALS['__me_cache'] = null;
}
function me(): ?array
{
    if (array_key_exists('__me_cache', $GLOBALS)) return is_array($GLOBALS['__me_cache']) ? $GLOBALS['__me_cache'] : null;
    $parts = auth_cookie_parts();
    if (!$parts || $parts['expire'] < time()) {
        if ($parts) auth_cookie_clear();
        return $GLOBALS['__me_cache'] = null;
    }
    $u = row('app_users', 'id', $parts['id']);
    $expected = $u ? hash_hmac('sha256', $parts['id'] . '|' . $parts['expire'], (string)$u['password']) : '';
    if (!$u || !hash_equals($expected, $parts['signature'])) {
        clear_auth_cookie();
        return null;
    }
    $g = group_by_id((int)$u['group_id']);
    if (!$g) {
        $fallback_group_id = (int)setting('default_group_id', '2');
        $g = group_by_id($fallback_group_id);
        if (!$g) {
            clear_auth_cookie();
            return null;
        }
        q('UPDATE app_users SET group_id=? WHERE id=? AND group_id=?', [$fallback_group_id, (int)$u['id'], (int)$u['group_id']]);
        $u['group_id'] = $fallback_group_id;
    }
    $GLOBALS['__request_uid'] = (int)$u['id'];
    return $GLOBALS['__me_cache'] = $u + ['group_name' => $g['name'], 'group_id' => (int)($u['group_id'] ?? 0), 'is_muted' => (int)($u['is_muted'] ?? 0), 'allow_manage' => (int)($g['allow_manage'] ?? 0), 'allow_admin' => (int)($g['allow_admin'] ?? 0)];
}
function can_manage(): bool
{
    if (is_super_user()) return true;
    $u = me();
    return $u && (int)($u['allow_manage'] ?? 0) === 1;
}
function can_access_admin(): bool
{
    if (is_super_user()) return true;
    $u = me();
    return $u && (int)($u['allow_admin'] ?? 0) === 1;
}
function is_muted(): bool
{
    if (is_super_user()) return false;
    $u = me();
    return $u && !can_access_admin() && (int)$u['is_muted'] === 1;
}
function can_speak(): bool
{
    if (!uid() || is_muted()) return false;
    return true;
}
function consume_auth_return_url(): string
{
    $url = trim((string)($_COOKIE['__auth_return_url'] ?? ''));
    app_cookie('__auth_return_url', '', time() - 3600);
    if ($url === '' || str_starts_with($url, '//') || str_contains($url, '\\')) return route_url('home');
    if (preg_match('/[\x00-\x1F\x7F]/', $url) || preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) return route_url('home');
    return $url;
}
function start_cookie_login(int $user_id): void
{
    $user = row('app_users', 'id', $user_id) ?: err('用户不存在');
    csrf_cookie_clear();
    auth_cookie_set($user_id, (string)$user['password']);
    $GLOBALS['__request_uid'] = $user_id;
    unset($GLOBALS['__me_cache']);
}
function complete_login(int $user_id): never
{
    start_cookie_login($user_id);
    go(consume_auth_return_url());
}
function need_login(): void
{
    if (!me()) go(route_url('login'));
}
function need_speak(): void
{
    need_login();
    if (is_muted()) err('禁止发言');
}
function need_admin(): void
{
    need_login();
    if (!can_access_admin()) err('无权限');
}
function need_site_access(): void
{
    $a = $_GET['a'] ?? 'home';
    limit_pagination_request_pages();
    if (setting('site_closed') === '1' && !can_access_admin()) {
        if (!in_array($a, ['login', 'logout', 'form_error'], true)) err('网站已关闭');
    }
}
function check(): void
{
    if (uid()) me();
    $is_post = is_post_request();
    $action = (string)($_GET['a'] ?? '');
    if ($is_post && !hash_equals(csrf_token(), (string)($_POST['_csrf'] ?? ''))) {
        err('请求已过期');
    }
}
function ajax_request(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
}
function is_post_request(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}
function json_response(array $data): never
{
    if (!isset($data['tip']) && !empty($GLOBALS['__ajax_tip'])) $data['tip'] = (string)$GLOBALS['__ajax_tip'];
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function set_flash(string $message): void
{
    $_COOKIE['__flash'] = $message;
    app_cookie('__flash', $message, time() + 30, true, false);
}
function err(string $message, int $status = 200, string $mode = 'auto', ?bool $log = null): never
{
    $status = $status > 0 ? $status : 200;
    $is_not_found = $status === 404;
    if ($log === true) debug_log_write($message);
    if ($status !== 200) http_response_code($status);
    if ($mode === 'auto') {
        if (ajax_request()) $mode = 'ajax';
        elseif (!$is_not_found && is_post_request()) $mode = 'form';
        else $mode = 'page';
    }
    if ($mode === 'ajax') {
        json_response(['ok' => 0, 'message' => $message]);
    }
    if ($mode === 'form') {
        $value = base64_encode(json_encode([
            'message' => $message,
            'created_at' => time(),
        ], JSON_UNESCAPED_UNICODE));
        app_cookie('__form_error', $value, time() + 30);
        go(route_url('form_error'));
    }
    error_page($is_not_found ? '404' : '消息', $message, $status);
}
function go(string $u): never
{
    if (ajax_request()) json_response(['ok' => 1, 'redirect' => $u]);
    if (ob_get_level() > 0) ob_end_clean();
    header("Location: $u");
    exit;
}
function error_page(string $title, string $message, int $status = 200): never
{
    if ($status > 0) http_response_code($status);
    render_page('error.html.twig', ['error_title' => $title, 'message' => trim($message)], $title);
    exit;
}
function database_error(Throwable $e): bool
{
    do {
        if ($e instanceof PDOException) return true;
        $message = strtolower($e->getMessage());
        if (str_contains($message, 'sqlite') || str_contains($message, 'sqlstate') || str_contains($message, 'database')) return true;
        $e = $e->getPrevious();
    } while ($e);
    return false;
}
function database_error_message(): string
{
    return '数据库出了点小问题';
}
function cut(string $v, int $max): string
{
    static $has_mb = null;
    $has_mb ??= function_exists('mb_substr');
    return $has_mb ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
}
function human_time(int $ts): string
{
    $diff = time() - $ts;
    if ($diff < 60) return '刚刚';
    if ($diff < 3600) return floor($diff / 60) . '分钟前';
    if ($diff < 86400) return floor($diff / 3600) . '小时前';
    if ($diff < 172800) return '昨天';
    if ($diff < 604800) return floor($diff / 86400) . '天前';
    return date('Y-m-d', $ts);
}
function max_pagination_pages(): int
{
    return min(1000, max(1, (int)setting('max_pagination_pages', '50')));
}
function limit_pagination_request_pages(): void
{
    if (is_post_request() || (string)($_GET['a'] ?? 'home') === 'topic') return;
    $max = max_pagination_pages();
    foreach ($_GET as $key => $value) {
        if ($key !== 'p' && !str_ends_with((string)$key, '_p')) continue;
        $_GET[$key] = (string)min($max, max(1, (int)$value));
    }
}
function paginate(int $total, int $page, int $size, string $url, bool $limited = true): string
{
    $pages = max(1, (int)ceil($total / $size));
    if ($limited) $pages = min($pages, max_pagination_pages());
    if ($pages <= 1) return '';
    $page = max(1, min($page, $pages));
    $page_url = fn(int $n): string => append_url_query($url, ['p' => $n]);
    $h = '<div class="pagination"><ul>';
    if ($page > 1) $h .= '<li><a href="' . h($page_url($page - 1)) . '">上一页</a></li>';
    $start = max(1, $page - 2);
    $end = min($pages, $page + 2);
    if ($start > 1) {
        $h .= '<li><a href="' . h($page_url(1)) . '">1</a></li>';
        if ($start > 2) $h .= '<li><span class="ellipsis">...</span></li>';
    }
    for ($i = $start; $i <= $end; $i++) {
        $h .= '<li' . ($i === $page ? ' class="active"' : '') . '><a href="' . h($page_url($i)) . '">' . $i . '</a></li>';
    }
    if ($end < $pages) {
        if ($end < $pages - 1) $h .= '<li><span class="ellipsis">...</span></li>';
        $h .= '<li><a href="' . h($page_url($pages)) . '">' . $pages . '</a></li>';
    }
    if ($page < $pages) $h .= '<li><a href="' . h($page_url($page + 1)) . '">下一页</a></li>';
    $h .= '</ul></div>';
    return $h;
}
function simple_paginate(bool $has_prev, bool $has_next, int $page, string $url, bool $limited = true): string
{
    if ($limited && $page >= max_pagination_pages()) $has_next = false;
    if (!$has_prev && !$has_next) return '';
    $page_url = fn(int $n): string => append_url_query($url, ['p' => $n]);
    $h = '<div class="pagination"><ul>';
    if ($has_prev) $h .= '<li><a href="' . h($page_url(max(1, $page - 1))) . '">上一页</a></li>';
    $h .= '<li class="active"><a href="' . h($page_url($page)) . '">' . $page . '</a></li>';
    if ($has_next) $h .= '<li><a href="' . h($page_url($page + 1)) . '">下一页</a></li>';
    return $h . '</ul></div>';
}
function topic_page_links(int $topic_id, int $reply_count): string
{
    $size = max(1, (int)setting('replies_per_page', '50'));
    $pages = (int)ceil($reply_count / $size);
    if ($pages <= 1) return '';
    $nums = [];
    foreach ([2, 3, $pages - 2, $pages - 1, $pages] as $n) if ($n >= 2 && $n <= $pages) $nums[$n] = true;
    $nums = array_keys($nums);
    sort($nums);
    $h = '<span class="topic-pages">' . svg_icon('pages');
    $prev = 1;
    foreach ($nums as $i) {
        if ($i - $prev > 1) $h .= '<span class="topic-pages-sep">…</span>';
        $h .= '<a href="' . h(route_url('topic', ['id' => $topic_id, 'p' => $i])) . '">' . $i . '</a>';
        $prev = $i;
    }
    return $h . '</span>';
}
function post(string $k, int $max = 0): string
{
    $v = trim((string)($_POST[$k] ?? ''));
    return $max ? cut($v, $max) : $v;
}
function id(string $k = 'id'): int
{
    return max(0, (int)($_GET[$k] ?? $_POST[$k] ?? 0));
}
function form_token(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}
function hidden_inputs(array $fields): string
{
    $html = '';
    foreach ($fields as $name => $value) {
        if ($value === null) continue;
        $html .= '<input type="hidden" name="' . h((string)$name) . '" value="' . h((string)$value) . '">';
    }
    return $html;
}
function post_action_form(string $action, string $label, array $fields = [], string $class = '', string $confirm = ''): string
{
    $confirm_attr = $confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '';
    return '<form class="post-action-form" method="post" action="' . h($action) . '"' . $confirm_attr . '>' . form_token() . hidden_inputs($fields) . '<button type="submit"' . ($class !== '' ? ' class="' . h($class) . '"' : '') . '>' . h($label) . '</button></form>';
}
function require_post(): void
{
    if (!is_post_request()) err('请求方式错误');
}
function svg_icon(string $name): string
{
    static $icons = [
        'user' => '<circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="2"/><path d="M4 21c1.8-4 4.5-6 8-6s6.2 2 8 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
        'id' => '<rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="2"/><circle cx="8" cy="10" r="2" stroke="currentColor" stroke-width="1.8"/><path d="M5.5 15c.7-1.4 1.5-2 2.5-2s1.8.6 2.5 2M13 10h5M13 14h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>',
        'reply' => '<path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>',
        'notify' => '<path d="M12 18.5a2.5 2.5 0 0 0 2.4-1.8H9.6a2.5 2.5 0 0 0 2.4 1.8Zm7-4.5-1.6-1.9V10a5.4 5.4 0 0 0-4.4-5.3V4a1 1 0 1 0-2 0v.7A5.4 5.4 0 0 0 6.6 10v2.1L5 14v1h14z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>',
        'forum' => '<path d="M4 5h16v14H4z" stroke="currentColor" stroke-width="2"/><path d="M8 9h8M8 13h5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
        'topic' => '<path d="M5 4h14v16H5z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M8 8h8M8 12h8M8 16h5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
        'view' => '<path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>',
        'settings' => '<path d="M21 4h-7M10 4H3M21 12h-9M8 12H3M21 20h-5M12 20H3M14 2v4M8 10v4M16 18v4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'admin' => '<path d="M12 3 4 6v6c0 5 3.4 7.8 8 9 4.6-1.2 8-4 8-9V6l-8-3Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M9 12l2 2 4-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'pages' => '<path d="M8 4h9a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M9.5 9h6M9.5 12.5h6M9.5 16h3.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
    ];
    return isset($icons[$name]) ? '<svg class="meta-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">' . $icons[$name] . '</svg>' : '';
}
function avatar_tag(int $uid, string $name, string $class = ''): string
{
    $classes = trim('avatar-img ' . $class);
    return '<img class="' . h($classes) . '" src="' . h(asset_url('app/assets/avatar.svg')) . '" alt="' . h($name) . '" loading="lazy">';
}
function avatar_link_tag(int $uid, string $name, string $class = ''): string
{
    $avatar = avatar_tag($uid, $name, $class);
    if ($uid < 1) return $avatar;
    return '<a class="avatar-profile-link" href="' . h(route_url('user', ['id' => $uid])) . '" aria-label="查看 ' . h($name) . ' 的个人主页">' . $avatar . '</a>';
}
function app_url(string $path = ''): string
{
    $path = ltrim($path, '/');
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
    $base = ($dir === '' || $dir === '.') ? '' : $dir;
    if ($path === '') return $base === '' ? '/' : $base . '/';
    return $base . '/' . $path;
}
function append_url_query(string $url, array $params): string
{
    $query = [];
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') continue;
        $query[$key] = (string)$value;
    }
    if (!$query) return $url;
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}
function index_url(array $params = []): string
{
    return append_url_query(app_url('index.php'), $params);
}
function admin_url(array $params = []): string
{
    return route_url('admin', $params);
}
function route_url(string $a = 'home', array $params = []): string
{
    if (setting('pretty_url', '0') !== '1') return $a === 'home' ? index_url($params) : index_url(['a' => $a] + $params);
    if ($a === 'home') return $params ? append_url_query(app_url(''), $params) : app_url();
    $params = $a === 'home' ? $params : ['a' => $a] + $params;
    $segments = [];
    if (isset($params['a']) && $params['a'] !== '') {
        $segments[] = rawurlencode((string)$params['a']);
        unset($params['a']);
    }
    if (isset($params['id']) && ctype_digit((string)$params['id'])) {
        $segments[] = rawurlencode((string)$params['id']);
        unset($params['id']);
    }
    return append_url_query(app_url(implode('/', $segments)), $params);
}
function asset_url(string $file): string
{
    return app_url($file);
}
function parse_path_route(): void
{
    $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($base !== '' && $base !== '.' && str_starts_with($path, $base . '/')) $path = substr($path, strlen($base));
    $path = trim($path, '/');
    if ($path === '' || $path === basename($script)) return;
    $segments = array_values(array_filter(explode('/', $path), 'strlen'));
    if (isset($segments[0]) && $segments[0] !== 'a' && !array_key_exists('a', $_GET)) $_GET['a'] = rawurldecode($segments[0]);
    if (isset($segments[1]) && ctype_digit($segments[1]) && !array_key_exists('id', $_GET)) $_GET['id'] = rawurldecode($segments[1]);
}
function content_html_token(array &$tokens, string $html): string
{
    $key = "\x1B" . count($tokens) . "\x1B";
    $tokens[$key] = $html;
    return $key;
}
function content_special_links_html(string $escaped_text, int $topic_id = 0): string
{
    if (!str_contains($escaped_text, '@')) return $escaped_text;
    $tokens = [];
    $escaped_text = preg_replace_callback('/@([^\s@#<]{1,32})\s+#(\d+)/u', function ($m) use (&$tokens, $topic_id) {
        if ($topic_id <= 0) return $m[0];
        $url = route_url('topic', ['id' => $topic_id, 'floor' => (int)$m[2]]);
        return content_html_token($tokens, '<a class="post-mention post-floor-mention" href="' . h($url) . '" target="_blank" rel="noopener">@' . $m[1] . ' #' . (int)$m[2] . '</a>');
    }, $escaped_text) ?? $escaped_text;
    $escaped_text = preg_replace_callback('/(?<![\p{L}\p{N}._%+\-])@([\p{L}\p{N}_-]+(?:\.[\p{L}\p{N}_-]+)*)/u', function ($m) {
        $username = html_entity_decode((string)$m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return '<a class="post-mention" href="' . h(route_url('user', ['username' => $username])) . '">@' . $m[1] . '</a>';
    }, $escaped_text) ?? $escaped_text;
    return strtr($escaped_text, $tokens);
}
function markdown_html(string $text, int $quote_depth = 0, int $topic_id = 0): string
{
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    if ($text === '') return '';
    return '<p>' . str_replace("\n", '<br>', content_special_links_html(h($text), $topic_id)) . '</p>';
}
function topic_post_row(array $row, string $body, int $time, string $ops = '', string $title = '', string $stats = '', bool $highlight = false, array $ctx = []): string
{
    $is_reply = isset($row['topic_id']);
    $topic_id = $is_reply ? (int)$row['topic_id'] : (int)($row['id'] ?? 0);
    $floor = $is_reply ? (int)($ctx['reply_position'] ?? 0) : 0;
    $has_title = $title !== '';
    $title_html = $has_title ? '<div class="post-topic-title"><h1 class="post-content-title">' . h($title) . '</h1>' . $stats . '</div>' : '';
    $avatar = avatar_link_tag((int)$row['user_id'], (string)$row['username']);
    $floor_attr = $floor > 0 ? ' data-floor="' . $floor . '"' : '';
    $floor_html = $floor > 0 ? '<a class="post-floor" href="' . h(route_url('topic', ['id' => $topic_id, 'floor' => $floor])) . '">#' . $floor . '</a>' : '';
    $ops_html = $ops !== '' || $floor_html !== '' ? '<div class="post-ops">' . $ops . $floor_html . '</div>' : '';
    $uid_html = ((int)($row['user_id'] ?? 0) > 0 && (string)($row['username'] ?? '') !== '匿名') ? '<span class="post-user-group user-uid-badge" title="用户 UID"><span class="user-uid-icon" aria-hidden="true">' . svg_icon('id') . '</span>' . (int)$row['user_id'] . '</span>' : '';
    $tags_html = topic_user_group_html($row) . user_state_tag_html($row) . $uid_html;
    $html = '<li class="post-item post-entry' . ($has_title ? ' has-title' : '') . ($highlight ? ' post-highlight' : '') . '" id="post-' . (int)($row['id'] ?? 0) . '"' . $floor_attr . '>' . $title_html . '<div class="post-avatar">' . $avatar . '</div><div class="post-body"><div class="post-head' . ($floor > 0 ? ' has-floor' : '') . '"><div class="post-info"><a class="post-title post-author" href="' . h(route_url('user', ['id' => (int)$row['user_id']])) . '">' . h($row['username']) . '</a><span class="post-time">' . human_time($time) . '</span></div>' . $ops_html . '</div><div class="post-meta">' . $tags_html . '</div></div><div class="post-content">' . markdown_html($body, 0, $topic_id) . '</div></li>';
    return $html;
}
function quote_reply_action(array $row, int $floor = 0): string
{
    $floor_attr = $floor > 0 ? ' data-floor="' . $floor . '"' : '';
    return '<a class="icon-action icon-quote quote-reply" href="#reply" data-username="' . h((string)$row['username']) . '"' . $floor_attr . ' title="回复"><span>回复</span></a>';
}
function topic_list_select_columns(): string
{
    static $cached = null;
    if ($cached !== null) return $cached;
    $columns = 'id,title,highlight_style,created_at,reply_count,last_reply_at,last_reply_user_id,forum_id,user_id';
    return $cached = $columns;
}
function search_min_chars(): int
{
    return length_limit('search', 'min');
}
function search_char_count(string $query): int
{
    $count = preg_match_all('/./us', trim($query));
    return $count === false ? 0 : $count;
}
function require_search_min_chars(string $query): void
{
    $query = trim($query);
    if ($query === '') return;
    $minimum = search_min_chars();
    if (search_char_count($query) < $minimum) err('请至少输入' . $minimum . '个字符再搜索');
}
function topic_search_field(string $field): string
{
    return in_array($field, ['title', 'body', 'reply'], true) ? $field : 'title';
}
function search_like_pattern(string $query): string
{
    return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($query)) . '%';
}
function content_search_condition(string $query, string $field = 'title'): array
{
    $field = in_array($field, ['title', 'body', 'reply'], true) ? $field : 'title';
    $column = $field === 'title' ? 'title' : 'body';
    $fallback = [$column . " LIKE ? ESCAPE '!'", [search_like_pattern($query)]];
    return $fallback;
}
function search_index_rebuild(string $type = 'all', int $start_id = 1): int
{
    return 0;
}
function topic_list_rows_for_replies(array $reply_rows): array
{
    if (!$reply_rows) return [];
    $topics = rows_by_ids('app_topics', array_column($reply_rows, 'topic_id'), topic_list_select_columns());
    $rows = [];
    foreach ($reply_rows as $reply) {
        $topic_id = (int)$reply['topic_id'];
        $topic = $topics[$topic_id] ?? [
            'id' => $topic_id,
            'title' => '已删除',
            'reply_only' => 1,
            'forum_id' => 0,
            'user_id' => (int)$reply['user_id'],
            'created_at' => (int)$reply['created_at'],
            'last_reply_at' => (int)$reply['created_at'],
            'reply_count' => 0,
        ];
        $rows[] = $topic + [
            'my_reply_at' => (int)$reply['created_at'],
            'my_reply_id' => (int)$reply['id'],
            'my_reply_excerpt' => content_excerpt(content_preview_source_text((string)$reply['body'])),
        ];
    }
    return attach_topic_list_users($rows);
}
function topic_list_row(array $t, string $sort): string
{
    $time = (int)($t['time'] ?? ($sort === 'post' ? $t['created_at'] : ($t['last_reply_at'] ?: $t['created_at'])));
    if (!empty($t['reply_only'])) {
        $reply_excerpt = trim((string)($t['my_reply_excerpt'] ?? ''));
        $reply_excerpt_html = $reply_excerpt !== '' ? '<div class="profile-reply-excerpt">' . h($reply_excerpt) . '</div>' : '';
        $user_link = '<a href="' . h(route_url('user', ['id' => (int)$t['user_id']])) . '">' . svg_icon('user') . h($t['username']) . '</a>';
        $html = '<li class="post-item"><div class="post-avatar">' . avatar_link_tag((int)$t['user_id'], (string)$t['username']) . '</div><div class="post-body">' . $reply_excerpt_html . '<div class="post-meta"><span>' . $user_link . '</span><span>' . human_time($time) . '</span></div></div></li>';
        return $html;
    }
    $forum = $t['forum'] ?? ['id' => (int)$t['forum_id'], 'name' => ''];
    $has_forum = (int)($forum['id'] ?? 0) > 0 && trim((string)($forum['name'] ?? '')) !== '';
    $user_link = '<a href="' . h(route_url('user', ['id' => (int)$t['user_id']])) . '">' . svg_icon('user') . h($t['username']) . '</a>';
    $forum_link = $has_forum ? '<a href="' . h(route_url('forum', ['id' => (int)$forum['id']])) . '">' . h($forum['name']) . '</a>' : '';
    $last_reply_username = (string)($t['last_reply_username'] ?? '');
    $last_reply_user = $last_reply_username !== '' ? '<span>' . svg_icon('user') . h($last_reply_username) . '</span>' : '';
    $time_meta = '<span>' . human_time($time) . '</span>';
    $forum_meta = $has_forum ? '<span class="post-forum-meta">' . svg_icon('forum') . $forum_link . '</span>' : '';
    $meta = '<span>' . $user_link . '</span>' . ($sort === 'post' ? $time_meta : '') . $forum_meta . '<span>' . svg_icon('reply') . (int)$t['reply_count'] . '</span>' . $last_reply_user . ($sort === 'post' ? '' : $time_meta);
    $pages = topic_page_links((int)$t['id'], (int)$t['reply_count']);
    $reply_id = (int)($t['my_reply_id'] ?? 0);
    $topic_url = route_url('topic', ['id' => (int)$t['id'], 'replyid' => $reply_id > 0 ? $reply_id : null]);
    $reply_excerpt = trim((string)($t['my_reply_excerpt'] ?? ''));
    $reply_excerpt_html = $reply_excerpt !== '' ? '<div class="profile-reply-excerpt">' . h($reply_excerpt) . '</div>' : '';
    $badges = ((int)($t['is_pinned'] ?? 0) ? '<span class="topic-badge pinned">置顶</span>' : '');
    $style = (string)($t['highlight_style'] ?? '') !== '' ? ' style="' . h((string)$t['highlight_style']) . '"' : '';
    $forum_badge = $has_forum ? '<a class="post-tag post-forum-badge" href="' . h(route_url('forum', ['id' => (int)$forum['id']])) . '">' . h($forum['name']) . '</a>' : '';
    $html = '<li class="post-item' . ((int)($t['is_pinned'] ?? 0) ? ' topic-pinned' : '') . '"><div class="post-avatar">' . avatar_link_tag((int)$t['user_id'], (string)$t['username']) . '</div><div class="post-body"><div class="post-title-row">' . $badges . '<a class="post-title" href="' . h($topic_url) . '"' . $style . '>' . h($t['title']) . '</a>' . $pages . '</div>' . $reply_excerpt_html . '<div class="post-meta">' . $meta . '</div></div>' . $forum_badge . '</li>';
    return $html;
}
function topic_stats_html(int $view_count, int $reply_count): string
{
    $stats = '';
    if ($view_count > 0) $stats .= '<span>' . svg_icon('view') . $view_count . '</span>';
    if ($reply_count > 0) $stats .= '<span>' . svg_icon('reply') . $reply_count . '</span>';
    return $stats ? '<div class="post-content-stats">' . $stats . '</div>' : '';
}
function twig(): Twig\Environment
{
    static $env;
    if ($env !== null) return $env;
    $env = new Twig\Environment(new Twig\Loader\FilesystemLoader(TEMPLATE_DIR), [
        'cache' => DATA_DIR . '/twig-cache',
        'auto_reload' => true,
    ]);
    $html_safe = ['route_url', 'index_url', 'admin_url', 'asset_url', 'app_url', 'svg_icon', 'avatar_tag', 'avatar_link_tag', 'form_token', 'hidden_inputs', 'input', 'textarea', 'checkbox', 'number_input', 'select_input', 'render_form_fields', 'select_forum', 'tab_bar_html', 'auth_tabs_html', 'admin_tabs', 'paginate', 'simple_paginate', 'topic_page_links', 'topic_list_row', 'topic_post_row', 'notification_row_html', 'notification_badge_html', 'sidebar_user_card_html', 'sidebar_notice_card_html', 'user_state_tag_html', 'topic_user_group_html', 'topic_stats_html', 'quote_reply_action', 'post_action_form', 'markdown_html', 'human_time', 'form_shell', 'shell_html', 'sidebar_stack_html', 'flash_json'];
    foreach ($html_safe as $fn) $env->addFunction(new Twig\TwigFunction($fn, $fn, ['is_safe' => ['html']]));
    foreach (['setting', 'csrf_token', 'uid', 'can_manage', 'can_speak', 'can_access_admin', 'is_super_user', 'notification_excerpt', 'excerpt_length', 'notification_link'] as $fn) $env->addFunction(new Twig\TwigFunction($fn, $fn));
    $env->addGlobal('app_version', APP_VERSION);
    return $env;
}
function template(string $name, array $data = []): string
{
    return twig()->render($name, $data);
}
function flash_json(string $flash): string
{
    return json_encode($flash, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}
function page_nav_data(string $site_name): array
{
    $active_forum = ($_GET['a'] ?? '') === 'forum' ? id() : 0;
    $mine = me();
    $forums = array_values(array_filter(forums_cache(), fn($f) => forum_group_allowed($f, 'allow_view_groups')));
    $visible_limit = min(20, max(0, (int)setting('pc_nav_forum_count', '6')));
    $visible = array_slice($forums, 0, $visible_limit);
    $visible_ids = array_map(fn($f): int => (int)$f['id'], $visible);
    if ($active_forum && !in_array($active_forum, $visible_ids, true)) {
        foreach ($forums as $f) {
            if ((int)$f['id'] === $active_forum) {
                if ($visible_limit > 0) $visible[$visible_limit - 1] = $f;
                break;
            }
        }
    }
    return [
        'site_name' => $site_name,
        'active_forum' => $active_forum,
        'mine' => $mine,
        'mine_unread' => $mine ? (int)($mine['unread_notifications'] ?? 0) : 0,
        'mine_link' => $mine ? route_url('user', ['id' => (int)$mine['id'], 'tab' => 'notifications']) : route_url('login'),
        'mine_avatar' => $mine ? avatar_tag((int)$mine['id'], (string)$mine['username'], 'mobile-nav-avatar') : svg_icon('user'),
        'visible_forums' => $visible,
        'has_more_forums' => count($forums) > $visible_limit,
        'all_forums' => $forums,
    ];
}
function page_common_data(string $title, array $seo = []): array
{
    $settings = settings_cache();
    $site_name = trim((string)$settings['site_name']) ?: 'FORUM';
    $site_name_title = trim((string)($settings['site_name_title'] ?? '')) ?: $site_name;
    $is_home = ($_GET['a'] ?? 'home') === 'home' && trim((string)($_GET['q'] ?? '')) === '';
    $page_title = $is_home || $title === '' || $title === $site_name ? $site_name_title : $title . ' - ' . $site_name_title;
    $description = trim((string)($seo['description'] ?? ($settings['site_description'] ?? '')));
    $flash = trim((string)($_COOKIE['__flash'] ?? ''));
    if ($flash !== '' && !headers_sent()) app_cookie('__flash', '', time() - 3600, true, false);
    return [
        'title' => $title,
        'site_name' => $site_name,
        'site_name_title' => $site_name_title,
        'site_keywords' => (string)($settings['site_keywords'] ?? ''),
        'site_description' => $description,
        'seo_canonical' => (string)($seo['canonical'] ?? ''),
        'is_home' => $is_home,
        'page_title' => $page_title,
        'flash' => $flash,
        'nav' => page_nav_data($site_name),
    ];
}
function render_page(string $template, array $data, string $title, array $seo = []): void
{
    echo template($template, $data + page_common_data($title, $seo));
}
function form_field_caption(string $label, string $help = ''): string
{
    return '<span>' . h($label) . ($help !== '' ? '<small>' . h($help) . '</small>' : '') . '</span>';
}
function length_attributes(string $name): string
{
    $limits = match ($name) {
        'username' => [length_limit('username', 'min'), length_limit('username', 'max')], 'email' => [length_limit('email', 'min'), length_limit('email', 'max')],
        'bio' => [length_limit('bio', 'min'), length_limit('bio', 'max')], 'q' => [length_limit('search', 'min'), length_limit('search', 'max')],
        'title' => [length_limit('title', 'min'), length_limit('title', 'max')], 'content' => [0, DB_TEXT_MAX_LENGTH],
        'body' => [min(length_limit('topic_body', 'min'), length_limit('reply_body', 'min')), max(length_limit('topic_body', 'max'), length_limit('reply_body', 'max'))],
        default => [null, null],
    };
    [$min, $max] = $limits;
    return ($min !== null && $min > 0 ? ' minlength="' . $min . '"' : '') . ($max !== null ? ' maxlength="' . $max . '"' : '');
}
function input(string $label, string $name, mixed $value = '', string $type = 'text', bool $required = false, string $help = '', string $class = ''): string
{
    return '<label class="grid' . ($class !== '' ? ' ' . h($class) : '') . '">' . form_field_caption($label, $help) . '<input name="' . h($name) . '" type="' . h($type) . '" value="' . h($value) . '"' . length_attributes($name) . ($required ? ' required' : '') . '></label>';
}
function textarea(string $label, string $name, mixed $value = '', bool $required = false, string $help = '', string $class = ''): string
{
    return '<label class="grid' . ($class !== '' ? ' ' . h($class) : '') . '">' . form_field_caption($label, $help) . '<textarea name="' . h($name) . '"' . length_attributes($name) . ($required ? ' required' : '') . '>' . h($value) . '</textarea></label>';
}
function checkbox(string $label, string $name, bool $checked = false, string $help = '', string $class = ''): string
{
    return '<label class="grid' . ($class !== '' ? ' ' . h($class) : '') . '">' . form_field_caption($label, $help) . '<input type="checkbox" name="' . h($name) . '" value="1"' . ($checked ? ' checked' : '') . '></label>';
}
function number_input(string $label, string $name, mixed $value = '', int|float|null $min = null, int|float|null $max = null, bool $required = true, string $help = '', string $class = ''): string
{
    $limits = ($min !== null ? ' min="' . h($min) . '"' : '') . ($max !== null ? ' max="' . h($max) . '"' : '');
    return '<label class="grid' . ($class !== '' ? ' ' . h($class) : '') . '">' . form_field_caption($label, $help) . '<input name="' . h($name) . '" type="number" value="' . h($value) . '"' . $limits . ($required ? ' required' : '') . '></label>';
}
function select_input(string $label, string $name, mixed $value, array $options, string $help = '', string $class = ''): string
{
    $html = '<label class="grid' . ($class !== '' ? ' ' . h($class) : '') . '">' . form_field_caption($label, $help) . '<select name="' . h($name) . '">';
    foreach ($options as $option_value => $option_label) $html .= '<option value="' . h($option_value) . '"' . ((string)$option_value === (string)$value ? ' selected' : '') . '>' . h($option_label) . '</option>';
    return $html . '</select></label>';
}
function render_form_fields(array $fields, array $values = []): string
{
    $html = '';
    foreach ($fields as $name => $field) {
        if (isset($field['html'])) {
            $html .= (string)$field['html'];
            continue;
        }
        $type = (string)($field['type'] ?? 'text');
        $label = (string)($field['label'] ?? $name);
        $value = array_key_exists('value', $field) ? $field['value'] : ($values[$name] ?? '');
        $help = (string)($field['help'] ?? '');
        $class = (string)($field['class'] ?? '');
        if ($help !== '' && !str_contains(' ' . $class . ' ', ' settings-help-field ')) $class = trim($class . ' settings-help-field');
        if ($type === 'checkbox') $html .= checkbox($label, (string)$name, (bool)(int)$value, $help, $class);
        elseif ($type === 'number') $html .= number_input($label, (string)$name, $value, $field['min'] ?? null, $field['max'] ?? null, (bool)($field['required'] ?? true), $help, $class);
        elseif ($type === 'select') $html .= select_input($label, (string)$name, $value, (array)($field['options'] ?? []), $help, $class);
        elseif ($type === 'textarea') $html .= textarea($label, (string)$name, $value, !empty($field['required']), $help, $class);
        else $html .= input($label, (string)$name, $value, $type, !empty($field['required']), $help, $class);
    }
    return $html;
}
function select_forum(int $fid): string
{
    $options = [];
    foreach (forums_cache() as $f) if (forum_group_allowed($f, 'allow_post_groups')) $options[(int)$f['id']] = (string)$f['name'];
    if ($fid <= 0) $fid = (int)(array_key_first($options) ?? 0);
    return select_input('版块', 'forum_id', $fid, $options);
}
function default_post_forum_id(): int
{
    foreach (forums_cache() as $forum) if (forum_group_allowed($forum, 'allow_post_groups')) return (int)$forum['id'];
    return 0;
}
function can_manage_topic(array $t): bool
{
    $allowed = can_manage() || (uid() && (int)$t['user_id'] === uid());
    return $allowed;
}
function can_manage_reply(array $r): bool
{
    return can_manage() || (uid() && (int)$r['user_id'] === uid());
}
function refresh_topic_stats(int $tid): void
{
    q("UPDATE app_topics SET reply_count=(SELECT COUNT(*) FROM app_replies WHERE topic_id=?),last_reply_at=COALESCE((SELECT created_at FROM app_replies WHERE topic_id=? ORDER BY created_at DESC,id DESC LIMIT 1),created_at),last_reply_user_id=COALESCE((SELECT user_id FROM app_replies WHERE topic_id=? ORDER BY created_at DESC,id DESC LIMIT 1),0) WHERE id=?", [$tid, $tid, $tid, $tid]);
}
function require_password_length(string $password): void
{
    if ((int)preg_match_all('/./us', $password) < PASSWORD_MIN_LENGTH) err('密码至少' . PASSWORD_MIN_LENGTH . '位');
}
function require_valid_username(string $username): void
{
    if ($username === '') err('用户名不能为空');
    $result = preg_match('/[\s\p{Z}\p{C}]/u', $username);
    if ($result === false) err('用户名包含非法字符');
    if ($result === 1) err('用户名不能包含空白或不可见字符');
}
function save_user(?int $target_user_id = null): void
{
    $user_id = $target_user_id ?? id();
    if ($user_id > 0 && $user_id !== uid()) err('无权限');
    if ($target_user_id === null && (array_key_exists('id', $_GET) || array_key_exists('id', $_POST))) err('参数错误');
    $is_registration = !$user_id;
    $username = post('username', length_limit('username', 'max'));
    $email = post('email', length_limit('email', 'max'));
    $bio = post('bio', length_limit('bio', 'max'));
    $old_user = $user_id ? row('app_users', 'id', $user_id) : null;
    if ($user_id && !$old_user) err('用户不存在');
    if ($old_user) {
        $username = (string)$old_user['username'];
        $email = (string)$old_user['email'];
    }
    if ($username === '') err('用户名不能为空');
    require_length($username, length_limit('username', 'min'), '用户名');
    require_length($email, length_limit('email', 'min'), '邮箱地址');
    require_length($bio, length_limit('bio', 'min'), '个人简介');
    $gid = $old_user ? (int)$old_user['group_id'] : (int)setting('default_group_id', '2');
    if (!group_by_id($gid)) err('用户组不存在');
    $pwd = (string)($_POST['password'] ?? '');
    $pwd2 = (string)($_POST['password2'] ?? '');
    if ($pwd !== '') require_password_length($pwd);
    if ($pwd !== '' && $pwd !== $pwd2) err('两次密码不一致');
    if (!$old_user || (string)$old_user['username'] !== $username) require_valid_username($username);
    if ($is_registration && preg_match_all('/./us', $username) < length_limit('username', 'min')) err('用户名长度不能少于' . length_limit('username', 'min') . '个字符');
    if ($is_registration && preg_match_all('/./us', $username) > length_limit('username', 'max')) err('用户名不能超过' . length_limit('username', 'max') . '个字符');
    $exists = $user_id ? one("SELECT id FROM app_users WHERE username=? AND id<>?", [$username, $user_id]) : one("SELECT id FROM app_users WHERE username=?", [$username]);
    if ($exists) err('用户名已存在');
    if ($user_id) {
        $sql = "UPDATE app_users SET username=?,email=?,bio=? WHERE id=?";
        $p = [$username, $email, $bio, $user_id];
        if ($pwd !== '') {
            $sql = "UPDATE app_users SET username=?,email=?,bio=?,password=? WHERE id=?";
            $p = [$username, $email, $bio, password_hash($pwd, PASSWORD_DEFAULT), $user_id];
        }
        q($sql, $p);
        if ($pwd !== '' && $user_id === uid()) csrf_cookie_clear();
    } else {
        if ($pwd === '') err('密码不能为空');
        q("INSERT INTO app_users(username,password,email,bio,group_id,created_at) VALUES(?,?,?,?,?,?)", [$username, password_hash($pwd, PASSWORD_DEFAULT), $email, $bio, $gid, now()]);
        $GLOBALS['__last_saved_user_id'] = app_db_last_insert_id('app_users');
    }
}
function base_url(): string
{
    $configured = clean_site_base_url(setting('site_base_url', ''));
    if ($configured !== '') return $configured;
    $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
    return (auth_cookie_secure() ? 'https' : 'http') . '://' . $host;
}
function absolute_url(string $url): string
{
    if (preg_match('/^https?:\/\//i', $url)) return $url;
    return rtrim(base_url(), '/') . '/' . ltrim($url, '/');
}
function seo_text(string $text, ?int $max = null): string
{
    $text = html_entity_decode(strip_tags(markdown_html($text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return cut(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), $max ?? excerpt_length());
}
function page_seo(string $route, array $params = [], string $description = ''): array
{
    $seo = ['canonical' => absolute_url(route_url($route, $params))];
    $description = seo_text($description);
    if ($description !== '') $seo['description'] = $description;
    return $seo;
}
function content_create_author(string $hook_name, array $content, array $context, array $limits): array
{
    $defaults = ['user_id' => uid()] + $content;
    $author = $defaults;
    $author['user_id'] = max(1, (int)$author['user_id']);
    foreach ($limits as $field => $max) $author[$field] = cut((string)$author[$field], $max);
    return $author;
}
function topic_title_color(string $style): string
{
    return preg_match('/(?:^|;)\s*color\s*:\s*(#[0-9a-fA-F]{6})(?:\s*;|$)/', $style, $matches) ? $matches[1] : '';
}
function topic_title_is_bold(string $style): bool
{
    return preg_match('/(?:^|;)\s*font-weight\s*:\s*(?:700|bold)(?:\s*;|$)/i', $style) === 1;
}
function topic_title_style(string $color, bool $bold): string
{
    $styles = [];
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1) $styles[] = 'color:' . $color;
    if ($bold) $styles[] = 'font-weight:700';
    return implode(';', $styles);
}
function save_topic(): int
{
    need_speak();
    $topic_id = id();
    if (!$topic_id) check_post_interval();
    $action = (string)($_POST['topic_action'] ?? '');
    $fid = max(0, (int)$_POST['forum_id']);
    if ($fid <= 0) $fid = default_post_forum_id();
    $forum = forum_by_id($fid) ?: err('版块不存在');
    $title = post('title', length_limit('title', 'max'));
    $body = post('body', length_limit('topic_body', 'max'));
    if ($topic_id) {
        $t = row('app_topics', 'id', $topic_id) ?: err('主题不存在');
        if (!can_manage_topic($t)) err('无权限');
        if ($action !== '' && !can_manage()) err('无权限');
        if (in_array($action, ['delete_topic', 'delete_topic_replies'], true)) {
            del('topics', (int)$t['id'], true);
            go(route_url('home'));
        }
        if (in_array($action, ['pin', 'unpin'], true)) {
            $pin_action = $action === 'pin' ? (string)($_POST['topic_pin_action'] ?? 'pin') : 'unpin';
            if (!in_array($pin_action, ['pin', 'unpin'], true)) err('请选择置顶操作');
            set_pinned_topic((int)$t['id'], $pin_action === 'pin');
            go(route_url('topic', ['id' => (int)$t['id']]));
        }
        if (in_array($action, ['highlight', 'bold'], true)) {
            $current_style = (string)($t['highlight_style'] ?? '');
            $color = topic_title_color($current_style);
            $bold = topic_title_is_bold($current_style);
            if ($action === 'highlight') {
                $raw_color = trim((string)($_POST['highlight_style'] ?? ''));
                $color = $raw_color === '' ? '' : (preg_match('/^#[0-9a-fA-F]{6}$/', $raw_color, $m) ? $m[0] : '#d94b4b');
            } else {
                $bold_action = (string)($_POST['topic_bold_action'] ?? '');
                if (!in_array($bold_action, ['bold', 'unbold'], true)) err('请选择加粗操作');
                $bold = $bold_action === 'bold';
            }
            q("UPDATE app_topics SET highlight_style=? WHERE id=?", [topic_title_style($color, $bold), (int)$t['id']]);
            go(route_url('topic', ['id' => (int)$t['id']]));
        }
        if ($action === 'mute_author') {
            if ((int)$t['user_id'] === 1) err('不能操作超级管理员');
            q("UPDATE app_users SET is_muted=1 WHERE id=?", [(int)$t['user_id']]);
            go(route_url('topic', ['id' => (int)$t['id']]));
        }
        if ($action === '') {
            if (!forum_group_allowed($forum, 'allow_post_groups')) err('无权限');
            if ($title === '' || $body === '') err('标题和内容不能为空');
            require_length($title, length_limit('title', 'min'), '标题');
            require_length($body, length_limit('topic_body', 'min'), '主题内容');
        } else {
            $title = (string)($t['title'] ?? '');
            $body = (string)($t['body'] ?? '');
        }
        $reply_order = (int)($_POST['reply_order'] ?? 0) === 1 ? 1 : 0;
        tx(function () use ($topic_id, $fid, $title, $body, $reply_order) {
            q("UPDATE app_topics SET forum_id=?,title=?,body=?,reply_order=? WHERE id=?", [$fid, $title, $body, $reply_order, $topic_id]);
        });
        return $topic_id;
    }
    if (!forum_group_allowed($forum, 'allow_post_groups')) err('无权限');
    if ($title === '' || $body === '') err('标题和内容不能为空');
    require_length($title, length_limit('title', 'min'), '标题');
    require_length($body, length_limit('topic_body', 'min'), '主题内容');
    $author = content_create_author('topic.create_author', ['title' => $title, 'body' => $body], ['forum_id' => $fid], ['title' => length_limit('title', 'max'), 'body' => length_limit('topic_body', 'max')]);
    $author_id = (int)$author['user_id'];
    $title = $author['title'];
    $body = $author['body'];
    if ($title === '' || $body === '') err('标题和内容不能为空');
    require_length($title, length_limit('title', 'min'), '标题');
    require_length($body, length_limit('topic_body', 'min'), '主题内容');
    $ts = now();
    $tid = tx(function () use ($fid, $author_id, $title, $body, $ts) {
        q("INSERT INTO app_topics(forum_id,user_id,title,body,created_at,last_reply_at) VALUES(?,?,?,?,?,?)", [$fid, $author_id, $title, $body, $ts, $ts]);
        $tid = app_db_last_insert_id('app_topics');
        q("UPDATE app_users SET last_post_at=? WHERE id=?", [$ts, $author_id]);
        create_topic_notifications($tid, $body, $author_id);
        return $tid;
    });
    return $tid;
}
function save_reply(): array
{
    need_speak();
    $reply_id = id();
    if (!$reply_id) check_post_interval();
    $r = null;
    if ($reply_id) {
        $r = row('app_replies', 'id', $reply_id) ?: err('回复不存在');
        if (!can_manage_reply($r)) err('无权限');
        if (trim((string)$r['body']) === '') err('回复已删除，无法编辑');
        $tid = (int)$r['topic_id'];
    } else {
        $tid = max(1, (int)$_POST['topic_id']);
    }
    $topic = row('app_topics', 'id', $tid) ?: err('主题不存在');
    $forum = forum_by_id((int)$topic['forum_id']) ?: err('版块不存在');
    if (!forum_group_allowed($forum, 'allow_reply_groups')) err('无权限');
    $body = post('body', length_limit('reply_body', 'max'));
    if ($body === '') err('回复不能为空');
    require_length($body, length_limit('reply_body', 'min'), '回帖内容');
    if ($reply_id) {
        tx(function () use ($body, $tid, $reply_id) {
            q("UPDATE app_replies SET body=?,updated_at=? WHERE id=? AND topic_id=?", [$body, now(), $reply_id, $tid]);
        });
        return ['topic_id' => (int)$r['topic_id'], 'reply_id' => (int)$r['id']];
    }
    require_length($body, length_limit('reply_body', 'min'), '回帖内容');
    $author = content_create_author('reply.create_author', ['body' => $body], ['topic_id' => $tid], ['body' => length_limit('reply_body', 'max')]);
    $author_id = (int)$author['user_id'];
    $body = $author['body'];
    if ($body === '') err('回复不能为空');
    $ts = now();
    $rid = tx(function () use ($tid, $author_id, $body, $ts) {
        q("INSERT INTO app_replies(topic_id,user_id,body,created_at,updated_at) VALUES(?,?,?,?,?)", [$tid, $author_id, $body, $ts, $ts]);
        $rid = app_db_last_insert_id('app_replies');
        q("UPDATE app_users SET last_post_at=? WHERE id=?", [$ts, $author_id]);
        q("UPDATE app_topics SET reply_count=reply_count+1,last_reply_at=?,last_reply_user_id=? WHERE id=?", [$ts, $author_id, $tid]);
        create_reply_notifications($tid, $rid, $body, $author_id);
        return $rid;
    });
    return ['topic_id' => $tid, 'reply_id' => $rid];
}
function content_delete_notify(array $row, bool $is_reply, int $topic_id = 0): void
{
    $author_id = (int)($row['user_id'] ?? 0);
    if ($author_id <= 0 || $author_id === uid()) return;
    if ($is_reply) {
        create_notification($author_id, uid(), 'delete', '您的回帖已被删除。', max(0, $topic_id));
        return;
    }
    $title = trim((string)($row['title'] ?? ''));
    create_notification($author_id, uid(), 'delete', '您的主题《' . ($title !== '' ? $title : '#' . (int)($row['id'] ?? 0)) . '》已被删除。');
}
function app_topics_del_ready(): bool
{
    static $ready = null;
    if ($ready === null) $ready = app_db_table_exists(db(), 'app_topics_del');
    return $ready;
}
function floor_index_clear(): void
{
    unset($GLOBALS['__floor_cache']);
}
function floor_index_record(int $topic_id, int $reply_id, int $created_at): void
{
    if (!app_topics_del_ready()) return;
    q('INSERT INTO app_topics_del(topic_id,reply_id,created_at) VALUES(?,?,?)', [$topic_id, $reply_id, $created_at]);
    floor_index_clear();
}
function topic_floor_index(int $topic_id): array
{
    if (isset($GLOBALS['__floor_cache'][$topic_id])) return $GLOBALS['__floor_cache'][$topic_id];
    if (!app_topics_del_ready()) return $GLOBALS['__floor_cache'][$topic_id] = [];
    $rows = q('SELECT reply_id AS id,created_at FROM app_topics_del WHERE topic_id=? ORDER BY created_at,id', [$topic_id])->fetchAll();
    return $GLOBALS['__floor_cache'][$topic_id] = array_map(static fn(array $r): array => ['id' => (int)$r['id'], 'created_at' => (int)$r['created_at']], $rows);
}
function gap_before(array $del, int $created_at, int $reply_id): int
{
    $lo = 0;
    $hi = count($del);
    while ($lo < $hi) {
        $mid = ($lo + $hi) >> 1;
        if ($del[$mid]['created_at'] < $created_at || ($del[$mid]['created_at'] === $created_at && $del[$mid]['id'] < $reply_id)) $lo = $mid + 1;
        else $hi = $mid;
    }
    return $lo;
}
function reply_position_floor(int $topic_id, int $created_at, int $reply_id): int
{
    $floor = 1 + (int)q('SELECT COUNT(*) FROM app_replies WHERE topic_id=? AND (created_at<? OR (created_at=? AND id<?))', [$topic_id, $created_at, $created_at, $reply_id])->fetchColumn();
    foreach (topic_floor_index($topic_id) as $gap) {
        if ($gap['created_at'] < $created_at || ($gap['created_at'] === $created_at && $gap['id'] < $reply_id)) $floor++;
    }
    return $floor;
}
function floor_live_position(int $topic_id, int $floor): int
{
    $pos = $floor;
    $i = 0;
    foreach (topic_floor_index($topic_id) as $gap) {
        $i++;
        $live_before = (int)q('SELECT COUNT(*) FROM app_replies WHERE topic_id=? AND (created_at<? OR (created_at=? AND id<?))', [$topic_id, (int)$gap['created_at'], (int)$gap['created_at'], (int)$gap['id']])->fetchColumn();
        if ($live_before + $i < $floor) $pos--;
    }
    return max(1, $pos);
}
function apply_reply_floors(array $replies, array $topic, int $page, int $size, bool $reply_desc): array
{
    $off = ($page - 1) * $size;
    $del_index = topic_floor_index((int)$topic['id']);
    foreach ($replies as $i => $reply) {
        if (!is_array($reply)) continue;
        $floor = $reply_desc ? (int)$topic['reply_count'] - $off - $i + gap_before($del_index, (int)($reply['created_at'] ?? 0), (int)($reply['id'] ?? 0)) : $off + $i + 1 + gap_before($del_index, (int)($reply['created_at'] ?? 0), (int)($reply['id'] ?? 0));
        $replies[$i]['reply_floor'] = max(1, $floor);
    }
    return $replies;
}
function del(string $table, int $id, bool $with_replies = false): void
{
    $tables = [
        'users' => 'app_users',
        'groups' => 'app_groups',
        'forums' => 'app_forums',
        'topics' => 'app_topics',
        'replies' => 'app_replies',
    ];
    if (!isset($tables[$table])) err('参数错误');
    if (in_array($table, ['users', 'groups', 'forums'], true) && !can_manage()) err('无权限');
    if ($table === 'users' && $id === uid()) err('不能删除自己');
    if ($table === 'groups' && $id <= 2) err('内置用户组不能删除');
    if ($table === 'groups' && $id === (int)setting('default_group_id', '2')) err('默认用户组不能删除');
    if ($table === 'forums' && count(forums_cache()) <= 1) err('至少保留一个版块');
    if (in_array($table, ['users', 'topics', 'replies'], true)) {
        $record = row($tables[$table], 'id', $id) ?: err('记录不存在');
        $affected_topics = $table === 'users' ? q("SELECT DISTINCT topic_id FROM app_replies WHERE user_id=?", [$id])->fetchAll() : [];
        tx(function () use ($table, $tables, $id, $record, $affected_topics, $with_replies) {
            if ($table === 'replies') {
                if (trim((string)$record['body']) === '') err('回复已删除');
                floor_index_record((int)$record['topic_id'], $id, (int)$record['created_at']);
                q('DELETE FROM app_replies WHERE id=?', [$id]);
                refresh_topic_stats((int)$record['topic_id']);
                content_delete_notify($record, true, (int)$record['topic_id']);
                return;
            }
            if ($table === 'topics' && $with_replies) {
                foreach (q("SELECT * FROM app_replies WHERE topic_id=?", [$id])->fetchAll() as $reply) {
                    floor_index_record((int)$reply['topic_id'], (int)$reply['id'], (int)$reply['created_at']);
                    q('DELETE FROM app_replies WHERE id=?', [(int)$reply['id']]);
                }
            }
            q('DELETE FROM ' . $tables[$table] . ' WHERE id=?', [$id]);
            if ($table === 'topics') content_delete_notify($record, false);
            if ($table === 'users') {
                foreach ($affected_topics as $topic) refresh_topic_stats((int)$topic['topic_id']);
            }
        });
        return;
    }
    tx(fn() => q('DELETE FROM ' . $tables[$table] . ' WHERE id=?', [$id]));
    if ($table === 'forums') forums_cache(true);
    else groups_cache(true);
}
function login_page(): void
{
    if (uid()) go(consume_auth_return_url());
    if (is_post_request()) {
        $u = one("SELECT id,password FROM app_users WHERE username=?", [post('username', DB_STRING_MAX_LENGTH)]);
        if ($u && password_verify((string)$_POST['password'], $u['password'])) {
            complete_login((int)$u['id']);
            return;
        }
        err('用户名或密码错误');
    }
    $sidebar = sidebar_stack_html([
        sidebar_notice_card_html('登录注意事项', ['请使用用户名登录。', '密码区分大小写。', '公共设备登录后请及时退出。']),
    ]);
    render_page('login.html.twig', ['sidebar' => $sidebar], '登录');
}
function register_page(): void
{
    if (uid()) go(consume_auth_return_url());
    if (setting('allow_register', '1') !== '1') err('注册已关闭');
    if (is_post_request()) {
        if (array_key_exists('id', $_GET) || array_key_exists('id', $_POST)) err('参数错误');
        save_user();
        $user_id = (int)($GLOBALS['__last_saved_user_id'] ?? 0);
        if ($user_id <= 0) err('注册失败');
        start_cookie_login($user_id);
        go(consume_auth_return_url());
    }
    $sidebar = sidebar_stack_html([
        sidebar_notice_card_html('注册注意事项', ['邮箱信息不会公开。', '请不要使用保留用户名或冒充他人。']),
    ]);
    render_page('register.html.twig', ['sidebar' => $sidebar, 'username_attributes' => length_attributes('username')], '注册');
}
function profile_page(): void
{
    need_login();
    $u = me();
    $profile_tab_input = $_GET['tab'] ?? 'profile';
    $profile_tab = is_string($profile_tab_input) ? cut(trim($profile_tab_input), 64) : 'profile';
    if ($profile_tab === '') $profile_tab = 'profile';
    $default_profile_tabs = [
        'home' => ['label' => '我的主页', 'href' => route_url('user', ['id' => (int)$u['id']])],
        'profile' => ['label' => '个人设置', 'href' => route_url('profile')],
    ];
    $profile_tabs = $default_profile_tabs;
    if (!array_key_exists($profile_tab, $profile_tabs)) $profile_tab = 'profile';

    if ($profile_tab === 'profile' && is_post_request()) {
        save_user(uid());
        set_flash('个人资料已保存');
        go(route_url('profile'));
    }
    render_page('profile.html.twig', [
        'u' => $u,
        'profile_tabs' => $profile_tabs,
        'profile_tab' => $profile_tab,
        'registered_at' => date('Y-m-d H:i', (int)$u['created_at']),
    ], '个人设置');
}
function user_page(): void
{
    $username = is_string($_GET['username'] ?? null) ? cut(trim((string)$_GET['username']), DB_STRING_MAX_LENGTH) : '';
    if ($username === '' && uid() && id() === uid()) $user = me();
    else {
        $user = $username !== ''
            ? one("SELECT id,username,bio,group_id,is_muted FROM app_users WHERE username=?", [$username])
            : row('app_users', 'id', id());
    }
    $user = $user ?: err('你访问的页面不存在', 404);
    $g = group_by_id((int)$user['group_id']) ?: ['name' => '用户'];
    $user['group_name'] = $g['name'];
    topic_index_page(null, $user);
}
function topic_index_sort(bool $profile): string
{
    if ($profile) return 'post';
    if (!array_key_exists('sort', $_GET)) return (($_COOKIE['__topic_index_sort'] ?? 'comment') === 'post') ? 'post' : 'comment';
    $sort = $_GET['sort'] === 'post' ? 'post' : 'comment';
    app_cookie('__topic_index_sort', $sort, time() + COOKIE_TTL, false);
    $_COOKIE['__topic_index_sort'] = $sort;
    return $sort;
}
function topic_index_data(int $fid, ?array $user, string $profile_tab, string $query, string $search_field, string $sort, int $page, int $size, bool $tab_allowed = true, string $denied_notice = ''): array
{
    $profile_uid = (int)($user['id'] ?? 0);
    $offset = ($page - 1) * $size;
    $ctx = ['forum_id' => $fid, 'user' => $user, 'profile_tab' => $profile_tab, 'query' => $query, 'search_field' => $search_field, 'sort' => $sort, 'page' => $page, 'page_size' => $size, 'offset' => $offset];
    if ($profile_uid && !$tab_allowed) {
        return ['rows' => [], 'total' => 0, 'profile_empty' => $denied_notice !== '' ? $denied_notice : '该内容不对外开放', 'unread_total' => 0, 'simple_pagination' => false, 'has_next_page' => false];
    }
    $simple_pagination = false;
    $has_next_page = false;
    $profile_empty = '';
    $unread_total = 0;
    $where_parts = [];
    $params = [];
    if ($fid) {
        $where_parts[] = 'forum_id=?';
        $params[] = $fid;
    }
    if ($query !== '' && $search_field !== 'reply') {
        [$condition, $search_params] = content_search_condition($query, $search_field);
        $where_parts[] = '(' . $condition . ')';
        $params = array_merge($params, $search_params);
    }
    $where = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';
    if ($profile_uid && $profile_tab === 'notifications') {
        $total = notifications_total($profile_uid);
        $unread_total = notifications_unread_total($profile_uid);
        $rows = notifications_list($profile_uid, $size, $offset);
    } elseif ($profile_uid && $profile_tab === 'replies') {
        $total = (int)val("SELECT COUNT(*) FROM app_replies WHERE user_id=?", [$profile_uid]);
        $reply_rows = q("SELECT id,topic_id,user_id,body,created_at FROM app_replies WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT ? OFFSET ?", [$profile_uid, $size, $offset])->fetchAll();
        $rows = topic_list_rows_for_replies($reply_rows);
    } elseif ($query !== '' && $search_field === 'reply') {
        [$reply_condition, $reply_params] = content_search_condition($query, 'reply');
        $reply_where = '(' . $reply_condition . ')' . ($profile_uid ? ' AND user_id=?' : '');
        $reply_rows = q("SELECT id,topic_id,user_id,body,created_at FROM app_replies WHERE $reply_where ORDER BY created_at DESC,id DESC LIMIT ? OFFSET ?", array_merge($reply_params, $profile_uid ? [$profile_uid] : [], [$size + 1, $offset]))->fetchAll();
        $total = 0;
        $simple_pagination = true;
        $has_next_page = count($reply_rows) > $size;
        $rows = topic_list_rows_for_replies(array_slice($reply_rows, 0, $size));
    } else {
        if ($profile_uid) {
            $where = $where ? $where . ' AND user_id=?' : 'WHERE user_id=?';
            $params[] = $profile_uid;
        }
        $total = $query !== '' ? 0 : (($fid || $profile_uid) ? (int)val("SELECT COUNT(*) FROM app_topics $where", $params) : (int)val('SELECT COUNT(*) FROM app_topics'));
        $order = $sort === 'post' ? 'created_at DESC,id DESC' : 'last_reply_at DESC,id DESC';
        $index_hint = '';
        $query_size = $query !== '' ? $size + 1 : $size;
        $rows = q("SELECT " . topic_list_select_columns() . " FROM app_topics$index_hint $where ORDER BY $order LIMIT ? OFFSET ?", array_merge($params, [$query_size, $offset]))->fetchAll();
        if ($query !== '') {
            $simple_pagination = true;
            $has_next_page = count($rows) > $size;
            $rows = array_slice($rows, 0, $size);
        }
        $pinned_ids = (!$profile_uid && !$fid && $query === '' && $page === 1) ? pinned_topic_ids() : [];
        if ($pinned_ids) {
            $pinned_rows = rows_by_ids('app_topics', $pinned_ids, topic_list_select_columns());
            $ordered = [];
            foreach ($pinned_ids as $pinned_id) {
                if (isset($pinned_rows[$pinned_id])) $ordered[] = $pinned_rows[$pinned_id] + ['is_pinned' => 1];
            }
            $rows = array_merge($ordered, array_values(array_filter($rows, fn($row) => !isset($pinned_rows[(int)$row['id']]))));
        }
        $rows = attach_topic_list_users($rows);
    }
    $data = [
        'rows' => $rows,
        'total' => $total,
        'profile_empty' => $profile_empty,
        'unread_total' => $unread_total,
        'simple_pagination' => $simple_pagination,
        'has_next_page' => $has_next_page,
    ];
    return $data;
}
function topic_index_page(?array $filter_forum = null, ?array $filter_user = null): void
{
    $fid = (int)($filter_forum['id'] ?? 0);
    $profile_uid = (int)($filter_user['id'] ?? 0);
    $own_profile = $profile_uid && uid() === $profile_uid;
    $url = function (string $query) use ($profile_uid, $fid): string {
        parse_str($query, $params);
        if ($profile_uid) return route_url('user', ['id' => $profile_uid] + $params);
        if ($fid) return route_url('forum', ['id' => $fid] + $params);
        return route_url('home', $params);
    };
    $p = max(1, (int)($_GET['p'] ?? 1));
    $size = max(1, (int)setting('topics_per_page', '30'));
    $off = ($p - 1) * $size;
    $profile_tab = (string)($_GET['tab'] ?? 'topics');
    $sort = topic_index_sort($profile_uid > 0);
    $q = '';
    $search_field = 'title';
    $profile_tabs = [
        'topics' => ['label' => '主题', 'href' => $url('tab=topics')],
        'replies' => ['label' => '回帖', 'href' => $url('tab=replies')],
    ];
    if ($own_profile) $profile_tabs['notifications'] = ['label' => '通知', 'href' => $url('tab=notifications')];
    if ($profile_uid) {
        if (!array_key_exists($profile_tab, $profile_tabs)) $profile_tab = 'topics';
    }
    if ($own_profile) {
        $profile_tabs['profile_settings'] = ['label' => '设置', 'href' => route_url('profile'), 'class' => 'tab-mobile-action'];
        if (can_access_admin()) $profile_tabs['admin'] = ['label' => '后台', 'href' => route_url('admin'), 'class' => 'tab-mobile-action'];
    }
    $profile_tab_allowed = true;
    $profile_tab_notice = '';
    $data = topic_index_data($fid, $filter_user, $profile_tab, $q, $search_field, $sort, $p, $size, $profile_tab_allowed, $profile_tab_notice);
    if ($profile_uid && $profile_tab_allowed && $profile_tab === 'notifications') mark_notifications_read($profile_uid, (int)$data['unread_total']);
    $title = $profile_uid ? $filter_user['username'] : ($filter_forum ? $filter_forum['name'] : '首页');
    $seo = [];
    if ($profile_uid) $seo = page_seo('user', ['id' => $profile_uid], (string)($filter_user['bio'] ?? $filter_user['username']));
    elseif ($filter_forum) $seo = page_seo('forum', ['id' => $fid], (string)($filter_forum['description'] ?? $filter_forum['name']));
    $search_query = $q !== '' ? 'q=' . rawurlencode($q) . '&field=' . $search_field . '&' : '';
    $tab_items = ['comment' => ['label' => '新评论', 'href' => $url($search_query . 'sort=comment')], 'post' => ['label' => '新帖子', 'href' => $url($search_query . 'sort=post')]];
    $list_rows = [];
    foreach (($data['rows'] ?? []) as $t) {
        $t['time'] = (int)($t['list_time'] ?? $t['my_reply_at'] ?? ($sort === 'post' ? $t['created_at'] : ($t['last_reply_at'] ?: $t['created_at'])));
        $t['forum'] = forum_by_id((int)$t['forum_id']) ?: ['id' => 0, 'name' => ''];
        $list_rows[] = $t;
    }
    $notification_rows = [];
    if ($profile_uid && $profile_tab_allowed && $profile_tab === 'notifications') {
        foreach ($data['rows'] as $i => $n) {
            $notification_rows[] = ['divider' => (int)$data['unread_total'] > 0 && $off + $i === (int)$data['unread_total'], 'row' => $n];
        }
    }
    $page_query = $search_query . ($profile_uid ? 'tab=' . $profile_tab : 'sort=' . $sort);
    $pagination = $data['simple_pagination'] ? simple_paginate($p > 1, $data['has_next_page'], $p, $url($page_query)) : paginate((int)$data['total'], $p, $size, $url($page_query));
    $is_home_first_page = !$profile_uid && !$filter_forum && $q === '' && $p === 1;
    $empty_text = $q !== '' ? '没有找到匹配的' . ($search_field === 'reply' ? '回帖' : '主题') : ((string)$data['profile_empty'] !== '' ? (string)$data['profile_empty'] : ($profile_uid ? ($profile_tab === 'replies' ? '暂无回帖' : '暂无主题') : '暂无主题'));
    render_page('home.html.twig', [
        'profile_uid' => $profile_uid,
        'profile_tab' => $profile_tab,
        'profile_tab_allowed' => $profile_tab_allowed,
        'profile_tabs' => $profile_tabs,
        'q' => $q,
        'fid' => $fid,
        'sort' => $sort,
        'mobile_forums' => $q === '' ? array_values(array_filter(forums_cache(), fn($f) => forum_group_allowed($f, 'allow_view_groups'))) : [],
        'tab_items' => $tab_items,
        'rows' => $data['rows'],
        'list_rows' => $list_rows,
        'notification_rows' => $notification_rows,
        'empty_text' => $empty_text,
        'pagination' => $pagination,
        'sidebar' => sidebar_stack_html([sidebar_user_card_html($profile_uid ? $filter_user : null, false, $fid)]),
        'shell_class' => $profile_uid ? 'profile-mobile-sidebar' . ($own_profile ? ' profile-mobile-sidebar-own' : '') : ($is_home_first_page ? 'home-mobile-sidebar' : ''),
    ], $title, $seo);
}
function home_page(): void
{
    topic_index_page();
}
function forum_page(): void
{
    $fid = id();
    $f = forum_by_id($fid) ?: err('你访问的页面不存在', 404);
    if (!forum_group_allowed($f, 'allow_view_groups')) err('无权限');
    topic_index_page($f);
}
function topic_page_replies(array $topic, int $page, int $size, int $offset, bool $reply_desc): array
{
    $ctx = ['topic' => $topic, 'page' => $page, 'page_size' => $size, 'offset' => $offset, 'reply_order' => $reply_desc ? 1 : 0];
    $order = $reply_desc ? 'created_at DESC,id DESC' : 'created_at,id';
    $replies = q("SELECT * FROM app_replies WHERE topic_id=? ORDER BY $order LIMIT ? OFFSET ?", [(int)$topic['id'], $size, $offset])->fetchAll();
    $posts = attach_users(array_merge([$topic], $replies));
    $topic = array_shift($posts);
    $replies = $posts;
    $data = ['topic' => $topic, 'replies' => $replies];
    return $data;
}
function topic_page_view(array $view): array
{
    extract($view, EXTR_SKIP);
    $topic_ops = uid() ? quote_reply_action($t) : '';
    if (can_manage_topic($t)) $topic_ops .= '<a class="icon-action icon-edit" href="' . h(route_url('topic_edit', ['id' => (int)$t['id']])) . '" title="编辑"><span>编辑</span></a>';
    $reply_rows = [];
    foreach ($replies as $i => $r) {
        if (trim((string)$r['body']) === '') continue;
        $reply_floor = (int)($r['reply_floor'] ?? ($reply_desc ? (int)$t['reply_count'] - $off - $i : $off + $i + 1));
        $reply_ops = uid() ? quote_reply_action($r, $reply_floor) : '';
        if (can_manage_reply($r)) $reply_ops .= '<a class="icon-action icon-edit" href="' . h(route_url('reply_edit', ['id' => (int)$r['id']])) . '" title="编辑"><span>编辑</span></a>';
        $reply_rows[] = $r + ['ops' => $reply_ops, 'floor' => $reply_floor, 'highlight' => $floor > 0 ? $reply_floor === $floor : (int)$r['id'] === $replyid];
    }
    $can_reply_forum = forum_group_allowed($forum, 'allow_reply_groups');
    return [
        't' => $t,
        'forum' => $forum,
        'topic_url' => route_url('topic', ['id' => (int)$t['id']]),
        'topic_ops' => $topic_ops,
        'page' => $p,
        'replies' => $replies,
        'reply_rows' => $reply_rows,
        'pagination' => paginate((int)$t['reply_count'], $p, $size, route_url('topic', ['id' => (int)$t['id']]), false),
        'can_reply_forum' => $can_reply_forum,
        'can_reply' => can_speak() && $can_reply_forum,
        'current_uid' => uid(),
        'reply_status' => uid() ? (can_speak() ? ($can_reply_forum ? '说两句' : '无回帖权限') : '禁止发言') : '登录后回复',
    ];
}
function topic_page(): void
{
    if (!id() && id('replyid')) {
        $reply = row('app_replies', 'id', id('replyid')) ?: err('你访问的帖子可能已经删除', 404);
        go(route_url('topic', ['id' => (int)$reply['topic_id'], 'replyid' => id('replyid')]));
    }
    $t = row('app_topics', 'id', id()) ?: err('你访问的帖子可能已经删除', 404);
    $forum = forum_by_id((int)$t['forum_id']);
    if ($forum && !forum_group_allowed($forum, 'allow_view_groups')) err('无权限');
    if (mark_viewed((int)$t['id'])) {
        q("UPDATE app_topics SET view_count=view_count+1 WHERE id=?", [(int)$t['id']]);
        $t['view_count'] = (int)$t['view_count'] + 1;
    }
    $size = max(1, (int)setting('replies_per_page', '50'));
    $replyid = id('replyid');
    $floor = id('floor');
    $reply_desc = (int)($t['reply_order'] ?? 0) === 1;
    if ($floor > 0) {
        $gap_count = count(topic_floor_index((int)$t['id']));
        if ($floor > (int)$t['reply_count'] + $gap_count) {
            $floor = (int)$t['reply_count'] + $gap_count;
            $_GET['floor'] = (string)$floor;
        }
        $anchor_pos = floor_live_position((int)$t['id'], $floor);
        $_GET['p'] = (string)max(1, $reply_desc ? (int)(((int)$t['reply_count'] - $anchor_pos) / $size) + 1 : (int)(($anchor_pos - 1) / $size) + 1);
    } elseif ($replyid > 0) {
        $reply = row('app_replies', 'id', $replyid);
        if ($reply && (int)$reply['topic_id'] !== (int)$t['id']) $reply = null;
        if ($reply) {
            $anchor_pos = floor_live_position((int)$t['id'], reply_position_floor((int)$t['id'], (int)$reply['created_at'], $replyid));
            $_GET['p'] = (string)max(1, $reply_desc ? (int)(((int)$t['reply_count'] - $anchor_pos) / $size) + 1 : (int)(($anchor_pos - 1) / $size) + 1);
        } else {
            err('你访问的帖子可能已经删除', 404);
        }
    }
    $p = max(1, (int)($_GET['p'] ?? 1));
    $off = ($p - 1) * $size;
    $page_data = topic_page_replies($t, $p, $size, $off, $reply_desc);
    $t = $page_data['topic'];
    $replies = apply_reply_floors($page_data['replies'], $t, $p, $size, $reply_desc);
    $view = compact('t', 'forum', 'replies', 'p', 'size', 'off', 'reply_desc', 'replyid', 'floor') + ['topic' => $t, 'page' => $p, 'page_size' => $size, 'offset' => $off, 'reply_order' => $reply_desc ? 1 : 0];
    render_page('topic.html.twig', topic_page_view($view), $t['title'] . ' - ' . $forum['name'], page_seo('topic', ['id' => (int)$t['id']], (string)$t['body']));
}
function topic_edit_page(): void
{
    need_speak();
    $topic_id = id();
    $editing = $topic_id > 0;
    $t = ['id' => 0, 'forum_id' => id('fid') ?: default_post_forum_id(), 'title' => '', 'body' => '', 'user_id' => uid()];
    if ($editing) {
        $t = row('app_topics', 'id', $topic_id) ?: err('主题不存在');
        if (!can_manage_topic($t)) err('无权限');
    }
    if (is_post_request()) go(route_url('topic', ['id' => save_topic()]));
    $title = $editing ? '编辑主题' : '发表主题';
    $topic_ops = '';
    if ($editing && can_manage()) {
        $style = topic_title_color((string)($t['highlight_style'] ?? ''));
        $is_bold = topic_title_is_bold((string)($t['highlight_style'] ?? ''));
        $is_pinned = in_array((int)$t['id'], pinned_topic_ids(), true);
        $colors = ['#d94b4b', '#d97706', '#16a34a', '#2563eb', '#7c3aed'];
        $swatches = '<div class="topic-color-swatches">';
        foreach ($colors as $color) $swatches .= '<button class="topic-color-swatch' . ($style === $color ? ' active' : '') . '" type="button" data-topic-color="' . h($color) . '" style="background:' . h($color) . '" aria-label="' . h($color) . '"></button>';
        $swatches .= '<button class="topic-color-swatch topic-color-clear' . ($style === '' ? ' active' : '') . '" type="button" data-topic-color="" aria-label="取消高亮"></button>';
        $swatches .= '</div>';
        $pin_options = '<option value="pin"' . ($is_pinned ? '' : ' selected') . '>置顶</option><option value="unpin"' . ($is_pinned ? ' selected' : '') . '>取消置顶</option>';
        $bold_options = '<option value="bold"' . ($is_bold ? '' : ' selected') . '>加粗</option><option value="unbold"' . ($is_bold ? ' selected' : '') . '>取消加粗</option>';
        $topic_ops = '<label class="grid topic-action-field"><span>操作</span><select name="topic_action" data-topic-action><option value="">不操作</option><option value="delete_topic">删除主题及回帖</option><option value="pin">置顶</option><option value="highlight">高亮</option><option value="bold">加粗</option><option value="mute_author">禁言作者</option></select></label><label class="grid topic-secondary-field is-hidden" data-topic-action-secondary="pin"><span>置顶</span><select name="topic_pin_action">' . $pin_options . '</select></label><label class="grid topic-highlight-field is-hidden" data-topic-action-secondary="highlight" data-topic-highlight-wrap><span>颜色</span><input type="hidden" name="highlight_style" value="' . h($style) . '" data-topic-highlight-value>' . $swatches . '</label><label class="grid topic-secondary-field is-hidden" data-topic-action-secondary="bold"><span>加粗</span><select name="topic_bold_action">' . $bold_options . '</select></label>';
    }
    $reply_order = $editing ? select_input('回帖排序', 'reply_order', (string)(int)($t['reply_order'] ?? 0), ['0' => '发帖时间顺序', '1' => '发帖时间倒序']) : '';
    render_page('topic_edit.html.twig', [
        'title' => $title,
        't' => $t,
        'reply_order' => $reply_order,
        'topic_ops' => $topic_ops,
        'loading_text' => $editing ? '正在保存' : '正在发帖',
    ], $title);
}
function reply_edit_page(): void
{
    need_speak();
    $reply_id = id();
    $editing = $reply_id > 0;
    $r = ['id' => 0, 'topic_id' => id('topic_id'), 'body' => '', 'user_id' => uid()];
    if ($editing) {
        $r = row('app_replies', 'id', $reply_id) ?: err('回复不存在');
        if (!can_manage_reply($r)) err('无权限');
        if (is_post_request() && ($_POST['do'] ?? '') === 'mute_author') {
            if (!can_manage()) err('无权限');
            if ((int)$r['user_id'] === 1) err('不能操作超级管理员');
            q("UPDATE app_users SET is_muted=1 WHERE id=?", [(int)$r['user_id']]);
            go(route_url('topic', ['id' => (int)$r['topic_id'], 'replyid' => (int)$r['id']]));
        }
        if (is_post_request() && ($_POST['do'] ?? '') === 'delete') {
            if (trim((string)$r['body']) === '') err('回复已删除');
            tx(function () use ($r) {
                floor_index_record((int)$r['topic_id'], (int)$r['id'], (int)$r['created_at']);
                q('DELETE FROM app_replies WHERE id=?', [(int)$r['id']]);
                refresh_topic_stats((int)$r['topic_id']);
                content_delete_notify($r, true, (int)$r['topic_id']);
            });
            go(route_url('topic', ['id' => (int)$r['topic_id']]));
        }
        if (trim((string)$r['body']) === '') err('回复已删除，无法编辑');
    }
    if (is_post_request()) {
        $saved = save_reply();
        if (!empty($saved['redirect'])) go($saved['redirect']);
        if (ajax_request() && $editing) go(route_url('topic', ['id' => $saved['topic_id'], 'replyid' => $saved['reply_id']]));
        if (ajax_request()) {
            $row = row('app_replies', 'id', $saved['reply_id']) ?: err('回复不存在');
            $row = attach_users([$row])[0];
            $topic = row('app_topics', 'id', $saved['topic_id']) ?: ['view_count' => 0, 'reply_count' => 0, 'reply_order' => 0];
            $floor = reply_position_floor((int)$topic['id'], (int)$row['created_at'], (int)$row['id']);
            $ops = quote_reply_action($row, $floor);
            if (can_manage_reply($row)) $ops .= '<a class="icon-action icon-edit" href="' . h(route_url('reply_edit', ['id' => (int)$row['id']])) . '" title="编辑"><span>编辑</span></a>';
            if ((int)($topic['reply_order'] ?? 0) === 1) go(route_url('topic', ['id' => $saved['topic_id'], 'replyid' => $saved['reply_id']]));
            json_response(['ok' => 1, 'html' => topic_post_row($row, $row['body'], (int)$row['created_at'], $ops, '', '', false, ['reply_position' => $floor]), 'stats_html' => topic_stats_html((int)$topic['view_count'], (int)$topic['reply_count'])]);
        }
        go(route_url('topic', ['id' => $saved['topic_id'], 'replyid' => $saved['reply_id']]));
    }
    $reply_ops = (int)$r['id'] > 0 ? '<label class="grid reply-action-field"><span>操作</span><select name="do" data-reply-actions aria-label="回复操作"><option value="">不操作</option><option value="delete" data-confirm="确定删除该回复？">删除</option>' . (can_manage() ? '<option value="mute_author" data-confirm="确定禁言该作者？">禁言作者</option>' : '') . '</select></label>' : '';
    render_page('reply_edit.html.twig', ['r' => $r, 'reply_ops' => $reply_ops], '编辑回复');
}
function admin_tabs(string $tab): string
{
    $items = [];
    $items = [];
    foreach (['settings' => '设置', 'forums' => '版块', 'groups' => '用户组'] as $key => $label) {
        $items[$key] = ['label' => $label, 'href' => admin_url(['tab' => $key])];
    }
    return tab_bar_html($items, $tab, 'admin-tabs', 'admin.tabs');
}
function form_error_route(): void
{
    $raw = base64_decode((string)($_COOKIE['__form_error'] ?? ''), true);
    $data = is_string($raw) ? json_decode($raw, true) : [];
    app_cookie('__form_error', '', time() - 3600);
    error_page('操作失败', trim((string)(is_array($data) ? ($data['message'] ?? '') : '') ?: '操作失败'));
}
function logout_route(): void
{
    require_post();
    clear_auth_cookie();
    go(route_url('home'));
}
function delete_route(): void
{
    require_post(); need_login();
    $type = (string)($_POST['type'] ?? '');
    $row = Admin::deletable_post_row($type, id());
    if (!$row || !in_array($type, ['topics', 'replies'], true)) err('参数错误');
    if (($type === 'topics' && !can_manage_topic($row)) || ($type === 'replies' && !can_manage_reply($row))) err('无权限');
    del($type, id());
    if ((string)($_POST['back'] ?? '') === 'topic') go(route_url('topic', ['id' => (int)($_POST['tid'] ?? 0)]));
    go(route_url('home'));
}
function mobile_menu_route(): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo mobile_menu_content_html(me());
}
function core_routes(): array
{
    return [
        'home' => 'home_page',
        'search' => [Search::class, 'page'],
        'mobile_menu' => 'mobile_menu_route',
        'forum' => 'forum_page',
        'topic' => 'topic_page',
        'user' => 'user_page',
        'login' => 'login_page',
        'logout' => 'logout_route',
        'register' => 'register_page',
        'form_error' => 'form_error_route',
        'profile' => 'profile_page',
        'topic_edit' => 'topic_edit_page',
        'reply_edit' => 'reply_edit_page',
        'delete' => 'delete_route',
        'admin' => [Admin::class, 'route'],
    ];
}
parse_path_route();
if (!db_schema_ready()) Setup::bootstrap_run();
check();
need_site_access();
try {
    if (($_GET['__route_not_found'] ?? '') === '1') {
        err(($_GET['__route_not_found_kind'] ?? '') === 'topic' ? '你访问的帖子可能已经删除' : '你访问的页面不存在', 404);
    }
    $route = (string)($_GET['a'] ?? 'home');
    $handler = core_routes()[$route] ?? null;
    if ($handler !== null) $handler();
    else err('你访问的页面不存在', 404);
} catch (Throwable $e) {
    debug_log_write('未捕获异常', $e);
    if (uid() === 1) {
        $message = exception_detail($e);
    } elseif (database_error($e)) {
        $message = database_error_message();
    } else {
        $message = '操作失败';
    }
    err($message);
}
