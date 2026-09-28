<?php

declare(strict_types=1);

namespace app\optional;

use PDO;
use Throwable;

if (!defined('APP_ROOT')) exit;

/**
 * 内容翻译队列：把帖子标题/正文与版块名/介绍机翻成已启用的语言（当前 en），结果永久缓存。
 *
 * 存储：plugin_i18n_content 表，(lang,target_type,target_id) 唯一；status='done' 为可用译文，
 * 'failed' 为失败占位（attempts 计数，超限放弃）。fingerprint 是原文 sha1，帖子被编辑后
 * 服务侧比对发现不一致就降级重排队列。队列待处理集 = 无记录、失败未超限，按 id 降序
 * （新帖优先，列表页最快变英文）。并发互斥用 app_settings 行 i18n_lease_until 的条件
 * UPDATE 乐观租约，与 TopicTDK 队列同一套模式。
 * AI 复用 TDK 的 DeepSeek 接口配置（tdk_api_base / tdk_model / 环境变量密钥）。
 */
final class Translator
{
    public const SCHEMA_VERSION = 1;
    private const ATTEMPT_LIMIT = 5;
    /** 喂给模型的正文长度上限：超长帖不硬翻，英文页回落展示原文 */
    private const FEED_BODY_CHARS = 8000;

    public static function enabled(): bool
    {
        return enabled_langs() !== [];
    }

    /** 翻译接口密钥：与 SEO TDK 共用同一环境变量，不落库不入代码 */
    public static function api_key(): string
    {
        return TopicTDK::api_key();
    }

    /** 幂等建表：只在 i18n_schema_version 落后时执行一次 */
    public static function ensure_schema(): void
    {
        if ((int)setting('i18n_schema_version', '0') >= self::SCHEMA_VERSION) return;
        $db = db();
        $db->exec("CREATE TABLE IF NOT EXISTS plugin_i18n_content(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            lang TEXT NOT NULL,
            target_type TEXT NOT NULL DEFAULT 'topic',
            target_id INTEGER NOT NULL,
            fingerprint TEXT NOT NULL DEFAULT '',
            title TEXT NOT NULL DEFAULT '',
            body TEXT NOT NULL DEFAULT '',
            description TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'done',
            attempts INTEGER NOT NULL DEFAULT 0,
            last_error TEXT NOT NULL DEFAULT '',
            duration_ms INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL DEFAULT 0,
            updated_at INTEGER NOT NULL DEFAULT 0
        )");
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_i18n_target ON plugin_i18n_content(lang, target_type, target_id)');
        save_settings_values(['i18n_schema_version' => (string)self::SCHEMA_VERSION]);
    }

    /** 帖子译文指纹：原文标题+正文变化（编辑）即视为过期 */
    public static function fingerprint(string $title, string $body): string
    {
        return hash('sha256', $title . "\n" . $body);
    }

