<?php
declare(strict_types=1);
use app\optional\Admin;
use app\optional\Bootstrap;
use app\optional\Db\Database;
use app\optional\Db\SqlitePdo;
use app\optional\DebugLog;
use app\optional\Markdown;
use app\optional\Model\Forum;
use app\optional\Model\Group;
use app\optional\Model\Notification;
use app\optional\Model\Reply;
use app\optional\Model\Setting;
use app\optional\Model\Topic;
use app\optional\Model\TopicDeletion;
use app\optional\Model\User;
use app\optional\Search;
if (!is_file(__DIR__ . '/vendor/autoload.php')) {
    header('Content-Type: text/plain; charset=utf-8');
    exit("缺少依赖：请先在项目根目录执行 composer install 后再运行本程序。\n");
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
    $db = new SqlitePdo('sqlite:' . $config['path'], null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    foreach (['PRAGMA journal_mode=WAL', 'PRAGMA synchronous=NORMAL', 'PRAGMA temp_store=MEMORY', 'PRAGMA busy_timeout=5000', 'PRAGMA foreign_keys=ON'] as $sql) $db->exec($sql);
    return $db;
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
function topic_rows_by_ids(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    return Topic::whereIn('id', $ids)->get(topic_list_columns())->keyBy('id')
        ->map(static fn(Topic $topic): array => $topic->toArray())->all();
}
function user_summary_defaults(string $username = ''): array
{
    return ['username' => $username, 'group_id' => 0, 'is_muted' => 0];
}
/** @return array<int, User> 以 id 为键的用户摘要，用于一次性补齐列表里的用户名 */
function user_summaries(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    return User::whereIn('id', $ids)->get(['id', 'username', 'group_id', 'is_muted'])->keyBy('id')->all();
}
function attach_users(array $rows, string $key = 'user_id', string $fallback = '用户删除'): array
{
    $users = user_summaries(array_column($rows, $key));
    foreach ($rows as &$row) $row += ($users[(int)($row[$key] ?? 0)] ?? null)?->only(['username', 'group_id', 'is_muted']) ?? user_summary_defaults($fallback);
    unset($row);
    return $rows;
}
function attach_topic_list_users(array $rows): array
{
    $users = user_summaries(array_merge(array_column($rows, 'user_id'), array_column($rows, 'last_reply_user_id')));
    foreach ($rows as &$row) {
        $row += ($users[(int)($row['user_id'] ?? 0)] ?? null)?->only(['username', 'group_id', 'is_muted']) ?? user_summary_defaults();
        $last_reply_uid = (int)($row['last_reply_user_id'] ?? 0);
        $row['last_reply_username'] = $last_reply_uid > 0 ? (string)(($users[$last_reply_uid] ?? null)?->username ?? '') : '';
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
    return $GLOBALS['__settings_cache'] ??= array_merge(default_settings(), Setting::query()->pluck('value', 'name')->all());
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
    $rows = [];
    foreach ($values as $name => $value) $rows[] = ['name' => (string)$name, 'value' => (string)$value];
    Setting::upsert($rows, ['name'], ['value']);
    if (is_array($GLOBALS['__settings_cache'] ?? null)) {
        foreach ($values as $name => $value) $GLOBALS['__settings_cache'][(string)$name] = (string)$value;
    }
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
    DebugLog::write($message, $e);
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
    $wait = $seconds - (time() - (int)(User::whereKey(uid())->value('last_post_at') ?? 0));
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
    return $GLOBALS['__forums_cache'] ??= Forum::query()->orderBy('sort')->orderBy('id')->get()->toArray();
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
    return $GLOBALS['__groups_cache'] ??= Group::query()->orderBy('id')->get()->toArray();
}
function group_by_id(int $id): ?array
{
    if (!is_array($GLOBALS['__group_by_id_map'] ?? null)) {
        $GLOBALS['__group_by_id_map'] = [];
        foreach (groups_cache() as $group) $GLOBALS['__group_by_id_map'][(int)$group['id']] = $group;
    }
    return $GLOBALS['__group_by_id_map'][$id] ?? null;
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
    Notification::create([
        'recipient_id' => $recipient_id,
        'sender_id' => $sender_id,
        'kind' => $kind,
        'content' => $content,
        'topic_id' => $topic_id > 0 ? $topic_id : null,
        'reply_id' => $reply_id > 0 ? $reply_id : null,
        'created_at' => now(),
        'read_at' => 0,
    ]);
    // 未读角标直接在 SQL 里自增，避免读改写之间丢计数
    User::whereKey($recipient_id)->increment('unread_notifications');
    return true;
}
function create_mention_notifications(int $topic_id, int $reply_id, string $body, int $sender_id): void
{
    $topic = Topic::find($topic_id);
    if (!$topic) return;
    $usernames = notification_targets($body);
    $targets = [];
    if ($usernames) {
        foreach (User::whereIn('username', $usernames)->pluck('id') as $id) $targets[(int)$id] = true;
    }
    unset($targets[$sender_id]);
    $excerpt = notification_excerpt($body);
    foreach (array_keys($targets) as $uid) {
        create_notification((int)$uid, $sender_id, 'mention', '在主题《' . (string)$topic->title . '》中提到你：' . $excerpt, $topic_id, $reply_id);
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
    return Notification::where('recipient_id', $uid)->orderByDesc('created_at')->orderByDesc('id')
        ->limit($limit)->offset($offset)->with('sender:id,username')->get()
        ->map(static fn(Notification $n): array => $n->getAttributes() + ['sender_username' => (string)($n->sender?->username ?? '')])
        ->all();
}
function notifications_total(int $uid): int
{
    return Notification::where('recipient_id', $uid)->count();
}
function notifications_unread_total(int $uid): int
{
    $m = me();
    if ($m && (int)$m['id'] === $uid) return (int)($m['unread_notifications'] ?? 0);
    return Notification::where('recipient_id', $uid)->where('read_at', 0)->count();
}
function mark_notifications_read(int $uid, int $unread): void
{
    if ($unread <= 0) return;
    Notification::where('recipient_id', $uid)->where('read_at', 0)->update(['read_at' => now()]);
    User::whereKey($uid)->update(['unread_notifications' => 0]);
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
    $u = User::find($parts['id']);
    $expected = $u ? hash_hmac('sha256', $parts['id'] . '|' . $parts['expire'], (string)$u->password) : '';
    if (!$u || !hash_equals($expected, $parts['signature'])) {
        clear_auth_cookie();
        return null;
    }
    $g = group_by_id((int)$u->group_id);
    if (!$g) {
        $fallback_group_id = (int)setting('default_group_id', '2');
        $g = group_by_id($fallback_group_id);
        if (!$g) {
            clear_auth_cookie();
            return null;
        }
        User::whereKey($u->id)->where('group_id', $u->group_id)->update(['group_id' => $fallback_group_id]);
        $u->group_id = $fallback_group_id;
    }
    $GLOBALS['__request_uid'] = (int)$u->id;
    return $GLOBALS['__me_cache'] = $u->toArray() + ['group_name' => $g['name'], 'group_id' => (int)($u->group_id ?? 0), 'is_muted' => (int)($u->is_muted ?? 0), 'allow_manage' => (int)($g['allow_manage'] ?? 0), 'allow_admin' => (int)($g['allow_admin'] ?? 0)];
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
    $user = User::find($user_id) ?: err('用户不存在');
    csrf_cookie_clear();
    auth_cookie_set($user_id, (string)$user->password);
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
function post(string $k, int $max = 0): string
{
    $v = trim((string)($_POST[$k] ?? ''));
    return $max ? cut($v, $max) : $v;
}
function id(string $k = 'id'): int
{
    return max(0, (int)($_GET[$k] ?? $_POST[$k] ?? 0));
}
function require_post(): void
{
    if (!is_post_request()) err('请求方式错误');
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
function admin_url(array $params = []): string
{
    return route_url('admin', $params);
}
/** 路由名即路径首段：home 是站点根，其余形如 /topic/12、/admin?tab=groups */
function route_url(string $a = 'home', array $params = []): string
{
    unset($params['a']);
    if ($a === 'home') return $params ? append_url_query(app_url(''), $params) : app_url();
    $segments = [rawurlencode($a)];
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
function markdown_html(string $text, int $topic_id = 0): string
{
    return Markdown::html($text, $topic_id);
}
/** 通知正文的富文本渲染：Markdown + 把《主题标题》整体做成一个链接 */
function notification_content_html(array $n): string
{
    $body = (string)($n['content'] ?? '');
    $content_html = markdown_html($body, (int)($n['topic_id'] ?? 0));
    if ((int)($n['topic_id'] ?? 0) <= 0 && (int)($n['reply_id'] ?? 0) <= 0) return $content_html;
    $url = notification_link($n);
    if ($url === '') return $content_html;
    $title_marker = "\x1ENOTIFICATION_TOPIC_TITLE\x1E";
    $topic_title = '';
    $source = preg_replace_callback('/《((?:[^《》]|(?R))*)》/u', static function (array $match) use (&$topic_title, $title_marker): string {
        $topic_title = $match[1];
        return '《' . $title_marker . '》';
    }, $body, 1, $count);
    if (is_string($source) && $count > 0) {
        // 标题整体做成一个链接，标题里的 @ 提及就不会产生嵌套的 a 标签
        return str_replace($title_marker, '<a href="' . h($url) . '">' . h($topic_title) . '</a>', markdown_html($source, (int)($n['topic_id'] ?? 0)));
    }
    $view_link = '<a href="' . h($url) . '">查看主题</a>';
    $content_html = preg_replace('/<\/p>\s*$/u', ' ' . $view_link . '</p>', $content_html, 1, $paragraph_count) ?? $content_html;
    if ($paragraph_count < 1) $content_html .= ' ' . $view_link;
    return $content_html;
}
/** 字段的长度上下限，模板据此决定是否输出 minlength / maxlength */
function length_limits(string $name): array
{
    return match ($name) {
        'username' => [length_limit('username', 'min'), length_limit('username', 'max')], 'email' => [length_limit('email', 'min'), length_limit('email', 'max')],
        'bio' => [length_limit('bio', 'min'), length_limit('bio', 'max')], 'q' => [length_limit('search', 'min'), length_limit('search', 'max')],
        'title' => [length_limit('title', 'min'), length_limit('title', 'max')], 'content' => [0, DB_TEXT_MAX_LENGTH],
        'body' => [min(length_limit('topic_body', 'min'), length_limit('reply_body', 'min')), max(length_limit('topic_body', 'max'), length_limit('reply_body', 'max'))],
        default => [null, null],
    };
}
/** 当前用户可以发帖的版块，id => 名称，供模板渲染版块下拉 */
/** 分页数据；无分页可显示时返回 null，模板据此决定是否渲染外层容器 */
function pagination_data(bool $simple, int $total, int $page, int $size, string $url, bool $has_next = false, bool $limited = true): ?array
{
    if ($simple) {
        $more = (!$limited || $page < max_pagination_pages()) && $has_next;
        if ($page <= 1 && !$more) return null;
        return ['kind' => 'simple', 'has_prev' => $page > 1, 'has_next' => $more, 'page' => $page, 'url' => $url, 'limited' => $limited];
    }
    $pages = max(1, (int)ceil($total / $size));
    if ($limited) $pages = min($pages, max_pagination_pages());
    if ($pages <= 1) return null;
    return ['kind' => 'full', 'total' => $total, 'page' => $page, 'size' => $size, 'url' => $url, 'limited' => $limited];
}
function post_forum_options(): array
{
    $options = [];
    foreach (forums_cache() as $f) if (forum_group_allowed($f, 'allow_post_groups')) $options[(int)$f['id']] = (string)$f['name'];
    return $options;
}
/** 主题列表需要的列；列表不展示正文，所以不带 body */
function topic_list_columns(): array
{
    return ['id', 'title', 'highlight_style', 'created_at', 'reply_count', 'last_reply_at', 'last_reply_user_id', 'forum_id', 'user_id'];
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
    $topics = topic_rows_by_ids(array_column($reply_rows, 'topic_id'));
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
function twig(bool $cache = true): Twig\Environment
{
    static $envs = [];
    if (isset($envs[$cache])) return $envs[$cache];
    // 缓存目录位于 app/data/ 下，该目录不可用时只能退回无缓存渲染
    $env = new Twig\Environment(new Twig\Loader\FilesystemLoader(TEMPLATE_DIR), [
        'cache' => $cache ? DATA_DIR . '/twig-cache' : false,
        'auto_reload' => true,
    ]);
    // 模板里只保留「取数据」的函数，页面标记一律由 templates/macros 下的宏负责
    foreach (['route_url', 'admin_url', 'asset_url', 'app_url', 'human_time', 'flash_json'] as $fn) $env->addFunction(new Twig\TwigFunction($fn, $fn, ['is_safe' => ['html']]));
    foreach (['setting', 'csrf_token', 'uid', 'me', 'group_by_id', 'can_manage', 'can_speak', 'can_access_admin', 'is_super_user', 'can_manage_topic', 'can_manage_reply', 'notification_excerpt', 'excerpt_length', 'notification_link', 'length_limits', 'max_pagination_pages', 'append_url_query', 'post_forum_options', 'admin_tabs'] as $fn) $env->addFunction(new Twig\TwigFunction($fn, $fn));
    // 正文是富文本渲染（Markdown 子集 + 提及/楼层链接），属于文本转换而非页面结构，保留为过滤器
    $env->addFilter(new Twig\TwigFilter('markdown', markdown_html(...), ['is_safe' => ['html']]));
    $env->addFilter(new Twig\TwigFilter('notification_content', notification_content_html(...), ['is_safe' => ['html']]));
    $env->addGlobal('app_version', APP_VERSION);
    return $envs[$cache] = $env;
}
function template(string $name, array $data = []): string
{
    return twig()->render($name, $data);
}
/** 不落缓存的渲染，供 app/data/ 不可用时的初始化失败页使用 */
function template_uncached(string $name, array $data = []): string
{
    return twig(false)->render($name, $data);
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
    // 原来是单条相关子查询 UPDATE（天然原子）。拆成读+写之后要放进事务里，保持同样的原子性。
    Database::connection()->transaction(static function () use ($tid): void {
        $topic = Topic::find($tid);
        if (!$topic) return;
        $last = Reply::where('topic_id', $tid)->orderByDesc('created_at')->orderByDesc('id')->first(['created_at', 'user_id']);
        $topic->update([
            'reply_count' => Reply::where('topic_id', $tid)->count(),
            'last_reply_at' => $last ? (int)$last->created_at : (int)$topic->created_at,
            'last_reply_user_id' => $last ? (int)$last->user_id : 0,
        ]);
    });
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
    $old_user = $user_id ? User::find($user_id) : null;
    if ($user_id && !$old_user) err('用户不存在');
    if ($old_user) {
        $username = (string)$old_user->username;
        $email = (string)$old_user->email;
    }
    if ($username === '') err('用户名不能为空');
    require_length($username, length_limit('username', 'min'), '用户名');
    require_length($email, length_limit('email', 'min'), '邮箱地址');
    require_length($bio, length_limit('bio', 'min'), '个人简介');
    $gid = $old_user ? (int)$old_user->group_id : (int)setting('default_group_id', '2');
    if (!group_by_id($gid)) err('用户组不存在');
    $pwd = (string)($_POST['password'] ?? '');
    $pwd2 = (string)($_POST['password2'] ?? '');
    if ($pwd !== '') require_password_length($pwd);
    if ($pwd !== '' && $pwd !== $pwd2) err('两次密码不一致');
    if (!$old_user || (string)$old_user->username !== $username) require_valid_username($username);
    if ($is_registration && preg_match_all('/./us', $username) < length_limit('username', 'min')) err('用户名长度不能少于' . length_limit('username', 'min') . '个字符');
    if ($is_registration && preg_match_all('/./us', $username) > length_limit('username', 'max')) err('用户名不能超过' . length_limit('username', 'max') . '个字符');
    if (User::where('username', $username)->when($user_id, static fn($query) => $query->where('id', '<>', $user_id))->exists()) err('用户名已存在');
    if ($user_id) {
        $values = ['username' => $username, 'email' => $email, 'bio' => $bio];
        if ($pwd !== '') $values['password'] = password_hash($pwd, PASSWORD_DEFAULT);
        User::whereKey($user_id)->update($values);
        if ($pwd !== '' && $user_id === uid()) csrf_cookie_clear();
    } else {
        if ($pwd === '') err('密码不能为空');
        $saved_user = User::create(['username' => $username, 'password' => password_hash($pwd, PASSWORD_DEFAULT), 'email' => $email, 'bio' => $bio, 'group_id' => $gid, 'created_at' => now()]);
        $GLOBALS['__last_saved_user_id'] = (int)$saved_user->id;
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
        $t = Topic::find($topic_id)?->toArray() ?: err('主题不存在');
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
            Topic::whereKey($t['id'])->update(['highlight_style' => topic_title_style($color, $bold)]);
            go(route_url('topic', ['id' => (int)$t['id']]));
        }
        if ($action === 'mute_author') {
            if ((int)$t['user_id'] === 1) err('不能操作超级管理员');
            User::whereKey((int)$t['user_id'])->update(['is_muted' => 1]);
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
        Database::connection()->transaction(static function () use ($topic_id, $fid, $title, $body, $reply_order): void {
            Topic::whereKey($topic_id)->update(['forum_id' => $fid, 'title' => $title, 'body' => $body, 'reply_order' => $reply_order]);
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
    $tid = Database::connection()->transaction(static function () use ($fid, $author_id, $title, $body, $ts): int {
        $saved = Topic::create(['forum_id' => $fid, 'user_id' => $author_id, 'title' => $title, 'body' => $body, 'created_at' => $ts, 'last_reply_at' => $ts]);
        $tid = (int)$saved->id;
        User::whereKey($author_id)->update(['last_post_at' => $ts]);
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
        $r = Reply::find($reply_id)?->toArray() ?: err('回复不存在');
        if (!can_manage_reply($r)) err('无权限');
        if (trim((string)$r['body']) === '') err('回复已删除，无法编辑');
        $tid = (int)$r['topic_id'];
    } else {
        $tid = max(1, (int)$_POST['topic_id']);
    }
    $topic = Topic::find($tid)?->toArray() ?: err('主题不存在');
    $forum = forum_by_id((int)$topic['forum_id']) ?: err('版块不存在');
    if (!forum_group_allowed($forum, 'allow_reply_groups')) err('无权限');
    $body = post('body', length_limit('reply_body', 'max'));
    if ($body === '') err('回复不能为空');
    require_length($body, length_limit('reply_body', 'min'), '回帖内容');
    if ($reply_id) {
        Database::connection()->transaction(static function () use ($body, $tid, $reply_id): void {
            Reply::whereKey($reply_id)->where('topic_id', $tid)->update(['body' => $body, 'updated_at' => now()]);
        });
        return ['topic_id' => (int)$r['topic_id'], 'reply_id' => (int)$r['id']];
    }
    require_length($body, length_limit('reply_body', 'min'), '回帖内容');
    $author = content_create_author('reply.create_author', ['body' => $body], ['topic_id' => $tid], ['body' => length_limit('reply_body', 'max')]);
    $author_id = (int)$author['user_id'];
    $body = $author['body'];
    if ($body === '') err('回复不能为空');
    $ts = now();
    $rid = Database::connection()->transaction(static function () use ($tid, $author_id, $body, $ts): int {
        $saved = Reply::create(['topic_id' => $tid, 'user_id' => $author_id, 'body' => $body, 'created_at' => $ts, 'updated_at' => $ts]);
        $rid = (int)$saved->id;
        User::whereKey($author_id)->update(['last_post_at' => $ts]);
        Topic::whereKey($tid)->increment('reply_count', 1, ['last_reply_at' => $ts, 'last_reply_user_id' => $author_id]);
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
    TopicDeletion::create(['topic_id' => $topic_id, 'reply_id' => $reply_id, 'created_at' => $created_at]);
    floor_index_clear();
}
function topic_floor_index(int $topic_id): array
{
    if (isset($GLOBALS['__floor_cache'][$topic_id])) return $GLOBALS['__floor_cache'][$topic_id];
    if (!app_topics_del_ready()) return $GLOBALS['__floor_cache'][$topic_id] = [];
    return $GLOBALS['__floor_cache'][$topic_id] = TopicDeletion::where('topic_id', $topic_id)->orderBy('created_at')->orderBy('id')
        ->get(['reply_id', 'created_at'])
        ->map(static fn(TopicDeletion $row): array => ['id' => (int)$row->reply_id, 'created_at' => (int)$row->created_at])
        ->all();
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
/** 某个 (created_at,id) 位置之前还有多少条存活回复；楼层号要把删掉的空洞算进去 */
function replies_before_count(int $topic_id, int $created_at, int $reply_id): int
{
    return Reply::where('topic_id', $topic_id)
        ->where(static function ($query) use ($created_at, $reply_id): void {
            $query->where('created_at', '<', $created_at)
                ->orWhere(static fn($sub) => $sub->where('created_at', $created_at)->where('id', '<', $reply_id));
        })->count();
}
function reply_position_floor(int $topic_id, int $created_at, int $reply_id): int
{
    $floor = 1 + replies_before_count($topic_id, $created_at, $reply_id);
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
        $live_before = replies_before_count($topic_id, (int)$gap['created_at'], (int)$gap['id']);
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
    $models = [
        'users' => User::class,
        'groups' => Group::class,
        'forums' => Forum::class,
        'topics' => Topic::class,
        'replies' => Reply::class,
    ];
    if (!isset($models[$table])) err('参数错误');
    if (in_array($table, ['users', 'groups', 'forums'], true) && !can_manage()) err('无权限');
    if ($table === 'users' && $id === uid()) err('不能删除自己');
    if ($table === 'groups' && $id <= 2) err('内置用户组不能删除');
    if ($table === 'groups' && $id === (int)setting('default_group_id', '2')) err('默认用户组不能删除');
    if ($table === 'forums' && count(forums_cache()) <= 1) err('至少保留一个版块');
    $model = $models[$table];
    if (in_array($table, ['users', 'topics', 'replies'], true)) {
        $record = $model::find($id)?->toArray() ?: err('记录不存在');
        $affected_topics = $table === 'users' ? Reply::where('user_id', $id)->distinct()->pluck('topic_id')->all() : [];
        Database::connection()->transaction(static function () use ($table, $model, $id, $record, $affected_topics, $with_replies): void {
            if ($table === 'replies') {
                if (trim((string)$record['body']) === '') err('回复已删除');
                floor_index_record((int)$record['topic_id'], $id, (int)$record['created_at']);
                $model::whereKey($id)->delete();
                refresh_topic_stats((int)$record['topic_id']);
                content_delete_notify($record, true, (int)$record['topic_id']);
                return;
            }
            if ($table === 'topics' && $with_replies) {
                foreach (Reply::where('topic_id', $id)->get(['id', 'topic_id', 'created_at']) as $reply) {
                    floor_index_record((int)$reply->topic_id, (int)$reply->id, (int)$reply->created_at);
                    Reply::whereKey($reply->id)->delete();
                }
            }
            $model::whereKey($id)->delete();
            if ($table === 'topics') content_delete_notify($record, false);
            if ($table === 'users') {
                foreach ($affected_topics as $topic_id) refresh_topic_stats((int)$topic_id);
            }
        });
        return;
    }
    Database::connection()->transaction(static fn() => $model::whereKey($id)->delete());
    if ($table === 'forums') forums_cache(true);
    else groups_cache(true);
}
function login_page(): void
{
    if (uid()) go(consume_auth_return_url());
    if (is_post_request()) {
        $u = User::where('username', post('username', DB_STRING_MAX_LENGTH))->first(['id', 'password']);
        if ($u && password_verify((string)$_POST['password'], (string)$u->password)) {
            complete_login((int)$u->id);
            return;
        }
        err('用户名或密码错误');
    }
    render_page('login.html.twig', [
        'auth_tabs' => auth_tab_items(),
        'notice_title' => '登录注意事项',
        'notice_items' => ['请使用用户名登录。', '密码区分大小写。', '公共设备登录后请及时退出。'],
    ], '登录');
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
    render_page('register.html.twig', [
        'auth_tabs' => auth_tab_items(),
        'notice_title' => '注册注意事项',
        'notice_items' => ['邮箱信息不会公开。', '请不要使用保留用户名或冒充他人。'],
    ], '注册');
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
            ? User::where('username', $username)->first(['id', 'username', 'bio', 'group_id', 'is_muted'])?->toArray()
            : User::find(id())?->toArray();
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
    // 主题列表的基础条件：版块过滤 + 标题/正文搜索；个人页再叠加 user_id
    $topic_list_query = static function () use ($fid, $query, $search_field) {
        $builder = Topic::query();
        if ($fid) $builder->where('forum_id', $fid);
        if ($query !== '' && $search_field !== 'reply') {
            [$condition, $params] = content_search_condition($query, $search_field);
            $builder->whereRaw('(' . $condition . ')', $params);
        }
        return $builder;
    };
    $reply_columns = ['id', 'topic_id', 'user_id', 'body', 'created_at'];
    if ($profile_uid && $profile_tab === 'notifications') {
        $total = notifications_total($profile_uid);
        $unread_total = notifications_unread_total($profile_uid);
        $rows = notifications_list($profile_uid, $size, $offset);
    } elseif ($profile_uid && $profile_tab === 'replies') {
        $own_replies = static fn() => Reply::query()->where('user_id', $profile_uid);
        $total = $own_replies()->count();
        $reply_rows = $own_replies()->orderByDesc('created_at')->orderByDesc('id')
            ->limit($size)->offset($offset)->get($reply_columns)->map->toArray()->all();
        $rows = topic_list_rows_for_replies($reply_rows);
    } elseif ($query !== '' && $search_field === 'reply') {
        [$condition, $params] = content_search_condition($query, 'reply');
        $search_replies = static function () use ($condition, $params, $profile_uid) {
            $builder = Reply::query()->whereRaw('(' . $condition . ')', $params);
            if ($profile_uid) $builder->where('user_id', $profile_uid);
            return $builder;
        };
        $reply_rows = $search_replies()->orderByDesc('created_at')->orderByDesc('id')
            ->limit($size + 1)->offset($offset)->get($reply_columns)->map->toArray()->all();
        $total = 0;
        $simple_pagination = true;
        $has_next_page = count($reply_rows) > $size;
        $rows = topic_list_rows_for_replies(array_slice($reply_rows, 0, $size));
    } else {
        $list_query = static function () use ($topic_list_query, $profile_uid) {
            $builder = $topic_list_query();
            if ($profile_uid) $builder->where('user_id', $profile_uid);
            return $builder;
        };
        $total = $query !== '' ? 0 : (($fid || $profile_uid) ? $list_query()->count() : Topic::query()->count());
        $query_size = $query !== '' ? $size + 1 : $size;
        $list = $list_query();
        if ($sort === 'post') $list->orderByDesc('created_at')->orderByDesc('id');
        else $list->orderByDesc('last_reply_at')->orderByDesc('id');
        $rows = $list->limit($query_size)->offset($offset)->get(topic_list_columns())->map->toArray()->all();
        if ($query !== '') {
            $simple_pagination = true;
            $has_next_page = count($rows) > $size;
            $rows = array_slice($rows, 0, $size);
        }
        $pinned_ids = (!$profile_uid && !$fid && $query === '' && $page === 1) ? pinned_topic_ids() : [];
        if ($pinned_ids) {
            $pinned_rows = topic_rows_by_ids($pinned_ids);
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
    $pagination = pagination_data((bool)$data['simple_pagination'], (int)$data['total'], $p, $size, $url($page_query), (bool)$data['has_next_page']);
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
        'sidebar_user' => $profile_uid ? $filter_user : null,
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
    $reply_query = Reply::where('topic_id', (int)$topic['id']);
    $reply_query = $reply_desc
        ? $reply_query->orderByDesc('created_at')->orderByDesc('id')
        : $reply_query->orderBy('created_at')->orderBy('id');
    $replies = $reply_query->limit($size)->offset($offset)->get()->map->toArray()->all();
    $posts = attach_users(array_merge([$topic], $replies));
    $topic = array_shift($posts);
    $replies = $posts;
    $data = ['topic' => $topic, 'replies' => $replies];
    return $data;
}
function topic_page_view(array $view): array
{
    extract($view, EXTR_SKIP);
    $reply_rows = [];
    foreach ($replies as $i => $r) {
        if (trim((string)$r['body']) === '') continue;
        $reply_floor = (int)($r['reply_floor'] ?? ($reply_desc ? (int)$t['reply_count'] - $off - $i : $off + $i + 1));
        $reply_rows[] = $r + ['floor' => $reply_floor, 'highlight' => $floor > 0 ? $reply_floor === $floor : (int)$r['id'] === $replyid];
    }
    $can_reply_forum = forum_group_allowed($forum, 'allow_reply_groups');
    return [
        't' => $t,
        'forum' => $forum,
        'topic_url' => route_url('topic', ['id' => (int)$t['id']]),
        'page' => $p,
        'replies' => $replies,
        'reply_rows' => $reply_rows,
        'pagination' => pagination_data(false, (int)$t['reply_count'], $p, $size, route_url('topic', ['id' => (int)$t['id']]), false, false),
        'can_reply_forum' => $can_reply_forum,
        'can_reply' => can_speak() && $can_reply_forum,
        'current_uid' => uid(),
        'reply_status' => uid() ? (can_speak() ? ($can_reply_forum ? '说两句' : '无回帖权限') : '禁止发言') : '登录后回复',
    ];
}
function topic_page(): void
{
    if (!id() && id('replyid')) {
        $reply = Reply::find(id('replyid'))?->toArray() ?: err('你访问的帖子可能已经删除', 404);
        go(route_url('topic', ['id' => (int)$reply['topic_id'], 'replyid' => id('replyid')]));
    }
    $t = Topic::find(id())?->toArray() ?: err('你访问的帖子可能已经删除', 404);
    $forum = forum_by_id((int)$t['forum_id']);
    if ($forum && !forum_group_allowed($forum, 'allow_view_groups')) err('无权限');
    if (mark_viewed((int)$t['id'])) {
        Topic::whereKey($t['id'])->increment('view_count');
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
        $reply = Reply::find($replyid)?->toArray();
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
        $t = Topic::find($topic_id)?->toArray() ?: err('主题不存在');
        if (!can_manage_topic($t)) err('无权限');
    }
    if (is_post_request()) go(route_url('topic', ['id' => save_topic()]));
    $title = $editing ? '编辑主题' : '发表主题';
    $edit_ops = null;
    if ($editing && can_manage()) {
        $edit_ops = [
            'style' => topic_title_color((string)($t['highlight_style'] ?? '')),
            'is_bold' => topic_title_is_bold((string)($t['highlight_style'] ?? '')),
            'is_pinned' => in_array((int)$t['id'], pinned_topic_ids(), true),
        ];
    }
    render_page('topic_edit.html.twig', [
        'title' => $title,
        't' => $t,
        'editing' => $editing,
        'edit_ops' => $edit_ops,
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
        $r = Reply::find($reply_id)?->toArray() ?: err('回复不存在');
        if (!can_manage_reply($r)) err('无权限');
        if (is_post_request() && ($_POST['do'] ?? '') === 'mute_author') {
            if (!can_manage()) err('无权限');
            if ((int)$r['user_id'] === 1) err('不能操作超级管理员');
            User::whereKey((int)$r['user_id'])->update(['is_muted' => 1]);
            go(route_url('topic', ['id' => (int)$r['topic_id'], 'replyid' => (int)$r['id']]));
        }
        if (is_post_request() && ($_POST['do'] ?? '') === 'delete') {
            if (trim((string)$r['body']) === '') err('回复已删除');
            Database::connection()->transaction(static function () use ($r): void {
                floor_index_record((int)$r['topic_id'], (int)$r['id'], (int)$r['created_at']);
                Reply::whereKey($r['id'])->delete();
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
            $row = Reply::find($saved['reply_id'])?->toArray() ?: err('回复不存在');
            $row = attach_users([$row])[0];
            $topic = Topic::find($saved['topic_id'])?->toArray() ?: ['view_count' => 0, 'reply_count' => 0, 'reply_order' => 0];
            $floor = reply_position_floor((int)$topic['id'], (int)$row['created_at'], (int)$row['id']);
            if ((int)($topic['reply_order'] ?? 0) === 1) go(route_url('topic', ['id' => $saved['topic_id'], 'replyid' => $saved['reply_id']]));
            json_response([
                'ok' => 1,
                'html' => template('partials/reply_post.html.twig', ['row' => $row, 'body' => (string)$row['body'], 'time' => (int)$row['created_at'], 'floor' => $floor, 'ops' => ['quote' => uid() > 0, 'edit' => can_manage_reply($row), 'edit_url' => route_url('reply_edit', ['id' => (int)$row['id']])]]),
                'stats_html' => template('partials/topic_stats.html.twig', ['view_count' => (int)$topic['view_count'], 'reply_count' => (int)$topic['reply_count']]),
            ]);
        }
        go(route_url('topic', ['id' => $saved['topic_id'], 'replyid' => $saved['reply_id']]));
    }
    render_page('reply_edit.html.twig', ['r' => $r], '编辑回复');
}
/** 后台标签页的数据，渲染交给 ui.tabs */
function admin_tabs(): array
{
    $items = [];
    foreach (['settings' => '设置', 'forums' => '版块', 'groups' => '用户组'] as $key => $label) {
        $items[$key] = ['label' => $label, 'href' => admin_url(['tab' => $key])];
    }
    return $items;
}
/** 登录/注册页顶部标签的数据 */
function auth_tab_items(): array
{
    return [
        'login' => ['label' => '登录', 'href' => route_url('login')],
        'register' => ['label' => '注册', 'href' => route_url('register')],
    ];
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
/** 编辑器预览：与正文共用同一个渲染函数，预览结果就是发出去的样子 */
function preview_route(): void
{
    require_post();
    need_login();
    json_response(['ok' => 1, 'html' => markdown_html((string)($_POST['body'] ?? ''), id('topic_id'))]);
}
function core_routes(): array
{
    return [
        'home' => 'home_page',
        'search' => [Search::class, 'page'],
        'mobile_menu' => 'mobile_menu_route',
        'preview' => 'preview_route',
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
// 先接上 Eloquent 再初始化，Bootstrap 里的建表和造数都走它
Database::boot();
if (!db_schema_ready()) Bootstrap::run();
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
