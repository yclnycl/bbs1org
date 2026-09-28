<?php

declare(strict_types=1);

namespace app\optional;

use PDO;
use Throwable;

if (!defined('APP_ROOT')) exit;

/**
 * 详情页 TDK 队列生成器：按主题 id 升序逐篇为帖子生成搜索引擎用的标题、描述与关键词。
 *
 * 存储沿用早期 seo_tdk 插件遗留的 plugin_seo_tdk_meta 表（2160 条存量 AI 结果直接复用，
 * 不做 fingerprint 校验），新列 attempts/last_error 由 ensure_schema() 幂等补齐。
 * 队列不设独立表：待处理集 = 尚无 meta 记录、或上次生成失败且重试未超限的主题，
 * 按 id 升序取批即天然有序。并发用 app_settings 行上的 BEGIN IMMEDIATE 租约互斥。
 * AI 走 DeepSeek 兼容接口（密钥经环境变量注入），失败回落到规则生成，两者都按
 * 后台配置的长度规则输出。
 */
final class TopicTDK
{
    public const SCHEMA_VERSION = 2;
    private const ATTEMPT_LIMIT = 5;

    /** @var array<string,int>|null 每请求内缓存的列名清单，避免重复 PRAGMA */
    private static ?array $columns = null;

    public static function enabled(): bool
    {
        return setting('tdk_enabled', '1') === '1';
    }

    /**
     * 幂等建表与补列：只在 tdk_schema_version 落后时执行一次。
     * 表名保留 plugin_ 前缀以复用插件时代的存量数据，不代表仍有插件系统。
     */
    public static function ensure_schema(): void
    {
        if ((int)setting('tdk_schema_version', '0') >= self::SCHEMA_VERSION) return;
        $db = db();
        $db->exec("CREATE TABLE IF NOT EXISTS plugin_seo_tdk_meta(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            target_type TEXT NOT NULL DEFAULT 'topic',
            target_id INTEGER NOT NULL,
            title TEXT NOT NULL DEFAULT '',
            description TEXT NOT NULL DEFAULT '',
            keywords TEXT NOT NULL DEFAULT '',
            source TEXT NOT NULL DEFAULT 'rule',
            locked INTEGER NOT NULL DEFAULT 0,
            fingerprint TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL DEFAULT 0,
            updated_at INTEGER NOT NULL DEFAULT 0,
            attempts INTEGER NOT NULL DEFAULT 0,
            last_error TEXT NOT NULL DEFAULT '',
            duration_ms INTEGER NOT NULL DEFAULT 0
        )");
        // 旧表由插件时代的 UNIQUE 约束生成了 (target_type,target_id) 自动索引，已有就不再建
        $has_unique = false;
        foreach ($db->query('PRAGMA index_list(plugin_seo_tdk_meta)') as $ix) {
            if (!$ix['unique']) continue;
            $cols = array_column($db->query("PRAGMA index_info({$ix['name']})")->fetchAll(PDO::FETCH_ASSOC), 'name');
            if ($cols === ['target_type', 'target_id']) { $has_unique = true; break; }
        }
        if (!$has_unique) $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_seo_tdk_target ON plugin_seo_tdk_meta(target_type, target_id)');
        $missing = array_diff(['attempts', 'last_error', 'duration_ms'], self::columns());
        foreach ($missing as $col) {
            $db->exec(match ($col) {
                'attempts' => 'ALTER TABLE plugin_seo_tdk_meta ADD COLUMN attempts INTEGER NOT NULL DEFAULT 0',
                'duration_ms' => 'ALTER TABLE plugin_seo_tdk_meta ADD COLUMN duration_ms INTEGER NOT NULL DEFAULT 0',
                default => "ALTER TABLE plugin_seo_tdk_meta ADD COLUMN last_error TEXT NOT NULL DEFAULT ''",
            });
        }
        self::$columns = null;
        save_settings_values(['tdk_schema_version' => (string)self::SCHEMA_VERSION]);
    }