    /** 内容被编辑后把译文标记为待重翻：降级为 failed、attempts 归零，自然回到队列最前（按 id 降序消费） */
    public static function requeue(string $lang, string $type, int $id): void
    {
        $st = db()->prepare("UPDATE plugin_i18n_content SET status='failed', attempts=0, last_error='内容已更新，待重翻', updated_at=?
            WHERE lang=? AND target_type=? AND target_id=? AND status='done'");
        $st->execute([now(), $lang, $type, $id]);
    }

    /** 详情页/版块页取译文：无记录或失败占位返回 null（页面回落原文），status='done' 原样返回 */
    public static function content_for(string $lang, string $type, int $id): ?array
    {
        if (!self::enabled()) return null;
        self::ensure_schema();
        $st = db()->prepare("SELECT title, body, description, fingerprint FROM plugin_i18n_content
            WHERE lang=? AND target_type=? AND target_id=? AND status='done'");
        $st->execute([$lang, $type, $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        return [
            'title' => (string)$row['title'],
            'body' => (string)$row['body'],
            'description' => (string)$row['description'],
            'fingerprint' => (string)$row['fingerprint'],
        ];
    }

    /** 列表页批量取译文：返回 [topic_id => ['title' => ..., 'description' => ...]]，没译文的 id 不在结果里 */
    public static function topic_titles(string $lang, array $topic_ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $topic_ids))));
        if (!$ids || !self::enabled()) return [];
        self::ensure_schema();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT target_id, title, description FROM plugin_i18n_content
            WHERE lang=? AND target_type='topic' AND status='done' AND target_id IN ($placeholders)");
        $st->execute(array_merge([$lang], $ids));
        $map = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int)$row['target_id']] = ['title' => (string)$row['title'], 'description' => (string)$row['description']];
        }
        return $map;
    }

    /**
     * 处理一批：每个启用语言先翻版块（数量少、全局可见），再按 id 降序翻待翻主题。
     * 返回 ['done' => 成功篇数, 'failed' => 失败篇数, 'skipped' => 1 表示其他进程持租约]。
     * 全程只用 db() 这一个连接：SQLite 下多连接混写会在持有写锁时互相锁死。
     */
    public static function process_batch(int $batch = 0): array
    {
        if (!self::enabled()) return ['done' => 0, 'failed' => 0, 'skipped' => 1];
        self::ensure_schema();
        $batch = $batch > 0 ? $batch : max(1, (int)setting('i18n_batch_size', '3'));
        $db = db();
        // 乐观租约：条件 UPDATE 原子抢占，抢不到说明其他进程正在处理
        $db->exec("INSERT OR IGNORE INTO app_settings(name, value) VALUES('i18n_lease_until', '0')");
        $expected = (string)(int)$db->query("SELECT value FROM app_settings WHERE name='i18n_lease_until'")->fetchColumn();
        if ((int)$expected > now()) return ['done' => 0, 'failed' => 0, 'skipped' => 1];
        $st = $db->prepare("UPDATE app_settings SET value=? WHERE name='i18n_lease_until' AND value=?");
        $st->execute([(string)(now() + 300), $expected]);
        if ($st->rowCount() !== 1) return ['done' => 0, 'failed' => 0, 'skipped' => 1];
        $done = 0;
        $failed = 0;
        try {
            foreach (enabled_langs() as $lang) {
                foreach (self::pending_forums($lang) as $forum) {
                    $result = self::translate_pair($lang, 'forum', (int)$forum['id'], (string)$forum['name'], (string)$forum['description']);
                    $result === false ? $failed++ : $done++;
                }
                foreach (self::pending_topic_ids($lang, $batch) as $topic_id) {
                    // 批内已持租约，直接走不带租约的内部方法，避免自己挡自己
                    self::translate_topic_row($lang, $db->query('SELECT * FROM app_topics WHERE id=' . (int)$topic_id)->fetch(PDO::FETCH_ASSOC) ?: null) ? $done++ : $failed++;
                }
                // 话题词（keyword + 定义摘要）队列：英文化话题聚合页，吃海外长尾
                foreach (self::pending_tag_ids($lang, $batch) as $tag_row) {
                    $result = self::translate_pair($lang, 'tag', (int)$tag_row['id'], (string)$tag_row['keyword'], (string)$tag_row['summary']);
                    $result === false ? $failed++ : $done++;
                }
            }
            return ['done' => $done, 'failed' => $failed, 'skipped' => 0];
        } finally {
            self::save_lease(0);
        }
    }

    /** 单篇即时翻译：详情页英文访客首访无译文时响应后调用；持不上租约就交给队列 tick */
    public static function translate_topic(string $lang, int $topic_id): bool
    {
        $db = db();
        $db->exec("INSERT OR IGNORE INTO app_settings(name, value) VALUES('i18n_lease_until', '0')");
        $expected = (string)(int)$db->query("SELECT value FROM app_settings WHERE name='i18n_lease_until'")->fetchColumn();
        if ((int)$expected > now()) return false;
        $st = $db->prepare("UPDATE app_settings SET value=? WHERE name='i18n_lease_until' AND value=?");
        $st->execute([(string)(now() + 120), $expected]);
        if ($st->rowCount() !== 1) return false;
        try {
            return self::translate_topic_row($lang, $db->query('SELECT * FROM app_topics WHERE id=' . (int)$topic_id)->fetch(PDO::FETCH_ASSOC) ?: null);
        } finally {
            self::save_lease(0);
        }
    }

    /** @return array<int,array{id:int,name:string,description:string}> 尚无有效译文的版块 */
    private static function pending_forums(string $lang): array
    {
        $st = db()->prepare("SELECT f.id, f.name, f.description FROM app_forums f
            LEFT JOIN plugin_i18n_content c ON c.lang=? AND c.target_type='forum' AND c.target_id=f.id
            WHERE c.id IS NULL OR (c.status='failed' AND c.attempts<" . self::ATTEMPT_LIMIT . ")
            ORDER BY f.id LIMIT 50");
        $st->execute([$lang]);
        return array_map(static fn(array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'description' => (string)$r['description']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** 待翻主题：无记录或失败未超限，或内容更新晚于译文（编辑过的帖子），按 id 降序（新帖优先） */
    private static function pending_topic_ids(string $lang, int $limit): array
    {
        $st = db()->prepare("SELECT t.id FROM app_topics t
            LEFT JOIN plugin_i18n_content c ON c.lang=? AND c.target_type='topic' AND c.target_id=t.id
            WHERE c.id IS NULL OR (c.status='failed' AND c.attempts<?) OR t.content_updated_at > COALESCE(c.updated_at, 0)
            ORDER BY (t.content_updated_at > COALESCE(c.updated_at, 0)) DESC, t.id DESC LIMIT " . max(1, $limit));
        $st->execute([$lang, self::ATTEMPT_LIMIT]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** 待翻话题词：启用中且无有效译文的（表未播种时安静跳过） */
    private static function pending_tag_ids(string $lang, int $limit): array
    {
        $db = db();
        if (!(int)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='plugin_topic_tags'")->fetchColumn()) return [];
        $st = $db->prepare("SELECT g.id, g.keyword, g.summary FROM plugin_topic_tags g
            LEFT JOIN plugin_i18n_content c ON c.lang=? AND c.target_type='tag' AND c.target_id=g.id
            WHERE g.status='active' AND (c.id IS NULL OR (c.status='failed' AND c.attempts<?))
            ORDER BY g.position, g.id LIMIT " . max(1, $limit));
        $st->execute([$lang, self::ATTEMPT_LIMIT]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function translate_topic_row(string $lang, ?array $t): bool
    {
        if (!$t) return false;
        $forum = db()->query('SELECT name, paid_mode FROM app_forums WHERE id=' . (int)$t['forum_id'])->fetch(PDO::FETCH_ASSOC) ?: ['name' => '', 'paid_mode' => 0];
        return self::translate_pair($lang, 'topic', (int)$t['id'], (string)$t['title'], (string)$t['body'], (string)$forum['name'], (int)$forum['paid_mode'] === 1) !== false;
    }

    /**
     * 翻译并落库一条内容；失败记 attempts。返回 false 表示本次失败（含无密钥）。
     * 收费版块的帖子先脱敏再喂模型（联系方式不出站），译文天然不带联系方式。
     */
    private static function translate_pair(string $lang, string $type, int $id, string $title, string $body, string $forum_name = '', bool $paid = false): array|bool
    {
        $db = db();
        $started = (int)(microtime(true) * 1000);
        try {
            if ($paid) {
                $title = mask_contacts($title);
                $body = mask_contacts($body);
            }
            $result = self::call_ai($lang, $title, $body, $forum_name);
            $st = $db->prepare("INSERT INTO plugin_i18n_content
                (lang, target_type, target_id, fingerprint, title, body, description, status, attempts, last_error, duration_ms, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'done', 0, '', ?, ?, ?)
                ON CONFLICT(lang, target_type, target_id) DO UPDATE SET
                    fingerprint=excluded.fingerprint, title=excluded.title, body=excluded.body, description=excluded.description,
                    status='done', attempts=0, last_error='', duration_ms=excluded.duration_ms, updated_at=excluded.updated_at");
            $ts = now();
            $st->execute([$lang, $type, $id, self::fingerprint($title, $body), $result['title'], $result['body'], $result['description'],
                (int)(microtime(true) * 1000) - $started, $ts, $ts]);
            return $result;
        } catch (Throwable $e) {
            $st = $db->prepare("INSERT INTO plugin_i18n_content
                (lang, target_type, target_id, fingerprint, title, body, description, status, attempts, last_error, duration_ms, created_at, updated_at)
                VALUES (?, ?, ?, '', '', '', '', 'failed', 1, ?, ?, ?, ?)
                ON CONFLICT(lang, target_type, target_id) DO UPDATE SET
                    status='failed', attempts=plugin_i18n_content.attempts+1, last_error=excluded.last_error,
                    duration_ms=excluded.duration_ms, updated_at=excluded.updated_at");
            $ts = now();
            $st->execute([$lang, $type, $id, mb_substr($e->getMessage(), 0, 200), (int)(microtime(true) * 1000) - $started, $ts, $ts]);
            return false;
        }
    }

    /**
     * DeepSeek 翻译：输出一行 JSON {title, body, description}。
     * 语法要素（markdown 结构、@提及、#楼层、****** 占位符、URL、数字价格）必须原样保留，
     * 否则正文渲染管线和联系方式打码都会被破坏。
     */
    private static function call_ai(string $lang, string $title, string $body, string $forum_name): array
    {
        $key = TopicTDK::api_key();
        if ($key === '') throw new \RuntimeException('未配置 DEEPSEEK_API_KEY');
        $base = rtrim(trim((string)setting('tdk_api_base', 'https://api.deepseek.com')), '/');
        $lang_names = ['en' => 'English'];
        $target = $lang_names[$lang] ?? $lang;
        $body_feed = mb_substr($body, 0, self::FEED_BODY_CHARS);
        $prompt = '你是专业的中译英翻译，把旧衣回收行业论坛的帖子翻译成地道的' . $target . '。'
            . '输入里的「【版块】」「【标题】」「【正文】」只是分段的元信息标签：不要把它们或它们的英文（[Category]/[Title] 等）包含进任何输出字段，title 只输出标题译文，body 只输出正文译文。'
            . '严格保留：markdown 语法结构（# 标题、列表、链接、图片、代码围栏）、@开头的用户名、#数字楼层引用、'
            . '连续六个星号的占位符（******，一个都不能增减改）、URL、数字与价格。'
            . 'description 是正文的' . $target . '摘要，100-160 个字符的完整陈述句。'
            . '只输出一行 JSON：{"title":"...","body":"...","description":"..."}';
        $payload = [
            'model' => trim((string)setting('tdk_model', 'deepseek-flash')),
            'messages' => [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => ($forum_name !== '' ? "【版块】{$forum_name}\n" : '') . "【标题】{$title}\n【正文】\n{$body_feed}"],
            ],
            'max_tokens' => 4000,
            'temperature' => 0.2,
            // 与 TDK 相同口径：关闭思考模式，否则思考阶段耗尽 max_tokens 导致空返回
            'thinking' => ['type' => 'disabled'],
            'response_format' => ['type' => 'json_object'],
        ];
        $ch = curl_init($base . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => max(10, (int)setting('i18n_timeout', '60')),
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        if ($code !== 200 || !is_string($resp)) throw new \RuntimeException("翻译接口 HTTP {$code} {$err}");
        $data = json_decode($resp, true);
        $choice = $data['choices'][0] ?? [];
        $content = trim((string)($choice['message']['content'] ?? ''));
        if ($content === '') {
            $finish = (string)($choice['finish_reason'] ?? 'unknown');
            throw new \RuntimeException($finish === 'length' ? '译文输出被截断（正文过长）' : "翻译接口返回空内容（finish={$finish}）");
        }
        $content = preg_replace('/^```(?:json)?|```$/m', '', $content);
        $result = json_decode(trim((string)$content), true);
        $title_out = trim((string)($result['title'] ?? ''));
        $body_out = trim((string)($result['body'] ?? ''));
        if ($title_out === '' || $body_out === '') throw new \RuntimeException('翻译结果缺少标题或正文');
        // 模型偶发把输入的元信息标签（【标题】/[Title] 等）带进输出：剥掉标签残留，标题截到首行
        $title_out = trim((string)preg_replace(['/\[(?:category|title|forum)\]\s*/iu', '/【(?:版块|标题|正文)】\s*/u'], '', $title_out));
        $title_out = trim((string)preg_split('/\r?\n/', $title_out)[0]);
        $body_out = trim((string)preg_replace('/^(?:\[(?:category|title|forum)\]|【(?:版块|标题|正文)】)[^\n]*\n+/iu', '', $body_out));
        if ($title_out === '' || $body_out === '') throw new \RuntimeException('翻译结果剥除标签残留后为空');
        // 兜底：译文出现打码占位符缺星或被改写时整体放弃，避免破坏展示管线
        if (mb_substr_count($body, '******') !== mb_substr_count($body_out, '******')) {
            throw new \RuntimeException('译文占位符数量与原文不一致，放弃本次结果');
        }
        return [
            'title' => $title_out,
            'body' => $body_out,
            'description' => mb_substr(trim((string)($result['description'] ?? '')), 0, 200),
        ];
    }

    /** 后台统计：total=现存主题数，translated/failed/pending 按语言分列（含编辑后待重翻的不计入已翻） */
    public static function stats(string $lang): array
    {
        self::ensure_schema();
        $db = db();
        $total = (int)$db->query('SELECT count(*) FROM app_topics')->fetchColumn();
        $st = $db->prepare("SELECT
                SUM(CASE WHEN status='done' THEN 1 ELSE 0 END) AS done,
                SUM(CASE WHEN status='failed' AND attempts<" . self::ATTEMPT_LIMIT . " THEN 1 ELSE 0 END) AS failed
            FROM plugin_i18n_content WHERE lang=? AND target_type='topic'");
        $st->execute([$lang]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $translated = min($total, (int)($row['done'] ?? 0));
        $failed = (int)($row['failed'] ?? 0);
        return ['total' => $total, 'translated' => $translated, 'failed' => $failed, 'pending' => max(0, $total - $translated - $failed)];
    }

    /** 队列日志：最近处理的记录（成功与失败占位同表），供后台查阅 */
    public static function recent_logs(string $lang, int $limit = 15): array
    {
        self::ensure_schema();
        $st = db()->prepare("SELECT m.target_type, m.target_id, m.title, m.status, m.attempts, m.last_error, m.duration_ms, m.updated_at,
                COALESCE(t.title, '') AS topic_title
            FROM plugin_i18n_content m LEFT JOIN app_topics t ON t.id = m.target_id AND m.target_type='topic'
            WHERE m.lang=?
            ORDER BY m.updated_at DESC LIMIT " . max(1, $limit));
        $st->execute([$lang]);
        return array_map(static function (array $r): array {
            $r['target_id'] = (int)$r['target_id'];
            $r['attempts'] = (int)$r['attempts'];
            $r['duration_ms'] = (int)$r['duration_ms'];
            $r['updated_at'] = (int)$r['updated_at'];
            return $r;
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function save_lease(int $until): void
    {
        $st = db()->prepare("UPDATE app_settings SET value=? WHERE name='i18n_lease_until'");
        $st->execute([(string)$until]);
    }
}
