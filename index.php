<?php
declare(strict_types=1);
use app\optional\Admin;
use app\optional\Bootstrap;
use app\optional\Db\Database;
use app\optional\Db\SqlitePdo;
use app\optional\DebugLog;
use app\optional\Markdown;
use app\optional\Mcp;
use app\optional\Model\Forum;
use app\optional\Model\Group;
use app\optional\Model\Notification;
use app\optional\Model\Reply;
use app\optional\Model\Setting;
use app\optional\Model\Topic;
use app\optional\Model\TopicDeletion;
use app\optional\Model\User;
use app\optional\Model\ViewStat;
use app\optional\Search;
use app\optional\SearchIndex;
use app\optional\TopicTags;
use app\optional\TopicTDK;
use app\optional\Translator;
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
// 多语言：默认语言不带前缀，其他语言走 /{lang}/ 子目录（/en/topic/5）；语言偏好记忆在浏览器里
define('DEFAULT_LANG', 'zh');
define('LANG_COOKIE_NAME', 'bbs_lang');
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
    // 聚合搜索：全文索引触发器（fts_*_ai/au/ad）要在任何写连接上都能调到这个分词 UDF
    // PHP 8.5 起驱动子类提供 createFunction()，旧方法名已标记废弃
    $create = $db instanceof Pdo\Sqlite ? 'createFunction' : 'sqliteCreateFunction';
    $db->$create('fts_index_text', static fn($text): string => SearchIndex::index_text((string)$text));
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
    return attach_topic_list_titles($rows);
}
/** 列表标题多语言：非默认语言下批量取译文标题覆盖（一次 IN 查询），无译文的仍显示中文 */
function attach_topic_list_titles(array $rows): array
{
    $lang = current_lang();
    if ($lang === DEFAULT_LANG || $rows === [] || !class_exists(Translator::class)) return $rows;
    $titles = Translator::topic_titles($lang, array_column($rows, 'id'));
    foreach ($rows as &$row) {
        $title = (string)($titles[(int)($row['id'] ?? 0)]['title'] ?? '');
        if ($title !== '') $row['title'] = $title;
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
        'site_logo' => '',
        'allow_register' => '1',
        'default_group_id' => '2',
        'pc_nav_forum_count' => '6',
        'topics_per_page' => '30',
        'replies_per_page' => '50',
        'max_pagination_pages' => '1000',
        'username_min_length' => '2', 'username_max_length' => '20',
        'email_min_length' => '7', 'email_max_length' => '150',
        'bio_min_length' => '0', 'bio_max_length' => '10000',
        'search_min_length' => '2', 'search_max_length' => '80',
        'title_min_length' => '1', 'title_max_length' => (string)DB_STRING_MAX_LENGTH,
        'topic_body_min_length' => '1', 'topic_body_max_length' => (string)DB_TEXT_MAX_LENGTH,
        'reply_body_min_length' => '1', 'reply_body_max_length' => (string)DB_TEXT_MAX_LENGTH,
        'excerpt_length' => '200',
        'post_interval_seconds' => '5',
        'baidu_verification' => 'codeva-o0vee5lpeB',
        'baidu_tongji_url' => '',
        // 多语言：逗号分隔的启用语言码（不含默认语言 zh），留空=整站关闭多语言
        'i18n_enabled_langs' => 'en',
        'i18n_batch_size' => '3',
        'i18n_interval_seconds' => '120',
        'i18n_timeout' => '60',
        // 详情页 TDK 队列生成：密钥走环境变量 DEEPSEEK_API_KEY，不在库与代码中存放
        'tdk_enabled' => '1',
        'tdk_ai_enabled' => '1',
        'tdk_api_base' => 'https://api.deepseek.com',
        'tdk_model' => 'deepseek-flash',
        'tdk_batch_size' => '3',
        'tdk_interval_seconds' => '300',
        'tdk_timeout' => '30',
        'tdk_title_max' => '45',
        'tdk_description_min' => '100',
        'tdk_description_max' => '160',
        'tdk_keyword_max' => '8',
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
    return $GLOBALS['__forums_cache'] ??= forum_rows_localized();
}
/** 版块行：非默认语言下用译文覆盖名称与介绍（无译文回落中文），导航/徽标/面包屑/下拉全部自动跟随 */
function forum_rows_localized(): array
{
    $rows = Forum::query()->orderBy('sort')->orderBy('id')->get()->toArray();
    $lang = current_lang();
    if ($lang === DEFAULT_LANG || !class_exists(Translator::class)) return $rows;
    foreach ($rows as &$row) {
        $content = Translator::content_for($lang, 'forum', (int)$row['id']);
        if ($content !== null) {
            if ($content['title'] !== '') $row['name'] = $content['title'];
            if ($content['body'] !== '') $row['description'] = $content['body'];
        }
    }
    unset($row);
    return $rows;
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
/** 每日浏览量 +1：只服务于后台报表，失败静默，绝不影响正常浏览 */
function record_view_stat(): void
{
    try {
        $row = ViewStat::firstOrCreate(['view_date' => date('Y-m-d')], ['views' => 0]);
        ViewStat::whereKey($row->getKey())->increment('views');
    } catch (Throwable) {
    }
}
function mobile_menu_content_html(?array $mine = null, ?array $forums = null): string
{
    $forums ??= array_values(array_filter(forums_cache(), fn($forum) => forum_group_allowed($forum, 'allow_view_groups')));
    $forum_links = [['text' => t('全部'), 'url' => route_url('home')]];
    foreach ($forums as $f) {
        $forum_links[] = ['text' => (string)$f['name'], 'url' => route_url('forum', ['id' => (int)$f['id']])];
    }
    $my_links = [];
    if ($mine) {
        $uid = (int)$mine['id'];
        $my_links[] = ['text' => t('我的主页'), 'url' => route_url('user', ['id' => $uid])];
        $my_links[] = ['text' => t('我的主题'), 'url' => route_url('user', ['id' => $uid, 'tab' => 'topics'])];
        $my_links[] = ['text' => t('我的回帖'), 'url' => route_url('user', ['id' => $uid, 'tab' => 'replies'])];
        $my_links[] = ['text' => t('我的通知'), 'url' => route_url('user', ['id' => $uid, 'tab' => 'notifications'])];
        $my_links[] = ['text' => t('个人设置'), 'url' => route_url('profile')];
        if (can_access_admin()) $my_links[] = ['text' => t('后台面板'), 'url' => route_url('admin')];
    } else {
        $my_links[] = ['text' => t('登录'), 'url' => route_url('login')];
        if (setting('allow_register', '1') === '1') $my_links[] = ['text' => t('注册'), 'url' => route_url('register')];
    }
    $sections = [
        ['title' => t('版块列表'), 'links' => $forum_links],
        ['title' => t('我的菜单'), 'links' => $my_links],
    ];
    // 语言切换走 /lang 端点：先写偏好 cookie 再跳目标语言页面，避免被语言协商跳回；
    // 抽屉是片段端点，当前页地址由前端 fetch 时以 back 参数带上，没有就退回目标语言首页
    if (enabled_langs()) {
        $lang_back = lang_back_target();
        // back 为空（前端没带或非法）时不传，由 /lang 端点回落到目标语言首页
        $lang_links = [['text' => '中文', 'url' => lang_switch_url(DEFAULT_LANG, $lang_back)]];
        foreach (enabled_langs() as $lang) $lang_links[] = ['text' => strtoupper($lang), 'url' => lang_switch_url($lang, $lang_back)];
        $sections[] = ['title' => t('语言'), 'links' => $lang_links];
    }
    return template('mobile_menu.html.twig', ['sections' => $sections]);
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
    // 站点关闭时 MCP 由 Mcp::route() 按令牌身份自行判定，返回 JSON-RPC 错误而不是 HTML 消息页
    if ($a === 'mcp') return;
    if (setting('site_closed') === '1' && !can_access_admin()) {
        if (!in_array($a, ['login', 'logout', 'form_error'], true)) err('网站已关闭');
    }
}
function check(): void
{
    if (uid()) me();
    $is_post = is_post_request();
    $action = (string)($_GET['a'] ?? '');
    // MCP 走 Bearer 令牌鉴权，不依赖 Cookie 会话，没有 CSRF 面；双提交校验只服务网页表单
    if ($is_post && $action !== 'mcp' && !hash_equals(csrf_token(), (string)($_POST['_csrf'] ?? ''))) {
        err('请求已过期');
    }
}
/*
 * Cloudflare Turnstile 人机验证。
 *
 * 三个环境变量一起决定是否启用，缺一个都视为未接入：页面不输出组件，后端也不校验。
 *   TURNSTILE_SITE_KEY   前端 site key（公开值，会渲染进页面）
 *   TURNSTILE_SECRET     后端 secret（只经 siteverify 使用，不落任何输出）
 *   TURNSTILE_HOSTNAMES  siteverify 返回的 hostname 白名单，逗号分隔
 * 只配一半是危险状态（以为开着其实没开），所以单独记一条调试日志。
 */
function turnstile_site_key(): string
{
    return trim((string)getenv('TURNSTILE_SITE_KEY'));
}
function turnstile_secret(): string
{
    return trim((string)getenv('TURNSTILE_SECRET'));
}
function turnstile_enabled(): bool
{
    static $warned = false;
    $site_key = turnstile_site_key();
    $secret = turnstile_secret();
    if ($site_key !== '' && $secret !== '') return true;
    // 只配一半（页面会渲染组件但后端不校验，反之亦然）是危险状态，记一条日志；
    // 一次请求里只会命中一次，避免每个宏/函数各写一遍
    if (!$warned && ($site_key !== '' || $secret !== '')) {
        $warned = true;
        debug_log_write('Turnstile 配置不完整：TURNSTILE_SITE_KEY / TURNSTILE_SECRET / TURNSTILE_HOSTNAMES 必须同时设置，当前按未接入处理');
    }
    return false;
}
function turnstile_hostnames(): array
{
    $hostnames = [];
    foreach (explode(',', (string)getenv('TURNSTILE_HOSTNAMES')) as $hostname) {
        $hostname = strtolower(trim($hostname));
        if ($hostname !== '') $hostnames[] = $hostname;
    }
    return $hostnames;
}
/** 调 siteverify 校验 token。拿不到确定结果时返回 null，由调用方按不通过处理 */
function turnstile_siteverify(string $secret, string $token): ?array
{
    $fields = ['secret' => $secret, 'response' => $token];
    $ip = ip_addr();
    if ($ip !== '' && $ip !== '0.0.0.0') $fields['remoteip'] = $ip;
    $url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    $body = http_build_query($fields);
    $raw = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false || (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE) !== 200) $raw = null;
        curl_close($ch);
    } else {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body,
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $context);
        if (!is_string($raw) || $raw === '') $raw = null;
    }
    if (!is_string($raw)) return null;
    $result = json_decode($raw, true);
    return is_array($result) ? $result : null;
}
/** 校验本次 POST 的 cf-turnstile-response；不通过就地报错，受保护的处理逻辑不会执行 */
function turnstile_verify(string $action): void
{
    if (!turnstile_enabled()) return;
    $secret = turnstile_secret();
    $hostnames = turnstile_hostnames();
    $token = trim((string)($_POST['cf-turnstile-response'] ?? ''));
    // 配置缺项、token 缺失或超长一律拒绝：宁可拦错也不放过
    if ($secret === '' || $hostnames === []) turnstile_reject($action, 'config');
    if ($token === '' || strlen($token) > 2048) turnstile_reject($action, 'token:' . strlen($token));
    $result = turnstile_siteverify($secret, $token);
    if ($result === null) turnstile_reject($action, 'siteverify-unreachable');
    // 逐项判定，失败原因写调试日志：error-codes 里的 invalid-input-secret 等能直接定位问题
    if (($result['success'] ?? false) !== true) {
        turnstile_reject($action, 'siteverify:' . implode(',', (array)($result['error-codes'] ?? [])));
    }
    if ((string)($result['action'] ?? '') !== $action) {
        turnstile_reject($action, 'action:' . (string)($result['action'] ?? ''));
    }
    if (!in_array(strtolower((string)($result['hostname'] ?? '')), $hostnames, true)) {
        turnstile_reject($action, 'hostname:' . (string)($result['hostname'] ?? ''));
    }
}
function turnstile_reject(string $action, string $reason): never
{
    debug_log_write('Turnstile 校验未通过 action=' . $action . ' reason=' . $reason);
    // 403 是给调用方的判定结果；消息页/JSON 沿用 err() 既有的两条出口
    err('人机验证未通过，请重试', 403, ajax_request() ? 'ajax' : 'page');
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
    if ($diff < 60) $text = t('刚刚');
    elseif ($diff < 3600) $text = floor($diff / 60) . t('分钟前');
    elseif ($diff < 86400) $text = floor($diff / 3600) . t('小时前');
    elseif ($diff < 172800) $text = t('昨天');
    elseif ($diff < 604800) $text = floor($diff / 86400) . t('天前');
    else $text = date('Y-m-d', $ts);
    // 包一层 <time datetime>：搜索引擎与 AI 引擎靠机器可读时间戳判定内容新鲜度
    return '<time datetime="' . sitemap_w3c($ts) . '">' . $text . '</time>';
}
/** SEO：站点默认一句话介绍——后台 site_description 留空时兜底 */
function default_site_description(): string
{
    return t('旧衣回收、出口行情与政策法规的行业资讯与交流社区。');
}
/** 收费模式：联系方式脱敏——手机号、座机/400、邮箱、微信号、QQ 号一律替换为 ****** */
function mask_contacts(string $text): string
{
    // 不换行空格(U+00A0)常被用来混淆号码，PCRE 的 \s 认不出它：先统一成普通空格再匹配
    $text = str_replace("\xc2\xa0", ' ', $text);
    // 邮箱先处理，避免本地部分被后续规则误伤
    $text = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/u', '******', $text) ?? $text;
    // 带引导词的号码（含 +86 国际格式等变体）
    $text = preg_replace('/(电话|手机|手机号|联系方式|致电|热线|tel)\s*[:：]?\s*\+?\d[\d\s-]{6,17}\d/iu', '$1：******', $text) ?? $text;
    // 手机号：11 位、分隔符任意（连写/逐位加空格/连续空格/0 前缀/12 位变体/+86 前缀）。
    // 第一个分隔符也要放开："1 8 2 8…"这种逐位空格写法，1 和第二位数字之间不能强制相邻
    $text = preg_replace('/(?<!\d)(?:\+?86[\s\-]?)?0?1(?:[\s\-]*\d){10,12}(?!\d)/u', '******', $text) ?? $text;
    // 座机（区号-号码，本地段允许 6 位）与 400/800 热线
    $text = preg_replace('/(?<!\d)(?:0\d{2,3}|[48]00)[- ]?\d{6,8}(?!\d)/u', '******', $text) ?? $text;
    $text = preg_replace('/(?<!\d)[48]00[- ]\d{3}[- ]\d{4}(?!\d)/u', '******', $text) ?? $text;
    // 微信号：前缀引导 + 6-20 位字母开头的 ID
    $text = preg_replace('/(微信号?|weixin|wx|vx|v信)\s*[:：#]?\s*[a-zA-Z][a-zA-Z0-9_-]{5,19}/iu', '$1：******', $text) ?? $text;
    // QQ/蝙蝠：前导字母允许空格/全角/单个 Q，与号值之间允许短括号说明（"QQ（第三方验证）：785095888"），号值允许空格分段
    $text = preg_replace('/(扣扣|蝙蝠|[qｑ][\s\-]?[qｑ]?)(\s*(?:（[^）\n]{1,16}）)?\s*[:：#]\s*)\s*[+\d][\d\s\-]{3,13}\d/iu', '$1$2******', $text) ?? $text;
    // WhatsApp/Telegram 等境外号码：保留软件名，号码整段打码（含 + 国际区号）
    $text = preg_replace('/((?:whats\s?app|telegram|wa\.me|t\.me)[^\d+]{0,3})\+?\d[\d\s\-.]{4,}\d/iu', '$1：******', $text) ?? $text;
    return $text;
}
function forum_paid_mode(int $forum_id): bool
{
    $forum = forum_by_id($forum_id);
    return $forum !== null && (int)($forum['paid_mode'] ?? 0) === 1;
}
/** 收费模式版块的内容对非管理员脱敏：只动展示数据，原文与编辑回显不受影响 */
function mask_topic_contacts(array $t): array
{
    if (can_manage() || !forum_paid_mode((int)$t['forum_id'])) return $t;
    $t['title'] = mask_contacts((string)$t['title']);
    $t['body'] = mask_contacts((string)$t['body']);
    return $t;
}
/** 运行时结构补列：settings 打标，每个部署只执行一次（Bootstrap 只管全新安装的建表） */
function ensure_schema_bumps(): void
{
    static $done = false;
    if ($done || (int)setting('schema_bumps', '0') >= 4) return;
    $done = true;
    $db = db();
    $cols = array_column($db->query('PRAGMA table_info(app_forums)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('paid_mode', $cols, true)) $db->exec('ALTER TABLE app_forums ADD COLUMN paid_mode INTEGER NOT NULL DEFAULT 0');
    if (!in_array('parent_id', $cols, true)) $db->exec('ALTER TABLE app_forums ADD COLUMN parent_id INTEGER NOT NULL DEFAULT 0');
    // bump 3：旧部署把早期默认值 50 落了库，导致列表只能翻到 50 页；仍停留在 50 的按新默认值放开
    if (setting('max_pagination_pages', '') === '50') save_settings_values(['max_pagination_pages' => '1000']);
    // bump 4：主题内容编辑时间——last_reply_at 只跟回帖走，自动化每日改数据的帖子（价格行情）
    // 一直带着旧时间，TDK/翻译队列也发现不了内容变过；编辑标题/正文时写 content_updated_at
    $topic_cols = array_column($db->query('PRAGMA table_info(app_topics)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('content_updated_at', $topic_cols, true)) {
        $db->exec('ALTER TABLE app_topics ADD COLUMN content_updated_at INTEGER NOT NULL DEFAULT 0');
        // 存量修补交由后台指纹回填（校正脚本比对 fingerprint 后写此列），避免这里全表误标
    }
    save_settings_values(['schema_bumps' => '4']);
}
/** 二级分类：子版块 ID 清单（版块层级固定两层） */
function forum_child_ids(int $fid, bool $viewable_only = false): array
{
    $ids = [];
    foreach (forums_cache() as $f) {
        if ((int)($f['parent_id'] ?? 0) !== $fid) continue;
        if ($viewable_only && !forum_group_allowed($f, 'allow_view_groups')) continue;
        $ids[] = (int)$f['id'];
    }
    return $ids;
}
/** SEO：首页 <title> 的业务词后缀 */
function home_title_suffix(): string
{
    return t('旧衣回收与出口行业资讯');
}
/** SEO：首页 <title> 的补充定位语——把标题拼到 Bing 建议的 50-60 字符区间，纯"站名 - 后缀"仅 21 字会被判过短 */
function home_title_tagline(): string
{
    return t('行情数据、政策法规与供应信息每日更新');
}
/** SEO：JSON-LD 输出编码——HEX_TAG 防止标题正文里的 `</script>` 提前闭合标签 */
function seo_jsonld_script(array $objects): string
{
    if (!$objects) return '';
    $json = json_encode(array_values($objects), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return $json === false ? '' : '<script type="application/ld+json">' . $json . '</script>';
}
/** SEO：规范化主机名——site_base_url 已配置时，主机名不符的 GET 请求 301 到规范域（本地开发未配置则不生效） */
function canonical_host_redirect(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    $base = clean_site_base_url(setting('site_base_url', ''));
    $configured_host = strtolower((string)parse_url($base, PHP_URL_HOST));
    $request_host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($base === '' || $configured_host === '' || $request_host === '') return;
    $request_host = strtolower((string)(parse_url('http://' . $request_host, PHP_URL_HOST) ?: $request_host));
    if ($request_host === $configured_host) return;
    // 开发库常带线上 site_base_url：本机、内网与 IP 直连一律不跳转，避免破坏本地工作流
    $local = static fn(string $host): bool => filter_var($host, FILTER_VALIDATE_IP) !== false
        || $host === 'localhost' || str_ends_with($host, '.localhost');
    if ($local($request_host) || $local($configured_host)) return;
    if (($_GET['a'] ?? '') === 'mcp') return;
    header('Location: ' . rtrim($base, '/') . (string)($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
    exit;
}
function max_pagination_pages(): int
{
    return min(1000, max(1, (int)setting('max_pagination_pages', '1000')));
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
/** 路由名即路径首段：home 是站点根，其余形如 /topic/12、/admin?tab=groups；非默认语言自动带 /{lang}/ 前缀 */
function route_url(string $a = 'home', array $params = [], ?string $lang = null): string
{
    unset($params['a']);
    $lang = $lang ?? current_lang();
    $prefix = $lang === DEFAULT_LANG ? '' : $lang . '/';
    if ($a === 'home') {
        $url = app_url($prefix === '' ? '' : rtrim($prefix, '/'));
        return $params ? append_url_query($url, $params) : $url;
    }
    $segments = [];
    if ($prefix !== '') $segments[] = $lang;
    $segments[] = rawurlencode($a);
    if (isset($params['id']) && ctype_digit((string)$params['id'])) {
        $segments[] = rawurlencode((string)$params['id']);
        unset($params['id']);
    }
    // 话题页：/tag/{关键词}，关键词进路径（中文原样百分号编码，搜索引擎可读）
    if ($a === 'tag' && isset($params['kw']) && (string)$params['kw'] !== '') {
        $segments[] = rawurlencode((string)$params['kw']);
        unset($params['kw']);
    }
    return append_url_query(app_url(implode('/', $segments)), $params);
}
function asset_url(string $file): string
{
    return app_url($file);
}
/* ==================== 多语言：中文用户中文站，非中文用户英文站 ==================== */

/** 已启用的非默认语言码列表（i18n_enabled_langs，逗号分隔两位语言码），空数组=整站关闭多语言 */
function enabled_langs(): array
{
    static $langs = null;
    if ($langs !== null) return $langs;
    $langs = [];
    foreach (explode(',', (string)setting('i18n_enabled_langs', '')) as $code) {
        $code = strtolower(trim($code));
        if (preg_match('/^[a-z]{2}$/', $code) && $code !== DEFAULT_LANG && !in_array($code, $langs, true)) $langs[] = $code;
    }
    return $langs;
}

function current_lang(): string
{
    return (string)($GLOBALS['__lang'] ?? DEFAULT_LANG);
}

function default_lang(): string
{
    return DEFAULT_LANG;
}

/**
 * 界面文案翻译：默认语言原样返回；其他语言查 app/i18n/{lang}.php（键=中文源文案，值=译文），
 * 缺词条回落中文，模板可以渐进补词条而不会出现空白。
 */
function t(string $text): string
{
    $lang = current_lang();
    if ($lang === DEFAULT_LANG) return $text;
    static $dicts = [];
    if (!isset($dicts[$lang])) {
        $file = APP_DIR . '/i18n/' . basename($lang) . '.php';
        $dicts[$lang] = is_file($file) ? (array)(include $file) : [];
    }
    return (string)($dicts[$lang][$text] ?? $text);
}

/** 解析 Accept-Language 为按 q 值降序排列的语言标签列表（非法片段与 q=0 的丢弃） */
function accept_lang_prefs(): array
{
    $prefs = [];
    foreach (explode(',', (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $part) {
        $pieces = array_map('trim', explode(';', $part));
        $tag = strtolower($pieces[0] ?? '');
        if (!preg_match('/^[a-z]{2,8}(-[a-zA-Z0-9]+)*$/', $tag)) continue;
        $q = 1.0;
        foreach (array_slice($pieces, 1) as $param) {
            if (str_starts_with($param, 'q=')) $q = (float)substr($param, 2);
        }
        if ($q <= 0) continue;
        $prefs[$tag] = max($prefs[$tag] ?? 0.0, $q);
    }
    arsort($prefs);
    return array_keys($prefs);
}

/**
 * 语言协商：cookie 里的显式选择优先；否则按 Accept-Language 与已启用语言前缀匹配——
 * 命中 zh 前缀（含 zh-CN/zh-TW）用中文站，命中 en/fr 等前缀用对应语言站，全部不匹配的
 * 非中文访客落第一个启用语言（英文站）。返回 null 表示多语言未启用。
 */
function negotiate_lang(): ?string
{
    $langs = enabled_langs();
    if (!$langs) return null;
    $cookie = strtolower(trim((string)($_COOKIE[LANG_COOKIE_NAME] ?? '')));
    if ($cookie === DEFAULT_LANG || in_array($cookie, $langs, true)) return $cookie;
    $prefs = accept_lang_prefs();
    // 完全没有 Accept-Language 的客户端（部分工具/老爬虫）拿不到语言意图，按默认语言处理；
    // 有语言意图但不含中文的（de/fr…）才落第一个启用语言（英文站）
    if (!$prefs) return DEFAULT_LANG;
    foreach ($prefs as $tag) {
        if (str_starts_with($tag, DEFAULT_LANG)) return DEFAULT_LANG;
        foreach ($langs as $lang) {
            if (str_starts_with($tag, $lang)) return $lang;
        }
    }
    return $langs[0];
}

/** 爬虫/链接预览机器人/命令行客户端不做语言跳转：搜索引擎必须拿到稳定的语言版本，而不是 302 出的个性化结果 */
function request_is_bot(): bool
{
    static $bot = null;
    if ($bot !== null) return $bot;
    $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') return $bot = true;
    return $bot = (bool)preg_match('/bot|crawl|spider|slurp|curl|wget|python|java\/|okhttp|libwww|httpclient|headless|lighthouse|pagespeed|monitor|preview|facebookexternalhit|skypeuripreview|discordapp|slack|telegrambot|whatsapp/i', $ua);
}

/** 当前请求页面在指定语言下的地址（语言切换器与 hreflang 共用） */
function lang_url(string $lang): string
{
    $params = $_GET;
    // id 要留在 params 里：route_url 会把它挪进路径段（/topic/1596），删掉会丢 id 变成 /topic
    unset($params['a']);
    return route_url((string)($params['a'] ?? 'home') === '' ? 'home' : (string)$_GET['a'], $params, $lang);
}

/** 切换到指定语言的链接（先经 /lang 写偏好 cookie 再跳目标页，否则会被语言协商跳回来）；back 可显式指定跳回地址（片段端点里当前页由前端传入） */
function lang_switch_url(string $lang, ?string $back = null): string
{
    return append_url_query(route_url('lang'), ['to' => $lang, 'back' => $back ?? lang_url($lang)]);
}

/** 语言切换的跳回地址：只接受站内路径（不开外链、不循环到 /lang 自身） */
function lang_back_target(): string
{
    $back = (string)($_GET['back'] ?? '');
    return $back !== '' && str_starts_with($back, '/') && !str_starts_with($back, '//') && !str_starts_with($back, '/lang') ? $back : '';
}

/** 语言切换端点（/lang?to=en&back=/topic/5）：写偏好 cookie 后跳回目标页面，back 只接受站内路径 */
function lang_switch_route(): void
{
    $to = strtolower(trim((string)($_GET['to'] ?? '')));
    if ($to !== DEFAULT_LANG && !in_array($to, enabled_langs(), true)) err('不支持的语言');
    app_cookie(LANG_COOKIE_NAME, $to, time() + COOKIE_TTL, true, false);
    go(lang_back_target() ?: route_url('home', [], $to));
}

/**
 * 路由前缀校验：parse_path_route() 摘出的两字母语言段若未启用（含多语言整体关闭），
 * 还原成未知路由走 404，与该路径本来不存在时的行为一致。
 */
function i18n_init(): void
{
    $lang = (string)($GLOBALS['__lang'] ?? '');
    if ($lang === '' || in_array($lang, enabled_langs(), true)) return;
    $_GET['a'] = $lang;
    unset($_GET['id']);
    $GLOBALS['__lang'] = DEFAULT_LANG;
}

/** 语言跳转：只服务普通浏览页（白名单路由），机器可读出口与功能性路由一律不跳 */
function i18n_redirect_guard(string $route): void
{
    if (is_post_request() || ajax_request() || request_is_bot()) return;
    if (preg_match('/\.(?:xml|txt)$/D', $route) || str_starts_with($route, 'sitemap-topics-')) return;
    static $pages = ['home', 'forum', 'topic', 'user', 'search', 'login', 'register', 'profile', 'form_error'];
    if (!in_array($route, $pages, true)) return;
    $target = negotiate_lang();
    if ($target === null || $target === current_lang()) return;
    $params = $_GET;
    unset($params['a']); // id 保留：route_url 会把它拼进路径段
    go(route_url($route, $params, $target));
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
    // 语言前缀先摘出来（/en/topic/5 的 "en" 不进路由表）。这里只做结构判断，
    // 语言是否真的启用由 i18n_init() 在设置可用后校验，未启用会还原回未知路由走 404。
    // 注意：路由名因此不允许出现恰好两个小写字母的取值。
    if (isset($segments[0]) && preg_match('/^[a-z]{2}$/', $segments[0])) {
        $GLOBALS['__lang'] = array_shift($segments);
        $segments = array_values($segments);
    }
    if (isset($segments[0]) && $segments[0] !== 'a' && !array_key_exists('a', $_GET)) $_GET['a'] = rawurldecode($segments[0]);
    if (isset($segments[1]) && ctype_digit($segments[1]) && !array_key_exists('id', $_GET)) $_GET['id'] = rawurldecode($segments[1]);
    // /tag/{关键词} 的第二段是中文关键词，不满足 ctype_digit，单独映射
    if (isset($segments[0], $segments[1]) && $segments[0] === 'tag' && !array_key_exists('kw', $_GET)) $_GET['kw'] = rawurldecode($segments[1]);
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
    return ['kind' => 'full', 'total' => $total, 'page' => $page, 'size' => $size, 'url' => $url, 'limited' => $limited, 'hidden' => pagination_hidden_fields($url)];
}
/** 页码直达 GET 表单的隐藏域：GET 提交会整体替换 query，要把 p 以外的现有参数原样带上，跳转才不丢 sort/tab 等状态 */
function pagination_hidden_fields(string $url): array
{
    $query = (string)(parse_url($url, PHP_URL_QUERY) ?? '');
    if ($query === '') return [];
    parse_str($query, $params);
    unset($params['p']);
    $fields = [];
    foreach ($params as $key => $value) $fields[(string)$key] = is_array($value) ? implode(',', array_map('strval', $value)) : (string)$value;
    return $fields;
}
function post_forum_options(): array
{
    $options = [];
    foreach (forums_cache() as $f) {
        if (!forum_group_allowed($f, 'allow_post_groups')) continue;
        $options[(int)$f['id']] = ((int)($f['parent_id'] ?? 0) > 0 ? '└ ' : '') . (string)$f['name'];
    }
    return $options;
}
/** 主题列表需要的列；列表不展示正文，所以不带 body */
function topic_list_columns(): array
{
    return ['id', 'title', 'highlight_style', 'created_at', 'reply_count', 'view_count', 'last_reply_at', 'last_reply_user_id', 'forum_id', 'user_id'];
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
    foreach (['setting', 'csrf_token', 'uid', 'me', 'group_by_id', 'can_manage', 'can_speak', 'can_access_admin', 'is_super_user', 'can_manage_topic', 'can_manage_reply', 'notification_excerpt', 'excerpt_length', 'notification_link', 'length_limits', 'max_pagination_pages', 'append_url_query', 'post_forum_options', 'admin_tabs', 'turnstile_enabled', 'turnstile_site_key', 't', 'current_lang', 'lang_url', 'lang_switch_url', 'enabled_langs', 'default_lang'] as $fn) $env->addFunction(new Twig\TwigFunction($fn, $fn));
    // 正文是富文本渲染（Markdown 子集 + 提及/楼层链接），属于文本转换而非页面结构，保留为过滤器
    $env->addFilter(new Twig\TwigFilter('markdown', markdown_html(...), ['is_safe' => ['html']]));
    $env->addFilter(new Twig\TwigFilter('notification_content', notification_content_html(...), ['is_safe' => ['html']]));
    // 统计脚本地址进 JS 字符串：整段 |e('js') 会把 URL 打成 \x3A 不可读，这里只转义真正危险的字符
    $env->addFilter(new Twig\TwigFilter('js_string', js_string_escape(...)));
    // 界面文案翻译：zh 原样返回，其他语言查字典，缺词回落中文
    $env->addFilter(new Twig\TwigFilter('t', t(...)));
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
/** 进 JS 字符串的值（统计脚本地址等）：只转义能跳出字符串/闭合 script 的字符，URL 保持原样可读 */
function js_string_escape(string $value): string
{
    return str_replace(['\\', '"', '<', '>'], ['\\\\', '\\"', '\\x3C', '\\x3E'], $value);
}
function flash_json(string $flash): string
{
    return json_encode($flash, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}
function page_nav_data(string $site_name): array
{
    // 导航只列一级版块；当前在子版块时高亮其父级
    $active_forum = ($_GET['a'] ?? '') === 'forum' ? id() : 0;
    if ($active_forum && (int)(forum_by_id($active_forum)['parent_id'] ?? 0) > 0) $active_forum = (int)forum_by_id($active_forum)['parent_id'];
    $mine = me();
    $forums = array_values(array_filter(forums_cache(), fn($f) => (int)($f['parent_id'] ?? 0) === 0 && forum_group_allowed($f, 'allow_view_groups')));
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
    // 论坛 Logo：后台填了图片地址就替换品牌位默认标志，这里归一成绝对地址供模板直接输出
    $site_logo = trim((string)setting('site_logo', ''));
    return [
        'site_name' => $site_name,
        'site_logo' => $site_logo !== '' ? absolute_url($site_logo) : '',
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
    // 版块页 title 拼版块描述：纯"版块名 - 站名"只有 14 字，低于 Bing 的过短阈值
    $title_tagline = '';
    if (($_GET['a'] ?? '') === 'forum') {
        $forum = forum_by_id(id());
        $title_tagline = $forum ? trim((string)$forum['description']) : '';
    }
    $page_title = $is_home || $title === '' || $title === $site_name
        ? trim($site_name_title . ($is_home && home_title_suffix() !== '' ? ' - ' . home_title_suffix() : '')
            . ($is_home && home_title_tagline() !== '' ? '｜' . home_title_tagline() : ''))
        : $title . ' - ' . $site_name_title . ($title_tagline !== '' ? '：' . $title_tagline : '');
    $description = trim((string)($seo['description'] ?? ($settings['site_description'] ?? '')));
    // description 任何页面都不为空：后台清空站点/版块描述时退回默认一句话，head 缺 description meta 会被 Bing SEO 扫描记高危
    if ($description === '') $description = default_site_description();
    $flash = trim((string)($_COOKIE['__flash'] ?? ''));
    if ($flash !== '' && !headers_sent()) app_cookie('__flash', '', time() - 3600, true, false);
    // 全站结构化数据：Organization 每页都有，WebSite（含搜索动作）走 @id 引用
    $base = rtrim(base_url(), '/');
    $site_logo = trim((string)($settings['site_logo'] ?? ''));
    $jsonld = [[
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        '@id' => $base . '/#organization',
        'name' => $site_name,
        'url' => $base . '/',
        'logo' => $site_logo !== '' ? absolute_url($site_logo) : absolute_url(asset_url('app/assets/index.svg')),
    ]];
    $jsonld[] = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        '@id' => $base . '/#website',
        'name' => $site_name_title,
        'url' => $base . '/',
        'inLanguage' => current_lang() === DEFAULT_LANG ? 'zh-CN' : current_lang(),
        'publisher' => ['@id' => $base . '/#organization'],
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => ['@type' => 'EntryPoint', 'urlTemplate' => absolute_url(route_url('search')) . '?q={q}'],
            'query-input' => 'required name=q',
        ],
    ];
    foreach ((array)($seo['jsonld'] ?? []) as $object) $jsonld[] = $object;
    return [
        'title' => $title,
        'site_name' => $site_name,
        'site_name_title' => $site_name_title,
        'site_keywords' => (string)($settings['site_keywords'] ?? ''),
        'site_description' => $description,
        'seo_canonical' => (string)($seo['canonical'] ?? ''),
        'seo_og_type' => (string)($seo['og_type'] ?? 'website'),
        // og:image 兜底：页面无图时用全站默认横幅，保证社交/AI 预览卡片不为空
        'seo_image' => trim((string)($seo['image'] ?? '')) !== '' ? (string)$seo['image'] : absolute_url(asset_url('app/assets/og-default.png')) . '?v=' . APP_VERSION,
        'seo_jsonld' => seo_jsonld_script($jsonld),
        'seo_noindex' => (bool)($seo['noindex'] ?? false),
        'seo_keywords' => trim((string)($seo['keywords'] ?? '')),
        'is_home' => $is_home,
        'page_title' => $page_title,
        'flash' => $flash,
        'nav' => page_nav_data($site_name),
        // 全站 footer：关于页入口 + 排位最前的话题词内链（话题聚合页的主内链来源之一）
        'footer_tags' => TopicTags::hot(10),
        'about_url' => route_url('about'),
        // hreflang 互补链接与 x-default（非中文访客默认英文版），只在可索引的公共页输出
        'lang_alternates' => lang_alternates(),
        // 自动语言跳转后、用户尚未做出选择时显示一次性的「切换到中文」提示条
        'i18n_banner' => current_lang() !== DEFAULT_LANG && (string)($_COOKIE[LANG_COOKIE_NAME] ?? '') === '',
    ];
}
/** 当前公共页在各语言下的绝对地址表（zh-CN / 各启用语言 / x-default→首选非中文语言），多语言关闭或非公共页返回空 */
function lang_alternates(): array
{
    static $pages = ['home', 'forum', 'topic'];
    $langs = enabled_langs();
    $route = (string)($_GET['a'] ?? 'home');
    if (!$langs || !in_array($route, $pages, true) || is_post_request()) return [];
    $alternates = [DEFAULT_LANG => absolute_url(lang_url(DEFAULT_LANG))];
    foreach ($langs as $lang) $alternates[$lang] = absolute_url(lang_url($lang));
    $alternates['x-default'] = $alternates[$langs[0]];
    return $alternates;
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
    // ****** 是打码占位符，markdown 会把连续星号当强调语法吃掉：渲染前转义、渲染后还原为字面
    $text = str_replace('******', '\*\*\*\*\*\*', $text);
    $text = html_entity_decode(strip_tags(markdown_html($text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace('\*', '*', $text);
    return cut(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), $max ?? excerpt_length());
}
/** SEO：description 低于 Bing 建议下限（150 字符）时按序补充站点定位语凑足；结果页只展示前段，长描述无副作用 */
function seo_pad_description(string $description, string $title = ''): string
{
    $description = trim($description);
    if ($description === '' || mb_strlen($description) >= 150) return $description;
    // 标题前置：SERP 只展示前 ~80 字，标题里的关键字要落在展示区
    $title = trim($title);
    if ($title !== '' && mb_strpos($description, $title) === false) $description = $title . '。' . $description;
    $parts = [];
    $parts[] = trim((string)(settings_cache()['site_description'] ?? '')) ?: default_site_description();
    $parts[] = '本站面向旧衣回收、分拣、出口与再生利用从业者，提供行情数据、行业新闻与政策解读，支持供需信息发布与行业交流。';
    // 版块清单只列一级版块，二级分类不进站点定位语
    $forum_names = implode('、', array_map(static fn(array $f): string => (string)$f['name'], array_filter(sitemap_viewable_forums(), static fn(array $f): bool => (int)($f['parent_id'] ?? 0) === 0)));
    if ($forum_names !== '') $parts[] = '覆盖' . $forum_names . '等版块，内容每日更新。';
    $parts[] = '所有内容免费开放阅读，欢迎行业同仁交流分享。';
    foreach ($parts as $p) {
        if (mb_strlen($description) >= 150) break;
        $p = trim($p);
        if ($p === '' || mb_strpos($description, $p) !== false) continue;
        // 不能用 rtrim 剥句尾标点：rtrim 按字节工作，全角标点的 UTF-8 字节会误伤正文——
        // 「！」= EF BC 81 里的 0x81 正是「流」(E6 B5 81) 的尾字节，剥掉后留下残缺序列，
        // 经 Twig 转义被 ENT_SUBSTITUTE 替换成 U+FFFD 乱码进 meta description。/u 按整字符匹配才安全。
        $description = (preg_replace('/[。．.！!？?；;\s]+$/u', '', $description) ?? $description) . '。' . $p;
    }
    return $description;
}
function page_seo(string $route, array $params = [], string $description = '', string $title = ''): array
{
    $seo = ['canonical' => absolute_url(route_url($route, $params))];
    $description = seo_pad_description(seo_text($description), $title);
    if ($description !== '') $seo['description'] = $description;
    return $seo;
}
/** TDK 队列的流量触发：页面响应结束后限频跑一小批生成，不拖慢用户请求；CLI（smoke、命令行）不触发 */
function tdk_cron_tick(): void
{
    if (PHP_SAPI === 'cli' || !TopicTDK::enabled()) return;
    $interval = max(60, (int)setting('tdk_interval_seconds', '300'));
    if (now() - (int)setting('tdk_last_run', '0') < $interval) return;
    save_settings_values(['tdk_last_run' => (string)now()]);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    try {
        TopicTDK::process_batch();
    } catch (Throwable $e) {
        debug_log_write('TDK 队列批处理失败', $e);
    }
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
        // 标题/正文真的变了才记内容更新时间：驱动 sitemap lastmod、JSON-LD dateModified 与 TDK/翻译队列重排
        $content_changed = trim((string)$t['title']) !== $title || trim((string)$t['body']) !== $body;
        Database::connection()->transaction(static function () use ($topic_id, $fid, $title, $body, $reply_order, $content_changed): void {
            $data = ['forum_id' => $fid, 'title' => $title, 'body' => $body, 'reply_order' => $reply_order];
            if ($content_changed) $data['content_updated_at'] = now();
            Topic::whereKey($topic_id)->update($data);
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
    indexnow_submit_topic($tid);
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
        turnstile_verify('login');
        $u = User::where('username', post('username', DB_STRING_MAX_LENGTH))->first(['id', 'password']);
        if ($u && password_verify((string)$_POST['password'], (string)$u->password)) {
            complete_login((int)$u->id);
            return;
        }
        err('用户名或密码错误');
    }
    render_page('login.html.twig', [
        'auth_tabs' => auth_tab_items(),
        'notice_title' => t('登录注意事项'),
        'notice_items' => [t('请使用用户名登录。'), t('密码区分大小写。'), t('公共设备登录后请及时退出。')],
    ], '登录');
}
function register_page(): void
{
    if (uid()) go(consume_auth_return_url());
    if (setting('allow_register', '1') !== '1') err('注册已关闭');
    if (is_post_request()) {
        turnstile_verify('register');
        if (array_key_exists('id', $_GET) || array_key_exists('id', $_POST)) err('参数错误');
        save_user();
        $user_id = (int)($GLOBALS['__last_saved_user_id'] ?? 0);
        if ($user_id <= 0) err('注册失败');
        start_cookie_login($user_id);
        go(consume_auth_return_url());
    }
    render_page('register.html.twig', [
        'auth_tabs' => auth_tab_items(),
        'notice_title' => t('注册注意事项'),
        'notice_items' => [t('邮箱信息不会公开。'), t('请不要使用保留用户名或冒充他人。')],
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

    $view = [
        'u' => $u,
        'profile_tabs' => $profile_tabs,
        'profile_tab' => $profile_tab,
        'registered_at' => date('Y-m-d H:i', (int)$u['created_at']),
        'api_tokens' => Mcp::tokens_for_user((int)$u['id']),
        'mcp_endpoint' => absolute_url(route_url('mcp')),
    ];

    if ($profile_tab === 'profile' && is_post_request()) {
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'mcp_token_create') {
            $view['api_tokens'] = Mcp::tokens_for_user((int)$u['id']);
            // 令牌明文只在这次响应里展示一次，不落库也不通过重定向/Flash 传递
            $view['new_token'] = Mcp::create_token((int)$u['id'], post('name', 50));
            render_page('profile.html.twig', $view, '个人设置');
            return;
        }
        if ($do === 'mcp_token_revoke') {
            Mcp::revoke_token((int)$u['id'], id());
            set_flash('API 令牌已吊销');
            go(route_url('profile'));
        }
        save_user(uid());
        set_flash('个人资料已保存');
        go(route_url('profile'));
    }
    render_page('profile.html.twig', $view, '个人设置');
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
        // 二级分类：父版块列表聚合其可见子版块的主题，子版块自身只列自己
        if ($fid) $builder->whereIn('forum_id', array_merge([$fid], forum_child_ids($fid, true)));
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
        'topics' => ['label' => t('主题'), 'href' => $url('tab=topics')],
        'replies' => ['label' => t('回帖'), 'href' => $url('tab=replies')],
    ];
    if ($own_profile) $profile_tabs['notifications'] = ['label' => t('通知'), 'href' => $url('tab=notifications')];
    if ($profile_uid) {
        if (!array_key_exists($profile_tab, $profile_tabs)) $profile_tab = 'topics';
    }
    if ($own_profile) {
        $profile_tabs['profile_settings'] = ['label' => t('设置'), 'href' => route_url('profile'), 'class' => 'tab-mobile-action'];
        if (can_access_admin()) $profile_tabs['admin'] = ['label' => t('后台'), 'href' => route_url('admin'), 'class' => 'tab-mobile-action'];
    }
    $profile_tab_allowed = true;
    $profile_tab_notice = '';
    $data = topic_index_data($fid, $filter_user, $profile_tab, $q, $search_field, $sort, $p, $size, $profile_tab_allowed, $profile_tab_notice);
    if ($profile_uid && $profile_tab_allowed && $profile_tab === 'notifications') mark_notifications_read($profile_uid, (int)$data['unread_total']);
    $title = $profile_uid ? $filter_user['username'] : ($filter_forum ? $filter_forum['name'] : '首页');
    $brand = trim((string)(settings_cache()['site_name_title'] ?? '')) ?: trim((string)(settings_cache()['site_name'] ?? '')) ?: 'FORUM';
    $page_h1 = $filter_forum ? (string)$filter_forum['name'] : ($profile_uid ? '' : $brand);
    // 可见 h1 的副行：版块页放版块描述、首页放站点一句话（与 description meta 同源），资料页没有 h1 也就不要副行
    $page_h1_sub = $profile_uid ? ''
        : ($filter_forum ? trim((string)$filter_forum['description'])
        : (trim(t((string)(settings_cache()['site_description'] ?? ''))) ?: default_site_description()));
    // 二级分类 tab：父版块显示「全部分类 + 各子分类」，子版块显示「父级(聚合) + 同级各分类」
    $category_tabs = [];
    $category_active = '';
    if ($filter_forum && !$q) {
        $parent_id = (int)($filter_forum['parent_id'] ?? 0);
        if ($parent_id > 0) {
            $parent = forum_by_id($parent_id);
            if ($parent && forum_group_allowed($parent, 'allow_view_groups')) {
                $category_tabs['all'] = ['label' => (string)$parent['name'], 'href' => route_url('forum', ['id' => $parent_id])];
                foreach (forums_cache() as $sf) {
                    if ((int)($sf['parent_id'] ?? 0) !== $parent_id || !forum_group_allowed($sf, 'allow_view_groups')) continue;
                    $key = 'f' . (int)$sf['id'];
                    $category_tabs[$key] = ['label' => (string)$sf['name'], 'href' => route_url('forum', ['id' => (int)$sf['id']])];
                    if ((int)$sf['id'] === $fid) $category_active = $key;
                }
            }
        } else {
            if (forum_child_ids($fid, true) !== []) {
                $category_tabs['all'] = ['label' => t('全部分类'), 'href' => route_url('forum', ['id' => $fid])];
                foreach (forums_cache() as $sf) {
                    if ((int)($sf['parent_id'] ?? 0) !== $fid || !forum_group_allowed($sf, 'allow_view_groups')) continue;
                    $category_tabs['f' . (int)$sf['id']] = ['label' => (string)$sf['name'], 'href' => route_url('forum', ['id' => (int)$sf['id']])];
                }
                $category_active = 'all';
            }
        }
    }
    $seo = [];
    if ($profile_uid) {
        $seo = page_seo('user', ['id' => $profile_uid], (string)($filter_user['bio'] ?? $filter_user['username']));
        // 空资料页（无主题、无回帖、无简介）不参与索引：对搜索引擎属薄内容负资产
        $seo['noindex'] = trim((string)($filter_user['bio'] ?? '')) === ''
            && !Topic::where('user_id', $profile_uid)->exists()
            && !Reply::where('user_id', $profile_uid)->exists();
    } elseif ($filter_forum) {
        $seo = page_seo('forum', ['id' => $fid] + ($p > 1 ? ['p' => $p] : []), trim((string)$filter_forum['description']) !== ''
            ? (string)$filter_forum['description']
            : '「' . $filter_forum['name'] . '」版块的最新主题与讨论——' . $brand);
        $forum_url = absolute_url(route_url('forum', ['id' => $fid]));
        $seo['jsonld'] = [
            [
                '@context' => 'https://schema.org',
                '@type' => 'CollectionPage',
                '@id' => $forum_url . '#collection',
                'url' => $forum_url,
                'name' => (string)$filter_forum['name'],
                'inLanguage' => current_lang() === DEFAULT_LANG ? 'zh-CN' : current_lang(),
                'isPartOf' => ['@id' => rtrim(base_url(), '/') . '/#website'],
            ],
            [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => t('首页'), 'item' => rtrim(base_url(), '/') . '/'],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => (string)$filter_forum['name'], 'item' => $forum_url],
                ],
            ],
        ];
    } else {
        // 列表分页的 canonical 指到自身（带 p），sitemap 里的分页链接才不会被归并到第 1 页
        // 首页 description 优先用后台站点描述（一句完整定位语）；再用品牌句兜底会与 pad 补充的
        // 站点描述语义重复，拼出堆叠的长描述
        $home_desc = trim(t((string)(settings_cache()['site_description'] ?? '')));
        $seo = page_seo('home', $p > 1 ? ['p' => $p] : [], $home_desc !== '' ? $home_desc : $brand . '：' . default_site_description());
    }
    $search_query = $q !== '' ? 'q=' . rawurlencode($q) . '&field=' . $search_field . '&' : '';
    $tab_items = ['comment' => ['label' => t('新评论'), 'href' => $url($search_query . 'sort=comment')], 'post' => ['label' => t('新帖子'), 'href' => $url($search_query . 'sort=post')]];
    $list_rows = [];
    foreach (($data['rows'] ?? []) as $t) {
        $t['time'] = (int)($t['list_time'] ?? $t['my_reply_at'] ?? ($sort === 'post' ? $t['created_at'] : ($t['last_reply_at'] ?: $t['created_at'])));
        $t['forum'] = forum_by_id((int)$t['forum_id']) ?: ['id' => 0, 'name' => ''];
        // 收费模式版块：列表标题同样打码
        if (!can_manage() && (int)($t['forum']['paid_mode'] ?? 0) === 1) $t['title'] = mask_contacts((string)$t['title']);
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
        'mobile_forums' => $q === '' ? array_values(array_filter(forums_cache(), fn($f) => (int)($f['parent_id'] ?? 0) === 0 && forum_group_allowed($f, 'allow_view_groups'))) : [],
        'tab_items' => $tab_items,
        'rows' => $data['rows'],
        'list_rows' => $list_rows,
        'notification_rows' => $notification_rows,
        'empty_text' => $empty_text,
        'page_h1' => $page_h1,
        'page_h1_sub' => $page_h1_sub,
        'category_tabs' => $category_tabs,
        'category_active' => $category_active,
        'pagination' => $pagination,
        'sidebar_user' => $profile_uid ? $filter_user : null,
        // 首页第一页侧栏挂话题词入口：给聚合页一批全站最重的内链
        'tag_chips' => $is_home_first_page ? TopicTags::chips() : [],
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
/** /tag/{关键词}：站内话题词库的关键词聚合页——同话题主题聚合成一个落地页（programmatic SEO） */
function tag_page(): void
{
    $kw = trim((string)($_GET['kw'] ?? ''));
    if ($kw === '' || search_char_count($kw) > 30) err('你访问的页面不存在', 404);
    // 话题页只有中文内容：非默认语言一律回中文页，避免同内容多语言重复收录
    if (current_lang() !== DEFAULT_LANG) go(route_url('tag', ['kw' => $kw], DEFAULT_LANG));
    TopicTags::ensure_ready();
    $tag = TopicTags::find_active($kw) ?: err('你访问的页面不存在', 404);
    $rows = TopicTags::topic_rows($kw, 100);
    $seo = page_seo('tag', ['kw' => $kw], (string)$tag['summary'], (string)$tag['keyword']);
    $tag_url = absolute_url(route_url('tag', ['kw' => $kw]));
    $seo['jsonld'] = [
        [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            '@id' => $tag_url . '#collection',
            'url' => $tag_url,
            'name' => (string)$tag['keyword'],
            'description' => (string)$tag['summary'],
            'inLanguage' => 'zh-CN',
            'isPartOf' => ['@id' => rtrim(base_url(), '/') . '/#website'],
        ],
        [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => t('首页'), 'item' => rtrim(base_url(), '/') . '/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => (string)$tag['keyword'], 'item' => $tag_url],
            ],
        ],
    ];
    render_page('tag.html.twig', [
        'tag' => $tag,
        'rows' => $rows,
        'siblings' => TopicTags::siblings((int)$tag['id'], (string)$tag['chain']),
        'search_url' => route_url('search', ['q' => $kw]),
    ], (string)$tag['keyword'], $seo);
}
/** /about：关于本站——组织实体的 E-E-A-T 落地页（FAQ 手写内容 + FAQPage 结构化数据）。内容只做中文，非默认语言回中文页 */
function about_page(): void
{
    if (current_lang() !== DEFAULT_LANG) go(route_url('about', [], DEFAULT_LANG));
    $description = '全球旧衣资讯网是面向旧衣回收、分拣批发与二手服装出口贸易从业者的行业资讯与交流社区，汇总全球回收、出口与再生利用的行业动态与行情数据。';
    $seo = page_seo('about', [], $description, '关于本站');
    $faq = [
        ['全球旧衣资讯网是什么？', '面向旧衣回收、二手服装批发与出口贸易从业者的行业资讯与交流社区，全站内容免费浏览，行业动态每日更新。'],
        ['站内资讯内容从哪里来？', '行业动态等资讯版块的内容由编辑流程从全球行业媒体的公开报道聚合改写而来，每篇文末标注原文来源与发布时间；货源、原料等供需信息由行业用户自行发布。'],
        ['如何发布供应或求购信息？', '注册账号后选择对应版块发帖即可。收费版块的联系方式对访客打码，注册登录后可见。'],
        ['如何联系站点？', '可在行业动态版块发帖留言，编辑部会定期查看处理。'],
    ];
    $seo['jsonld'] = [
        [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(static fn(array $qa): array => [
                '@type' => 'Question',
                'name' => $qa[0],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]],
            ], $faq),
        ],
    ];
    render_page('about.html.twig', ['faq' => $faq, 'about_description' => $description], '关于本站', $seo);
}
function topic_page_replies(array $topic, int $page, int $size, int $offset, bool $reply_desc): array
{
    $ctx = ['topic' => $topic, 'page' => $page, 'page_size' => $size, 'offset' => $offset, 'reply_order' => $reply_desc ? 1 : 0];
    $reply_query = Reply::where('topic_id', (int)$topic['id']);
    $reply_query = $reply_desc
        ? $reply_query->orderByDesc('created_at')->orderByDesc('id')
        : $reply_query->orderBy('created_at')->orderBy('id');
    $replies = $reply_query->limit($size)->offset($offset)->get()->map->toArray()->all();
    // 收费模式版块的回帖同样脱敏（原文不动）
    if (forum_paid_mode((int)$topic['forum_id']) && !can_manage()) {
        foreach ($replies as &$r) $r['body'] = mask_contacts((string)$r['body']);
        unset($r);
    }
    $posts = attach_users(array_merge([$topic], $replies));
    $topic = array_shift($posts);
    $replies = $posts;
    $data = ['topic' => $topic, 'replies' => $replies];
    return $data;
}
function topic_page_view(array $view): array
{
    extract($view, EXTR_SKIP);
    // 打码占位符 ****** 经 markdown 渲染会被当强调语法吃掉：渲染前转义，页面显示字面星号
    $t['body'] = str_replace('******', '\*\*\*\*\*\*', (string)$t['body']);
    foreach ($replies as &$r) $r['body'] = str_replace('******', '\*\*\*\*\*\*', (string)$r['body']);
    unset($r);
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
        // 相关话题交叉内链只做中文（话题页本身 zh-only），英文页挂了也是死链中文词
        'topic_tags' => current_lang() === DEFAULT_LANG ? TopicTags::for_topic_title((string)$t['title']) : [],
        'reply_status' => t(uid() ? (can_speak() ? ($can_reply_forum ? '说两句' : '无回帖权限') : '禁止发言') : '登录后回复'),
        'i18n_pending' => (bool)($i18n_pending ?? false),
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
    // 收费模式版块：非管理员看到的内容先脱敏，标题/正文/摘要/TDK 全部走打码后的数据
    $t = mask_topic_contacts($t);
    // 多语言：优先用译文（译文源自打码后文本，英文页对所有人一致）；缺译文先展示原文并登记响应后补翻。
    // 管理员看收费版块拿到的是未打码原文，与译文指纹口径不同，直接跳过覆盖避免误判编辑。
    $t_i18n = null;
    if (current_lang() !== DEFAULT_LANG && !(forum_paid_mode((int)$t['forum_id']) && can_manage())) {
        $t_i18n = Translator::content_for(current_lang(), 'topic', (int)$t['id']);
        if ($t_i18n !== null) {
            if ($t_i18n['fingerprint'] !== Translator::fingerprint((string)$t['title'], (string)$t['body'])) {
                Translator::requeue(current_lang(), 'topic', (int)$t['id']);
            }
            if ($t_i18n['title'] !== '') $t['title'] = $t_i18n['title'];
            if ($t_i18n['body'] !== '') $t['body'] = $t_i18n['body'];
        } else {
            $GLOBALS['__i18n_pending_topic'] = (int)$t['id'];
            $GLOBALS['__i18n_pending_lang'] = current_lang();
        }
    }
    if (mark_viewed((int)$t['id'])) {
        Topic::whereKey($t['id'])->increment('view_count');
        record_view_stat();
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
    $t_seo = page_seo('topic', ['id' => (int)$t['id']], (string)$t['body'], (string)$t['title']);
    // 英文页的 SEO 描述直接用译文摘要；中文 TDK 队列结果只在中文页应用
    if ($t_i18n !== null && trim($t_i18n['description']) !== '') {
        $t_seo['description'] = trim($t_i18n['description']);
        $t_tdk = null;
    } else {
        // TDK 队列生成结果优先：title/description 换成生成值（描述仍过补足规则保证不低于 Bing 下限），keywords 进 meta 与 JSON-LD
        $t_tdk = current_lang() === DEFAULT_LANG ? TopicTDK::meta_for((int)$t['id']) : null;
    }
    if ($t_tdk) {
        // 存量 TDK 生成于打码功能之前，收费版块（非管理员）应用时统一再脱敏一次
        if (forum_paid_mode((int)$t['forum_id']) && !can_manage()) {
            $t_tdk = array_map(static fn($v) => is_string($v) ? mask_contacts($v) : $v, $t_tdk);
        }
        if (trim((string)$t_tdk['title']) !== '') $t_tdk_title = cut(trim((string)$t_tdk['title']), (int)setting('tdk_title_max', '45'));
        if (trim((string)$t_tdk['description']) !== '') $t_seo['description'] = seo_pad_description(seo_text((string)$t_tdk['description']));
        if (trim((string)$t_tdk['keywords']) !== '') $t_seo['keywords'] = trim((string)$t_tdk['keywords']);
    }
    $t_base = rtrim(base_url(), '/');
    $topic_url = $t_seo['canonical'];
    // og:image 取正文第一张非 SVG 图片；SVG 不是社交平台支持的预览格式
    $t_image = '';
    if (preg_match('/<img[^>]*\ssrc="([^"]+)"/i', (string)$t['body'], $img_m)) {
        $img_src = html_entity_decode((string)$img_m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!preg_match('/\.svg(?:[?#]|$)/i', $img_src)) $t_image = absolute_url($img_src);
    }
    $t_seo['jsonld'] = [
        [
            '@context' => 'https://schema.org',
            '@type' => 'DiscussionForumPosting',
            '@id' => $topic_url . '#posting',
            'url' => $topic_url,
            'headline' => (string)$t['title'],
            'inLanguage' => current_lang() === DEFAULT_LANG ? 'zh-CN' : current_lang(),
            'datePublished' => sitemap_w3c((int)$t['created_at']),
            'dateModified' => sitemap_w3c(max((int)$t['created_at'], (int)($t['last_reply_at'] ?: 0), (int)($t['content_updated_at'] ?? 0))),
            'author' => [
                '@type' => 'Person',
                'name' => (string)($t['username'] ?? ''),
                'url' => absolute_url(route_url('user', ['id' => (int)$t['user_id']])),
            ],
            'interactionStatistic' => [
                ['@type' => 'InteractionCounter', 'interactionType' => 'https://schema.org/ViewAction', 'userInteractionCount' => (int)$t['view_count']],
                ['@type' => 'InteractionCounter', 'interactionType' => 'https://schema.org/CommentAction', 'userInteractionCount' => (int)$t['reply_count']],
            ],
            'publisher' => ['@id' => $t_base . '/#organization'],
        ] + (isset($t_seo['keywords']) ? ['keywords' => array_map('trim', explode(',', (string)$t_seo['keywords']))] : []),
        [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => t('首页'), 'item' => $t_base . '/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => (string)$forum['name'], 'item' => absolute_url(route_url('forum', ['id' => (int)$forum['id']]))],
                ['@type' => 'ListItem', 'position' => 3, 'name' => (string)$t['title'], 'item' => $topic_url],
            ],
        ],
    ];
    $t_seo['og_type'] = 'article';
    if ($t_image !== '') $t_seo['image'] = $t_image;
    // <title> 标题段截断：默认规则截 32 字保「标题 - 版块 - 站名」整体宽度；TDK 生成的标题已按 tdk_title_max 控长，放宽到 45 字
    $t_title_seo = isset($t_tdk_title) ? $t_tdk_title : (string)$t['title'];
    $t_title_cap = isset($t_tdk_title) ? (int)setting('tdk_title_max', '45') : 32;
    if (mb_strlen($t_title_seo) > $t_title_cap) $t_title_seo = cut($t_title_seo, $t_title_cap) . '…';
    $view['i18n_pending'] = $t_i18n === null && current_lang() !== DEFAULT_LANG;
    render_page('topic.html.twig', topic_page_view($view), $t_title_seo . ' - ' . $forum['name'], $t_seo);
}
function topic_edit_page(): void
{
    need_speak();
    // 本页 POST 既可能发主题也可能删/置顶/高亮，一律先过人机验证再读库
    if (is_post_request()) turnstile_verify('topic');
    $topic_id = id();
    $editing = $topic_id > 0;
    $t = ['id' => 0, 'forum_id' => id('fid') ?: default_post_forum_id(), 'title' => '', 'body' => '', 'user_id' => uid()];
    if ($editing) {
        $t = Topic::find($topic_id)?->toArray() ?: err('主题不存在');
        if (!can_manage_topic($t)) err('无权限');
    }
    if (is_post_request()) go(route_url('topic', ['id' => save_topic()]));
    $title = t($editing ? '编辑主题' : '发表主题');
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
        'loading_text' => t($editing ? '正在保存' : '正在发帖'),
    ], $title);
}
function reply_edit_page(): void
{
    need_speak();
    // 编辑页上挂着删除/禁言等操作，整页 POST 一律先过人机验证
    if (is_post_request()) turnstile_verify('reply');
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
    foreach (['settings' => '设置', 'verify' => '站点验证', 'analytics' => '统计', 'tdk' => 'SEO TDK', 'i18n' => '多语言', 'forums' => '版块', 'groups' => '用户组', 'topics' => '帖子管理', 'users' => '用户管理', 'report' => '数据报表', 'mcp' => 'MCP日志'] as $key => $label) {
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
/** sitemap 单文件 URL 数量上限：协议上限 5 万条/50MB，按 1 万条切分留足余量 */
const SITEMAP_TOPICS_PER_FILE = 10000;
/** sitemap 协议单文件 URL 硬上限：/sitemap.xml 按它截断，装不下的主题仍走分片文件 */
const SITEMAP_MAX_URLS = 50000;

/** 游客（即爬虫）可见的版块：allow_view_groups 为空表示不限制，否则须显式包含游客组 0 */
function sitemap_viewable_forums(): array
{
    return array_values(array_filter(forums_cache(), static function (array $f): bool {
        $ids = forum_group_ids($f, 'allow_view_groups');
        return $ids === [] || in_array(0, $ids, true);
    }));
}

/** sitemap 的 W3C Datetime 时间格式：站点时区带偏移量（如 2026-09-25T12:00:00+08:00） */
function sitemap_w3c(int $ts): string
{
    return date('Y-m-d\TH:i:sP', $ts);
}

/** sitemap 的 <url> 条目：lastmod 小于等于 0 时省略（协议允许省略） */
function sitemap_url_xml(string $loc, int $lastmod = 0, array $alternates = []): string
{
    $lines = ['    <loc>' . h($loc) . '</loc>'];
    foreach ($alternates as $hreflang => $alt_url) {
        $lines[] = '    <xhtml:link rel="alternate" hreflang="' . h($hreflang) . '" href="' . h($alt_url) . '"/>';
    }
    if ($lastmod > 0) $lines[] = '    <lastmod>' . sitemap_w3c($lastmod) . '</lastmod>';
    return "  <url>\n" . implode("\n", $lines) . "\n  </url>";
}

/** 同一页面的全语言地址表：键为 hreflang 值（zh-CN / 语言码 / x-default），值均为绝对地址 */
function sitemap_lang_map(string $route, array $params): array
{
    $langs = enabled_langs();
    $map = ['zh-CN' => absolute_url(route_url($route, $params, DEFAULT_LANG))];
    foreach ($langs as $lang) $map[$lang] = absolute_url(route_url($route, $params, $lang));
    $map['x-default'] = $map[$langs[0] ?? DEFAULT_LANG];
    return $map;
}

/** 多语言下的 sitemap 条目：每种语言一个 <url>，各带全语言互补标注；多语言关闭时退化为单条 */
function sitemap_page_url_langs(string $route, array $params = [], int $lastmod = 0): array
{
    $langs = enabled_langs();
    if (!$langs) return [sitemap_url_xml(absolute_url(route_url($route, $params)), $lastmod)];
    $map = sitemap_lang_map($route, $params);
    $urls = [];
    foreach ($map as $hreflang => $url) {
        if ($hreflang === 'x-default') continue;
        $urls[] = sitemap_url_xml($url, $lastmod, $map);
    }
    return $urls;
}

/** 主题的 <lastmod>：回帖会刷新 last_reply_at，取两者较大值兜底（旧数据可能为 0） */
function sitemap_topic_lastmod(array $t): int
{
    return max((int)($t['last_reply_at'] ?? 0), (int)($t['created_at'] ?? 0), (int)($t['content_updated_at'] ?? 0));
}

/** 机器可读出口（sitemap）：与 json_response 同级的直出，不走 render_page */
function sitemap_xml_response(string $xml): never
{
    header('Content-Type: application/xml; charset=UTF-8');
    header('Cache-Control: public, max-age=3600');
    // 浏览器直接打开时 XML 默认无样式，深色主题下透明背景黑底黑字像打不开；
    // 挂一个只管观感的 CSS，搜索引擎与工具照常按纯 XML 解析
    $pi = '<?xml-stylesheet type="text/css" href="' . h(app_url('app/assets/sitemap.css')) . '?v=' . APP_VERSION . '"?>';
    $xml = preg_replace('/^<\?xml[^?]*\?>/', '$0' . "\n" . $pi, $xml, 1, $count);
    echo $count === 1 ? $xml : $pi . "\n" . $xml;
    exit;
}

/** 首页 + 游客可见版块列表页（含分页页）的 <url> 条目。切页大小与前台一致（topics_per_page），
 * 页数同样受最大分页数约束——超出上限的页码会被前台钳到最后一页，生成链接只会产出重复内容 */
function sitemap_page_urls(): array
{
    $urls = sitemap_page_url_langs('home');
    // 关于页只有中文：以 zh URL 进 sitemap（同话题页口径）
    $urls[] = '<url><loc>' . h(absolute_url(route_url('about', [], DEFAULT_LANG))) . '</loc></url>';
    $size = max(1, (int)setting('topics_per_page', '30'));
    $max_pages = max_pagination_pages();
    // 首页列表的第 1 页就是站点根，分页链接从第 2 页开始
    $home_pages = min($max_pages, (int)ceil(Topic::count() / $size));
    for ($p = 2; $p <= $home_pages; $p++) $urls = array_merge($urls, sitemap_page_url_langs('home', ['p' => $p]));
    foreach (sitemap_viewable_forums() as $f) {
        $fid = (int)$f['id'];
        $lastmod = (int)Topic::where('forum_id', $fid)->max('last_reply_at');
        $urls = array_merge($urls, sitemap_page_url_langs('forum', ['id' => $fid], $lastmod));
        // 与列表页同口径：一级版块聚合可见子分类的主题后再切页
        $total = Topic::whereIn('forum_id', array_merge([$fid], forum_child_ids($fid, true)))->count();
        $pages = min($max_pages, (int)ceil($total / $size));
        for ($p = 2; $p <= $pages; $p++) $urls = array_merge($urls, sitemap_page_url_langs('forum', ['id' => $fid, 'p' => $p], $lastmod));
    }
    return $urls;
}

/** /sitemap.xml：单张平面 urlset（首页+版块页+全部游客可见主题）。头条等国内平台不收索引型 sitemap，百度也不再处理索引型，统一输出平面格式 */
function sitemap_root_route(): void
{
    $urls = sitemap_page_urls();
    // 话题聚合页排在主题前面：它们是长尾搜索的主落地页，优先保证被分发
    foreach (TopicTags::active() as $tag) {
        $urls[] = '<url><loc>' . h(absolute_url(route_url('tag', ['kw' => (string)$tag['keyword']], DEFAULT_LANG))) . '</loc></url>';
    }
    $forum_ids = array_map(static fn(array $f): int => (int)$f['id'], sitemap_viewable_forums());
    // 协议单文件上限 5 万条：主题按 id 升序填满剩余额度，装不下的仍可经 /sitemap-topics-{n}.xml 单独提交
    $remaining = SITEMAP_MAX_URLS - count($urls);
    if ($forum_ids && $remaining > 0) {
        $rows = Topic::whereIn('forum_id', $forum_ids)->orderBy('id')->limit($remaining)->get(['id', 'created_at', 'last_reply_at', 'content_updated_at']);
        foreach ($rows as $t) {
            $urls = array_merge($urls, sitemap_page_url_langs('topic', ['id' => (int)$t->id], sitemap_topic_lastmod($t->toArray())));
        }
    }
    sitemap_xml_response('<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n"
        . implode("\n", $urls) . "\n</urlset>\n");
}

/** /sitemap-tags.xml：话题聚合页单独一份，便于搜索引擎分组提交与诊断收录 */
function sitemap_tags_route(): void
{
    $urls = [];
    foreach (TopicTags::active() as $tag) {
        $urls[] = '<url><loc>' . h(absolute_url(route_url('tag', ['kw' => (string)$tag['keyword']], DEFAULT_LANG))) . '</loc></url>';
    }
    sitemap_xml_response('<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
        . ($urls ? implode("\n", $urls) . "\n" : '') . '</urlset>' . "\n");
}

/** /sitemap-pages.xml：首页 + 游客可见版块列表页（含分页页），lastmod 取该版块最新回帖时间 */
function sitemap_pages_route(): void
{
    $urls = sitemap_page_urls();
    sitemap_xml_response('<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n"
        . implode("\n", $urls) . "\n</urlset>\n");
}

/** /sitemap-topics-{n}.xml：游客可见主题按 id 升序每 1 万条一个文件，超出分片数报 404 */
function sitemap_topics_route(int $page): void
{
    $forum_ids = array_map(static fn(array $f): int => (int)$f['id'], sitemap_viewable_forums());
    $query = Topic::whereIn('forum_id', $forum_ids);
    $total = (clone $query)->count();
    if ($page < 1 || $page > max(1, (int)ceil($total / SITEMAP_TOPICS_PER_FILE))) err('你访问的页面不存在', 404);
    $rows = $query->orderBy('id')->limit(SITEMAP_TOPICS_PER_FILE)->offset(($page - 1) * SITEMAP_TOPICS_PER_FILE)
        ->get(['id', 'created_at', 'last_reply_at', 'content_updated_at']);
    $urls = [];
    foreach ($rows as $t) {
        $urls = array_merge($urls, sitemap_page_url_langs('topic', ['id' => (int)$t->id], sitemap_topic_lastmod($t->toArray())));
    }
    sitemap_xml_response('<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n"
        . ($urls ? implode("\n", $urls) . "\n" : '') . '</urlset>' . "\n");
}

/** /robots.txt：后台、登录注册、编辑与表单等对爬虫无意义的路径全站禁止，并声明 sitemap 位置 */
function robots_txt_route(): void
{
    $lines = ['User-agent: *'];
    // 路径形如 /{a}/{id}，首段即路由名；个人资料页是登录后的设置页，与后台一并禁抓
    foreach (['admin', 'login', 'logout', 'register', 'profile', 'form_error', 'preview', 'mobile_menu', 'delete', 'topic_edit', 'reply_edit', 'mcp', 'search', 'lang'] as $a) {
        $lines[] = 'Disallow: /' . $a;
    }
    $lines[] = 'Disallow: /app/';
    $lines[] = '';
    $lines[] = 'Sitemap: ' . absolute_url(app_url('sitemap.xml'));
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: public, max-age=3600');
    echo implode("\n", $lines) . "\n";
    exit;
}
/** /seo-indexnow-key.txt：IndexNow 的 key 文件（Bing/Yandex 快速收录通道的归属验证），key 在站点设置 indexnow_key 里 */
function indexnow_key_route(): void
{
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: public, max-age=3600');
    echo setting('indexnow_key', '') . "\n";
    exit;
}
/** 新主题发布后把 URL 提交到 IndexNow（api.indexnow.org 会分发给 Bing/Yandex 等）。尽力而为：超时/失败静默，不影响发布 */
function indexnow_submit_topic(int $topic_id): void
{
    $key = trim((string)setting('indexnow_key', ''));
    if ($key === '' || $topic_id <= 0) return;
    $payload = json_encode([
        'host' => (string)parse_url(base_url(), PHP_URL_HOST),
        'key' => $key,
        'keyLocation' => absolute_url('seo-indexnow-key.txt'),
        'urlList' => [absolute_url(route_url('topic', ['id' => $topic_id]))],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json; charset=utf-8\r\n",
        'content' => $payload,
        'timeout' => 2.0,
        'ignore_errors' => true,
    ]]);
    try {
        @file_get_contents('https://api.indexnow.org/indexnow', false, $context);
    } catch (Throwable) {
        // 加速收录通道，失败不需要兜底动作
    }
}
/** /{神马验证码}[.html]：神马站长平台文件验证（平台要求下载的验证文件原样放根目录，这里按站点设置直接输出验证码） */
function sm_verify_file_route(): void
{
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-cache');
    echo setting('sm_verification', '');
    exit;
}
/** /llms.txt：面向 AI 系统的站点说明（llmstxt.org 社区规范；Google 声明不用于搜索排名，供其他 AI 系统选用） */
function llms_txt_route(): void
{
    $settings = settings_cache();
    $site_name = trim((string)$settings['site_name']) ?: 'FORUM';
    $description = trim((string)($settings['site_description'] ?? ''));
    if ($description === '') $description = default_site_description();
    $lines = ['# ' . $site_name, '', '> ' . t($description), '', t('## 版块')];
    foreach (sitemap_viewable_forums() as $f) {
        $intro = cut(seo_text((string)($f['description'] ?? '')), 80);
        $lines[] = '- [' . $f['name'] . '](' . absolute_url(route_url('forum', ['id' => (int)$f['id']])) . ')' . t('：') . ($intro !== '' ? $intro : t('该版块的最新主题与讨论'));
    }
    $lines[] = '';
    $lines[] = t('## 产业链话题');
    // 话题聚合页是按关键词聚合的主题集，AI 系统可按话题直接取整组相关内容
    foreach (TopicTags::active() as $tag_row) {
        $lines[] = '- [' . $tag_row['keyword'] . '](' . absolute_url(route_url('tag', ['kw' => (string)$tag_row['keyword']], DEFAULT_LANG)) . ')' . t('：') . cut(seo_text((string)$tag_row['summary']), 80);
    }
    $lines[] = '';
    $lines[] = t('## 使用说明');
    $lines[] = '- ' . t('主题页 URL 形如 ') . absolute_url(route_url('topic', ['id' => 1])) . t('（替换数字 id）');
    $lines[] = '- ' . t('站点介绍（关于页）：') . absolute_url(route_url('about', [], DEFAULT_LANG));
    $lines[] = t('- 行情类主题包含当日价格数据（元/kg），引用时请注明发布日期');
    $lines[] = t('- 全站内容为服务端渲染，无需执行 JavaScript 即可读取');
    $lines[] = t('- 最新内容订阅源（RSS 2.0）：') . absolute_url(route_url('feed.xml'));
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: public, max-age=3600');
    echo implode("\n", $lines) . "\n";
    exit;
}
/** /feed.xml：RSS 2.0 订阅源，取游客可见版块最新 feed_size 篇主题（订阅器与聚合端发现更新的常规通道；全量发现走 sitemap） */
function feed_route(): void
{
    $settings = settings_cache();
    $site_name = trim((string)$settings['site_name']) ?: 'FORUM';
    $site_desc = trim(t((string)($settings['site_description'] ?? ''))) ?: default_site_description();
    $viewable = sitemap_viewable_forums();
    $forum_ids = array_map(static fn(array $f): int => (int)$f['id'], $viewable);
    $forum_names = array_column($viewable, 'name', 'id');
    $size = max(1, min(500, (int)setting('feed_size', '50')));
    $items = '';
    $last_build = 0;
    if ($forum_ids) {
        $rows = Topic::whereIn('forum_id', $forum_ids)->orderByDesc('created_at')->orderByDesc('id')
            ->limit($size)->get(['id', 'title', 'body', 'created_at', 'forum_id']);
        $t_map = current_lang() !== DEFAULT_LANG ? Translator::topic_titles(current_lang(), array_map(static fn($row) => (int)$row['id'], $rows->toArray())) : [];
        foreach ($rows as $t) {
            $fid = (int)$t->forum_id;
            $title = (string)$t->title;
            $excerpt = seo_text((string)$t->body, 200);
            // 非默认语言：标题与摘要优先用译文（无译文回落中文原文）
            $translated = (array)($t_map[(int)$t->id] ?? []);
            if (trim((string)($translated['title'] ?? '')) !== '') $title = (string)$translated['title'];
            if (trim((string)($translated['description'] ?? '')) !== '') $excerpt = (string)$translated['description'];
            // 收费版块与前台列表同口径：公开出口的标题与摘要一律打码
            if (forum_paid_mode($fid)) {
                $title = mask_contacts($title);
                $excerpt = mask_contacts($excerpt);
            }
            $url = absolute_url(route_url('topic', ['id' => (int)$t->id]));
            $pub = (int)$t->created_at;
            $last_build = max($last_build, $pub);
            $items .= "    <item>\n"
                . '      <title>' . h($title) . "</title>\n"
                . '      <link>' . h($url) . "</link>\n"
                . '      <guid isPermaLink="true">' . h($url) . "</guid>\n"
                . '      <pubDate>' . gmdate('r', $pub) . "</pubDate>\n"
                . '      <category>' . h((string)($forum_names[$fid] ?? '')) . "</category>\n"
                . '      <description>' . h($excerpt) . "</description>\n"
                . "    </item>\n";
        }
    }
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<rss version="2.0">' . "\n"
        . "  <channel>\n"
        . '    <title>' . h($site_name) . "</title>\n"
        . '    <link>' . h(absolute_url(route_url('home'))) . "</link>\n"
        . '    <description>' . h($site_desc) . "</description>\n"
        . '    <language>' . (current_lang() === DEFAULT_LANG ? 'zh-cn' : current_lang()) . "</language>\n"
        . ($last_build > 0 ? '    <lastBuildDate>' . gmdate('r', $last_build) . "</lastBuildDate>\n" : '')
        . rtrim($items, "\n") . "\n"
        . "  </channel>\n"
        . "</rss>\n";
    header('Content-Type: application/rss+xml; charset=UTF-8');
    header('Cache-Control: public, max-age=600');
    echo $xml;
    exit;
}
function core_routes(): array
{
    return [
        'home' => 'home_page',
        'lang' => 'lang_switch_route',
        'search' => [Search::class, 'page'],
        'sitemap.xml' => 'sitemap_root_route',
        'sitemap-pages.xml' => 'sitemap_pages_route',
        'sitemap-tags.xml' => 'sitemap_tags_route',
        'robots.txt' => 'robots_txt_route',
        'llms.txt' => 'llms_txt_route',
        'feed.xml' => 'feed_route',
        'seo-indexnow-key.txt' => 'indexnow_key_route',
        'mobile_menu' => 'mobile_menu_route',
        'preview' => 'preview_route',
        'forum' => 'forum_page',
        'topic' => 'topic_page',
        'tag' => 'tag_page',
        'about' => 'about_page',
        'user' => 'user_page',
        'login' => 'login_page',
        'logout' => 'logout_route',
        'register' => 'register_page',
        'form_error' => 'form_error_route',
        'profile' => 'profile_page',
        'topic_edit' => 'topic_edit_page',
        'reply_edit' => 'reply_edit_page',
        'delete' => 'delete_route',
        'mcp' => [Mcp::class, 'route'],
        'admin' => [Admin::class, 'route'],
    ];
}
parse_path_route();
// 先接上 Eloquent 再初始化，Bootstrap 里的建表和造数都走它
Database::boot();
if (!db_schema_ready()) Bootstrap::run();
ensure_schema_bumps();
i18n_init();
check();
need_site_access();
// canonical_host_redirect() 保持停用：http→https 与 apex→www 的 301 已上移到宿主 nginx
// （80 与裸域 443 一律 301 到 https://www.cncttc.com，见 docs/DEPLOY-PRODUCTION.md），
// 这里再跳会变成双重重定向；canonical 等绝对地址仍由 site_base_url 生成
try {
    if (($_GET['__route_not_found'] ?? '') === '1') {
        err(($_GET['__route_not_found_kind'] ?? '') === 'topic' ? '你访问的帖子可能已经删除' : '你访问的页面不存在', 404);
    }
    $route = (string)($_GET['a'] ?? 'home');
    i18n_redirect_guard($route);
    $handler = core_routes()[$route] ?? null;
    if ($handler !== null) $handler();
    // 主题分片文件名带序号（/sitemap-topics-2.xml），无法静态注册进 core_routes()
    elseif (preg_match('/^sitemap-topics-(\d+)\.xml$/D', $route, $m)) sitemap_topics_route((int)$m[1]);
    // 神马文件验证的路径带验证码本身（/{验证码} 或 /{验证码}.html），同样无法静态注册
    elseif (($code = setting('sm_verification', '')) !== '' && preg_match('/^' . preg_quote($code, '/') . '(?:\.html?)?$/D', $route)) sm_verify_file_route();
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
// 响应已交付：流量触发的 TDK 队列批处理（限频 + 租约互斥，见 tdk_cron_tick / TopicTDK::process_batch）
tdk_cron_tick();
i18n_cron_tick();
i18n_view_tick();
/** 流量触发的翻译队列批处理：与 TDK 队列同一套限频 + fastcgi_finish_request 模式 */
function i18n_cron_tick(): void
{
    if (PHP_SAPI === 'cli' || !Translator::enabled()) return;
    $interval = max(30, (int)setting('i18n_interval_seconds', '120'));
    if (now() - (int)setting('i18n_last_run', '0') < $interval) return;
    save_settings_values(['i18n_last_run' => (string)now()]);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    try {
        Translator::process_batch();
    } catch (Throwable $e) {
        debug_log_write('翻译队列批处理失败', $e);
    }
}
/** 详情页英文访客首访缺译文：响应后立即补翻当前页（限流 15 秒，撞车交给队列 tick） */
function i18n_view_tick(): void
{
    $topic_id = (int)($GLOBALS['__i18n_pending_topic'] ?? 0);
    $lang = (string)($GLOBALS['__i18n_pending_lang'] ?? '');
    if ($topic_id <= 0 || $lang === '' || PHP_SAPI === 'cli' || !Translator::enabled()) return;
    if (now() - (int)setting('i18n_view_run_at', '0') < 15) return;
    save_settings_values(['i18n_view_run_at' => (string)now()]);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    try {
        Translator::translate_topic($lang, $topic_id);
    } catch (Throwable $e) {
        debug_log_write('单篇即时翻译失败', $e);
    }
}
i18n_cron_tick();