    /** @return array<string,int> total=主题总数 pending=待生成 failed=失败挂起（仅统计现存主题，已删主题的遗留行不计） */
    public static function stats(): array
    {
        self::ensure_schema();
        $db = db();
        $total = (int)$db->query('SELECT count(*) FROM app_topics')->fetchColumn();
        $covered = (int)$db->query("SELECT count(*) FROM plugin_seo_tdk_meta m JOIN app_topics t ON t.id=m.target_id
            WHERE m.target_type='topic' AND m.source<>'failed'")->fetchColumn();
        $failed = (int)$db->query("SELECT count(*) FROM plugin_seo_tdk_meta m JOIN app_topics t ON t.id=m.target_id
            WHERE m.target_type='topic' AND m.source='failed' AND m.attempts<" . self::ATTEMPT_LIMIT)->fetchColumn();
        return ['total' => $total, 'covered' => min($total, $covered), 'pending' => max(0, $total - $covered - $failed), 'failed' => $failed];
    }

    /** 详情页的 TDK 取值：无记录或失败占位的返回 null（走默认规则），生成结果原样返回 */
    public static function meta_for(int $topic_id): ?array
    {
        self::ensure_schema();
        $row = db()->query("SELECT title, description, keywords, source, locked FROM plugin_seo_tdk_meta
            WHERE target_type='topic' AND target_id=" . (int)$topic_id)->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['source'] === 'failed' || (trim((string)$row['title']) === '' && trim((string)$row['description']) === '')) return null;
        return $row;
    }

    public static function fingerprint(array $t): string
    {
        return hash('sha256', (string)$t['title'] . "\n" . (string)$t['body']);
    }

    /**
     * 处理一批：按 id 升序取「无记录 或 失败且未超限」的主题，逐篇生成并落库。
     * 返回 ['done' => 成功篇数, 'failed' => 失败篇数, 'skipped' => 1 表示其他进程持租约]。
     * 全程只用 db() 这一个连接：SQLite 下 Eloquent 连接与本连接混写会在持有写锁时互相锁死。
     */
    public static function process_batch(int $batch = 0): array
    {
        if (!self::enabled()) return ['done' => 0, 'failed' => 0, 'skipped' => 1];
        self::ensure_schema();
        $batch = $batch > 0 ? $batch : max(1, (int)setting('tdk_batch_size', '3'));
        $db = db();
        // 乐观租约：条件 UPDATE 原子抢占，抢不到说明其他进程正在处理
        $db->exec("INSERT OR IGNORE INTO app_settings(name, value) VALUES('tdk_lease_until', '0')");
        $expected = (string)(int)$db->query("SELECT value FROM app_settings WHERE name='tdk_lease_until'")->fetchColumn();
        if ((int)$expected > now()) return ['done' => 0, 'failed' => 0, 'skipped' => 1];
        $st = $db->prepare("UPDATE app_settings SET value=? WHERE name='tdk_lease_until' AND value=?");
        $st->execute([(string)(now() + 300), $expected]);
        if ($st->rowCount() !== 1) return ['done' => 0, 'failed' => 0, 'skipped' => 1];
        try {
            // 预取版块信息（含收费模式标记）：批内不再触碰其他连接
            $forum_map = $db->query('SELECT id, name, paid_mode FROM app_forums')->fetchAll(PDO::FETCH_ASSOC);
            $forum_names = array_column($forum_map, 'name', 'id');
            $forum_paid = array_map(static fn($v): bool => (int)$v === 1, array_column($forum_map, 'paid_mode', 'id'));
            $done = 0;
            $failed = 0;
            foreach (self::pending_ids($batch) as $id) {
                $t = $db->query('SELECT * FROM app_topics WHERE id=' . (int)$id)->fetch(PDO::FETCH_ASSOC);
                if (!$t) continue;
                $forum_id = (int)$t['forum_id'];
                $forum_name = (string)($forum_names[$forum_id] ?? '');
                $paid = (bool)($forum_paid[$forum_id] ?? false);
                $started = (int)(microtime(true) * 1000);
                try {
                    // 收费模式版块：喂给 AI 的正文先脱敏（联系方式不出站），生成结果入库前同样脱敏兜底
                    $feed = $paid ? mask_contacts((string)$t['body']) : (string)$t['body'];
                    $feed_t = $paid ? mask_contacts((string)$t['title']) : (string)$t['title'];
                    $tdk = $paid || self::ai_enabled() ? self::generate_ai(['title' => $feed_t, 'body' => $feed], $forum_name) : null;
                    $source = 'ai';
                    if ($tdk === null) {
                        $tdk = self::generate_rule(['title' => $feed_t, 'body' => $feed], $forum_name);
                        $source = 'rule';
                    }
                    if ($paid) $tdk = array_map(static fn(string $v): string => mask_contacts($v), $tdk);
                    self::upsert_meta((int)$id, $tdk, $source, self::fingerprint($t), (int)(microtime(true) * 1000) - $started);
                    $done++;
                } catch (Throwable $e) {
                    self::record_failure((int)$id, mb_substr($e->getMessage(), 0, 200), (int)(microtime(true) * 1000) - $started);
                    $failed++;
                }
            }
            return ['done' => $done, 'failed' => $failed, 'skipped' => 0];
        } finally {
            self::save_lease(0);
        }
    }

    /** 官方模型清单（api-docs.deepseek.com 的 create-chat-completion）：后台下拉选择，不开放手填 */
    public static function models(): array
    {
        return ['deepseek-flash' => 'deepseek-flash（默认，快速）', 'deepseek-v4-pro' => 'deepseek-v4-pro（旗舰）'];
    }

    /**
     * AI 生成：DeepSeek chat completions，关闭思考模式 + JSON 输出模式（官方文档口径），
     * 输出一行 JSON 的 TDK。任何网络/格式问题都抛出由 process_batch 记失败，交由下次重试。
     */
    public static function generate_ai(array $t, string $forum_name): ?array
    {
        $key = self::api_key();
        if ($key === '') return null;
        $base = rtrim(trim((string)setting('tdk_api_base', 'https://api.deepseek.com')), '/');
        $body = [
            'model' => trim((string)setting('tdk_model', 'deepseek-flash')),
            'messages' => [
                ['role' => 'system', 'content' => '你是资深中文 SEO 专家，为旧衣回收行业论坛的帖子生成搜索引擎与 AI 搜索（GEO）友好的 TDK。'
                    . 'title 不超过 ' . (int)setting('tdk_title_max', '60') . ' 个字，含核心实体与贴切的行业关键词，不带站点名；'
                    . 'description ' . (int)setting('tdk_description_min', '100') . '-' . (int)setting('tdk_description_max', '160')
                    . ' 个字，完整陈述句概括帖子事实与价值，自然融入行业词；'
                    . 'keywords 给 ' . (int)setting('tdk_keyword_max', '8') . ' 个以内的中文词组，覆盖实体、业务词与长尾词。'
                    . '只输出一行 JSON：{"title":"...","description":"...","keywords":["..."]}'],
                ['role' => 'user', 'content' => "【版块】{$forum_name}\n【标题】{$t['title']}\n【正文】\n" . mb_substr((string)$t['body'], 0, 2000)],
            ],
            'max_tokens' => 1000,
            'temperature' => 0.4,
            // 官方参数：思考模式默认开启且思考阶段不产 content，曾把 max_tokens 耗尽导致空返回；关闭后直接出 JSON
            'thinking' => ['type' => 'disabled'],
            // 官方 JSON 输出模式：须配合消息里"输出 JSON"的指示（prompt 已带）
            'response_format' => ['type' => 'json_object'],
        ];
        $ch = curl_init($base . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => (int)setting('tdk_timeout', '30'),
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        if ($code !== 200 || !is_string($resp)) throw new \RuntimeException("TDK 接口 HTTP {$code} {$err}");
        $payload = json_decode($resp, true);
        $choice = $payload['choices'][0] ?? [];
        $content = trim((string)($choice['message']['content'] ?? ''));
        if ($content === '') {
            $finish = (string)($choice['finish_reason'] ?? 'unknown');
            throw new \RuntimeException($finish === 'length' ? 'TDK 接口输出被截断（推理耗尽 max_tokens），请改用非推理模型' : "TDK 接口返回空内容（finish={$finish}）");
        }
        // 模型偶尔用 ```json 围栏包住，剥掉再解析
        $content = preg_replace('/^```(?:json)?|```$/m', '', $content);
        $tdk = json_decode(trim((string)$content), true);
        if (!is_array($tdk) || trim((string)($tdk['description'] ?? '')) === '') throw new \RuntimeException('TDK 返回缺少 description');
        return [
            'title' => mb_substr(trim((string)($tdk['title'] ?? '')), 0, (int)setting('tdk_title_max', '60')),
            'description' => mb_substr(trim((string)$tdk['description']), 0, (int)setting('tdk_description_max', '160')),
            'keywords' => implode(',', array_slice((array)($tdk['keywords'] ?? []), 0, (int)setting('tdk_keyword_max', '8'))),
        ];
    }

    /** 规则生成：AI 不可用时的兜底——标题即题、描述取正文摘要、关键词取业务词库命中的词加版块名 */
    public static function generate_rule(array $t, string $forum_name): array
    {
        $haystack = (string)$t['title'] . ' ' . (string)$t['body'];
        $lexicon = ['旧衣', '回收', '出口', '羽绒', '价格', '行情', '政策', '法规', '分拣', '再生', '纺织', '服装', '货柜', '供应商', '海关', '环保', '循环', '库存', '批发', '货源'];
        $hits = [];
        foreach ($lexicon as $word) if (mb_strpos($haystack, $word) !== false) $hits[] = $word;
        $keywords = array_values(array_unique(array_merge([$forum_name], $hits)));
        return [
            'title' => (string)$t['title'],
            'description' => mb_substr((string)$t['body'], 0, (int)setting('tdk_description_max', '160')),
            'keywords' => implode(',', array_slice($keywords, 0, (int)setting('tdk_keyword_max', '8'))),
        ];
    }

    private static function pending_ids(int $limit): array
    {
        $rows = db()->query("SELECT t.id FROM app_topics t
            LEFT JOIN plugin_seo_tdk_meta m ON m.target_type='topic' AND m.target_id=t.id
            WHERE m.id IS NULL OR (m.source='failed' AND m.attempts<" . self::ATTEMPT_LIMIT . ")
            ORDER BY t.id LIMIT " . max(1, $limit))->fetchAll(PDO::FETCH_COLUMN);
        return array_map('intval', $rows);
    }

    private static function upsert_meta(int $topic_id, array $tdk, string $source, string $fingerprint, int $duration_ms = 0): void
    {
        $db = db();
        $ts = now();
        $st = $db->prepare("INSERT INTO plugin_seo_tdk_meta
            (target_type, target_id, title, description, keywords, source, locked, fingerprint, created_at, updated_at, attempts, last_error, duration_ms)
            VALUES ('topic', ?, ?, ?, ?, ?, 0, ?, ?, ?, 0, '', ?)
            ON CONFLICT(target_type, target_id) DO UPDATE SET
                title=excluded.title, description=excluded.description, keywords=excluded.keywords, source=excluded.source,
                fingerprint=excluded.fingerprint, updated_at=excluded.updated_at, attempts=0, last_error='', duration_ms=excluded.duration_ms
            WHERE plugin_seo_tdk_meta.locked=0");
        $st->execute([$topic_id, $tdk['title'], $tdk['description'], $tdk['keywords'], $source, $fingerprint, $ts, $ts, $duration_ms]);
    }

    private static function record_failure(int $topic_id, string $message, int $duration_ms = 0): void
    {
        $db = db();
        $ts = now();
        $st = $db->prepare("INSERT INTO plugin_seo_tdk_meta
            (target_type, target_id, title, description, keywords, source, locked, fingerprint, created_at, updated_at, attempts, last_error, duration_ms)
            VALUES ('topic', ?, '', '', '', 'failed', 0, '', ?, ?, 1, ?, ?)
            ON CONFLICT(target_type, target_id) DO UPDATE SET
                source='failed', attempts=plugin_seo_tdk_meta.attempts+1, last_error=excluded.last_error, updated_at=excluded.updated_at, duration_ms=excluded.duration_ms
            WHERE plugin_seo_tdk_meta.locked=0");
        $st->execute([$topic_id, $ts, $ts, $message, $duration_ms]);
    }

    /**
     * 队列日志：最近处理的记录（成功、规则兜底与失败占位都在 meta 表里），供后台查阅。
     * failed 行没有标题，标题从主题表现取；已删主题的行也展示（标注已删除）。
     * @return array<int, array{id:int,topic_id:int,topic_title:string,deleted:bool,source:string,attempts:int,duration_ms:int,error:string,updated_at:int,title:string}>
     */
    public static function recent_logs(int $limit = 15): array
    {
        self::ensure_schema();
        $rows = db()->query("SELECT m.target_id, m.title, m.source, m.attempts, m.last_error, m.duration_ms, m.updated_at,
                COALESCE(t.title, '') AS topic_title, (t.id IS NULL) AS deleted
            FROM plugin_seo_tdk_meta m LEFT JOIN app_topics t ON t.id = m.target_id
            WHERE m.target_type='topic'
            ORDER BY m.updated_at DESC LIMIT " . max(1, $limit))->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static function (array $r): array {
            $r['id'] = (int)$r['target_id'];
            $r['topic_id'] = (int)$r['target_id'];
            $r['deleted'] = (bool)$r['deleted'];
            $r['attempts'] = (int)$r['attempts'];
            $r['duration_ms'] = (int)$r['duration_ms'];
            $r['updated_at'] = (int)$r['updated_at'];
            $r['topic_title'] = (string)$r['topic_title'];
            return $r;
        }, $rows);
    }

    private static function save_lease(int $until): void
    {
        $st = db()->prepare("UPDATE app_settings SET value=? WHERE name='tdk_lease_until'");
        $st->execute([(string)$until]);
    }

    private static function ai_enabled(): bool
    {
        return setting('tdk_ai_enabled', '1') === '1' && self::api_key() !== '';
    }

    public static function api_key(): string
    {
        $key = getenv('DEEPSEEK_API_KEY');
        return is_string($key) ? trim($key) : '';
    }

    /**
     * 查询账户余额（官方 GET /user/balance），结果缓存 5 分钟避免后台刷新反复打接口。
     * 返回 ['is_available' => bool, 'infos' => [['currency','total_balance','granted_balance','topped_up_balance']]]；未配密钥或查询失败返回 null。
     */
    public static function balance(): ?array
    {
        $key = self::api_key();
        if ($key === '') return null;
        $cached = setting('tdk_balance_json', '');
        if ($cached !== '' && now() - (int)setting('tdk_balance_at', '0') < 300) {
            $data = json_decode($cached, true);
            return is_array($data) ? $data : null;
        }
        $base = rtrim(trim((string)setting('tdk_api_base', 'https://api.deepseek.com')), '/');
        $ch = curl_init($base . '/user/balance');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key],
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $payload = is_string($resp) ? json_decode($resp, true) : null;
        if ($code !== 200 || !is_array($payload) || !isset($payload['balance_infos'])) return null;
        $data = ['is_available' => (bool)($payload['is_available'] ?? false), 'infos' => array_values((array)$payload['balance_infos'])];
        save_settings_values(['tdk_balance_json' => json_encode($data, JSON_UNESCAPED_UNICODE), 'tdk_balance_at' => (string)now()]);
        return $data;
    }

    /** @return string[] 当前表的列名（每请求缓存） */
    private static function columns(): array
    {
        if (self::$columns !== null) return self::$columns;
        return self::$columns = array_column(db()->query('PRAGMA table_info(plugin_seo_tdk_meta)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    }
}
