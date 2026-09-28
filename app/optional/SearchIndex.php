<?php

declare(strict_types=1);

namespace app\optional;

use app\optional\Db\Database;
use app\optional\Model\Reply;
use app\optional\Model\Topic;
use PDO;

if (!defined('APP_ROOT')) exit;

/**
 * 站内聚合搜索：SQLite FTS5 全文索引，一次关键字同时命中标题/正文/回帖。
 *
 * - 索引表 plugin_fts_topics / plugin_fts_replies 存的是「分词后」文本：unicode61 分词器
 *   不切汉字，整句汉字会成为一个长 token，所以写入前经 fts_index_text()（app_db_connect
 *   注册的 UDF）在每个 CJK 字符后插一个空格；查询侧同样按字组短语，中文按字连续命中。
 * - 同步靠 app_topics / app_replies 上的触发器，写库即更新索引；UPDATE OF 列限定保证
 *   view_count 这类高频更新不会触发重建。
 * - FTS 只负责找出 rowid（bm25 相关度排序）；摘要与标红在 PHP 侧基于原文完成，
 *   避免把分词空格带进页面。
 * - 环境不支持 FTS5 时自动回落 LIKE 聚合，展示行为一致、仅排序近似。
 * - 非默认语言下追加译文检索（plugin_i18n_content LIKE 桥接）：英文界面搜英文词也能命中。
 */
final class SearchIndex
{
    public const SCHEMA_VERSION = 1;
    /** 候选池上限：够排满前若干页，同时限制 LIKE 回落/译文桥接的扫描成本 */
    private const CANDIDATE_LIMIT = 600;
    private const REPLY_LIMIT = 900;
    /** 每个主题最多展开几条回帖命中 */
    private const REPLY_HITS_PER_TOPIC = 2;
    /** 摘要窗口（字节）：命中点前 ~15 个汉字 / 后 ~70 个汉字，截取时按 UTF-8 字符边界回退 */
    private const EXCERPT_BEFORE_BYTES = 48;
    private const EXCERPT_AFTER_BYTES = 210;
    private const EXCERPT_FALLBACK_CHARS = 120;
    /** CJK 范围：汉字 + 假名 + 谚文音节 */
    private const CJK = '\x{3040}-\x{30fa}\x{3400}-\x{4dbf}\x{4e00}-\x{9fff}\x{ac00}-\x{d7af}';

    private static ?bool $fts = null;

    /** 索引分词：CJK 逐字拆开（后插空格），西文保持原样交给 unicode61 */
    public static function index_text(string $text): string
    {
        // 非法 UTF-8 会置空返回值：退回原文（ASCII 仍可命中），不让索引丢内容
        return trim((string)(preg_replace('/([' . self::CJK . '])/u', '$1 ', $text) ?? $text));
    }

    public static function available(): bool
    {
        if (self::$fts !== null) return self::$fts;
        try {
            return self::$fts = (bool)db()->query("SELECT count(*) FROM pragma_module_list() WHERE name='fts5'")->fetchColumn();
        } catch (\Throwable) {
            return self::$fts = false;
        }
    }

    /** 幂等建表/触发器并回填存量，search_schema_version 门闩保证只跑一次 */
    public static function ensure_schema(): void
    {
        if ((int)setting('search_schema_version', '0') >= self::SCHEMA_VERSION) return;
        if (!self::available()) {
            // FTS5 不可用的环境：跳过索引，搜索走 LIKE 聚合
            save_settings_values(['search_schema_version' => (string)self::SCHEMA_VERSION]);
            return;
        }
        $db = db();
        $db->exec('CREATE VIRTUAL TABLE IF NOT EXISTS plugin_fts_topics USING fts5(title, body)');
        $db->exec('CREATE VIRTUAL TABLE IF NOT EXISTS plugin_fts_replies USING fts5(topic_id UNINDEXED, body)');
        // 触发器里的 fts_index_text() 由 app_db_connect 在每个连接上注册，任何写入口都自动同步
        $db->exec("CREATE TRIGGER IF NOT EXISTS fts_topics_ai AFTER INSERT ON app_topics BEGIN
            INSERT INTO plugin_fts_topics(rowid, title, body) VALUES (new.id, fts_index_text(new.title), fts_index_text(new.body));
        END");
        $db->exec("CREATE TRIGGER IF NOT EXISTS fts_topics_au AFTER UPDATE OF title, body ON app_topics BEGIN
            DELETE FROM plugin_fts_topics WHERE rowid = old.id;
            INSERT INTO plugin_fts_topics(rowid, title, body) VALUES (new.id, fts_index_text(new.title), fts_index_text(new.body));
        END");
        $db->exec("CREATE TRIGGER IF NOT EXISTS fts_topics_ad AFTER DELETE ON app_topics BEGIN
            DELETE FROM plugin_fts_topics WHERE rowid = old.id;
        END");
        $db->exec("CREATE TRIGGER IF NOT EXISTS fts_replies_ai AFTER INSERT ON app_replies BEGIN
            INSERT INTO plugin_fts_replies(rowid, topic_id, body) VALUES (new.id, new.topic_id, fts_index_text(new.body));
        END");
        $db->exec("CREATE TRIGGER IF NOT EXISTS fts_replies_au AFTER UPDATE OF body ON app_replies BEGIN
            DELETE FROM plugin_fts_replies WHERE rowid = old.id;
            INSERT INTO plugin_fts_replies(rowid, topic_id, body) VALUES (new.id, new.topic_id, fts_index_text(new.body));
        END");
        $db->exec("CREATE TRIGGER IF NOT EXISTS fts_replies_ad AFTER DELETE ON app_replies BEGIN
            DELETE FROM plugin_fts_replies WHERE rowid = old.id;
        END");
        self::backfill();
        save_settings_values(['search_schema_version' => (string)self::SCHEMA_VERSION]);
    }

    /** 存量回填：行数对不上才整体重建，事务内执行避免并发请求重复回填 */
    private static function backfill(): void
    {
        Database::connection()->transaction(static function (): void {
            $db = db();
            $topic_total = (int)Topic::query()->count();
            if ((int)$db->query('SELECT count(*) FROM plugin_fts_topics')->fetchColumn() !== $topic_total) {
                $db->exec('DELETE FROM plugin_fts_topics');
                $st = $db->prepare('INSERT INTO plugin_fts_topics(rowid, title, body) VALUES (?, ?, ?)');
                Topic::query()->select('id', 'title', 'body')->chunkById(500, static function ($chunk) use ($st): void {
                    foreach ($chunk as $t) $st->execute([(int)$t->id, self::index_text((string)$t->title), self::index_text((string)$t->body)]);
                }, 'id');
            }
            $reply_total = (int)Reply::query()->count();
            if ((int)$db->query('SELECT count(*) FROM plugin_fts_replies')->fetchColumn() !== $reply_total) {
                $db->exec('DELETE FROM plugin_fts_replies');
                $st = $db->prepare('INSERT INTO plugin_fts_replies(rowid, topic_id, body) VALUES (?, ?, ?)');
                Reply::query()->select('id', 'topic_id', 'body')->chunkById(500, static function ($chunk) use ($st): void {
                    foreach ($chunk as $r) $st->execute([(int)$r->id, (int)$r->topic_id, self::index_text((string)$r->body)]);
                }, 'id');
            }
        });
    }

    /**
     * 关键字 → FTS5 MATCH 表达式 + 高亮词表。
     * CJK 连续段按字组短语（"旧 衣"），西文段 ≥3 字符时启用前缀匹配（"cargo"*）；
     * 输入只保留字母数字段并整体加引号，用户拼不进 FTS5 语法。
     * @return array{expr: string, terms: list<string>}|null
     */
    public static function match_plan(string $query): ?array
    {
        $query = trim($query);
        if ($query === '' || !preg_match_all('/[\p{L}\p{N}]+/u', $query, $m)) return null;
        $expr = [];
        $terms = [];
        foreach ($m[0] as $word) {
            // 混合词（如 T恤）按 CJK 段/西文段拆开，各自成短语或词项
            $runs = preg_split('/([' . self::CJK . ']+)/u', $word, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($runs as $run) {
                if (preg_match('/[' . self::CJK . ']/u', $run)) {
                    $chars = preg_split('//u', $run, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    if (!$chars) continue;
                    $expr[] = '"' . implode(' ', $chars) . '"';
                } else {
                    $expr[] = (preg_match_all('/./us', $run) >= 3 ? '"' . $run . '"*' : '"' . $run . '"');
                }
                $terms[] = $run;
            }
        }
        if (!$expr) return null;
        return ['expr' => implode(' ', $expr), 'terms' => array_values(array_unique($terms))];
    }

    /**
     * 聚合搜索入口：返回 ['rows' => [['row' => 列表行, 'hit' => ['title','body','replies']]], 'has_next' => bool]
     * 行结构与 home 列表一致（topic_list_row 可直接渲染），hit 是该主题下按相关度展开的命中上下文。
     */
    public static function page_results(string $query, int $page, int $size): array
    {
        self::ensure_schema();
        $page = max(1, $page);
        $size = max(1, $size);
        $plan = self::match_plan($query);
        $entries = [];
        if ($plan) {
            $entries = self::available() ? self::fts_entries($plan['expr']) : self::like_entries($query);
        }
        // 非默认语言：译文里再捞一遍（LIKE 桥接），补齐英文查询命中不到中文索引的部分
        $lang = current_lang();
        $translated = $lang !== DEFAULT_LANG ? self::i18n_entries($lang, $query, $entries) : [];
        foreach ($translated as $topic_id => $entry) $entries[$topic_id] ??= $entry;

        $list = array_values($entries);
        usort($list, static fn(array $a, array $b): int => $a['rank'] <=> $b['rank'] ?: $b['id'] <=> $a['id']);
        $offset = ($page - 1) * $size;
        $page_entries = array_slice($list, $offset, $size);
        $has_next = count($list) > $offset + $size;

        $rows = [];
        if ($page_entries) {
            $ids = array_column($page_entries, 'id');
            $topics = topic_rows_by_ids($ids);
            $bodies = Topic::whereIn('id', $ids)->pluck('body', 'id')->all();
            $terms = $plan['terms'] ?? [trim($query)];
            $reply_ids = [];
            foreach ($page_entries as $entry) foreach ($entry['replies'] as $rid) $reply_ids[] = (int)$rid;
            $reply_rows = $reply_ids ? Reply::whereIn('id', $reply_ids)->get(['id', 'topic_id', 'user_id', 'body', 'created_at'])->keyBy('id')->map->toArray()->all() : [];
            $authors = user_summaries(array_column($reply_rows, 'user_id'));
            foreach ($page_entries as $entry) {
                $t = $topics[(int)$entry['id']] ?? null;
                if (!$t) continue; // 命中后又被删除，索引里剩的脏行跳过即可
                $mask = !can_manage() && forum_paid_mode((int)$t['forum_id']);
                if ($mask) $t['title'] = mask_contacts((string)$t['title']);
                // 译文命中的条目：标题/摘要以译文为源，标红才落在用户看到的文字上
                $source_title = (string)$t['title'];
                $source_body = (string)($bodies[(int)$t['id']] ?? '');
                if (!empty($entry['i18n'])) {
                    $content = class_exists(Translator::class) ? Translator::content_for($lang, 'topic', (int)$t['id']) : null;
                    if ($content) {
                        $source_title = (string)$content['title'];
                        $source_body = (string)$content['body'];
                        $t['title'] = $source_title;
                    }
                }
                if ($mask) $source_title = mask_contacts($source_title);
                $hit = ['title' => '', 'body' => '', 'replies' => []];
                if (self::text_matches($source_title, $terms)) {
                    $hit['title'] = self::highlight($source_title, $terms);
                    $t['title_html'] = $hit['title']; // 列表行标题同步标红
                }
                $hit['body'] = self::excerpt_html(self::plain_text($mask ? mask_contacts($source_body) : $source_body), $terms);
                foreach ($entry['replies'] as $rid) {
                    $r = $reply_rows[(int)$rid] ?? null;
                    if (!$r) continue;
                    $reply_text = self::plain_text((string)$r['body']);
                    if ($mask) $reply_text = mask_contacts($reply_text);
                    $hit['replies'][] = [
                        'excerpt' => self::excerpt_html($reply_text, $terms),
                        // floor 参数让详情页定位到该楼层
                        'url' => route_url('topic', ['id' => (int)$t['id'], 'floor' => reply_position_floor((int)$t['id'], (int)$r['created_at'], (int)$r['id'])]),
                        'author' => (string)($authors[(int)$r['user_id']]->username ?? ''),
                        'time' => (int)$r['created_at'],
                    ];
                }
                $rows[] = ['row' => $t, 'hit' => $hit];
            }
            $list_rows = attach_topic_list_users(array_column($rows, 'row'));
            foreach ($rows as $i => $item) {
                $row = $list_rows[$i] ?? $item['row'];
                $row['time'] = (int)(($row['last_reply_at'] ?? 0) ?: ($row['created_at'] ?? 0));
                $row['forum'] = forum_by_id((int)$row['forum_id']) ?: ['id' => 0, 'name' => ''];
                $rows[$i]['row'] = $row;
            }
        }
        return ['rows' => $rows, 'has_next' => $has_next];
    }

    /** 主题 + 回帖一次 MATCH：标题命中权重 10，正文 1；回帖命中最相关的挂到所属主题下 */
    private static function fts_entries(string $expr): array
    {
        $db = db();
        $entries = [];
        $st = $db->prepare('SELECT rowid AS id, bm25(plugin_fts_topics, 10.0, 1.0) AS rank FROM plugin_fts_topics WHERE plugin_fts_topics MATCH ? LIMIT ' . self::CANDIDATE_LIMIT);
        $st->execute([$expr]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $entries[(int)$r['id']] = ['id' => (int)$r['id'], 'rank' => (float)$r['rank'], 'replies' => []];
        }
        $st = $db->prepare('SELECT rowid AS reply_id, topic_id, bm25(plugin_fts_replies, 1.0) AS rank FROM plugin_fts_replies WHERE plugin_fts_replies MATCH ? ORDER BY rank LIMIT ' . self::REPLY_LIMIT);
        $st->execute([$expr]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $topic_id = (int)$r['topic_id'];
            $rank = (float)$r['rank'];
            // 注意直接改 $entries[...]：PHP 数组是值语义，经临时变量改是改在副本上
            if (!isset($entries[$topic_id])) $entries[$topic_id] = ['id' => $topic_id, 'rank' => $rank, 'replies' => []];
            if ($rank < $entries[$topic_id]['rank']) $entries[$topic_id]['rank'] = $rank;
            if (count($entries[$topic_id]['replies']) < self::REPLY_HITS_PER_TOPIC) $entries[$topic_id]['replies'][] = (int)$r['reply_id'];
        }
        return $entries;
    }

    /** FTS5 不可用时的回落：LIKE 三路聚合，标题 1 / 正文 2 / 回帖 3，同档由 page_results 按 id 降序 */
    private static function like_entries(string $query): array
    {
        $db = db();
        $pattern = search_like_pattern($query);
        $entries = [];
        $st = $db->prepare("SELECT id FROM app_topics WHERE title LIKE ? ESCAPE '!' ORDER BY id DESC LIMIT " . self::CANDIDATE_LIMIT);
        $st->execute([$pattern]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $entries[(int)$r['id']] = ['id' => (int)$r['id'], 'rank' => 1.0, 'replies' => []];
        $st = $db->prepare("SELECT id FROM app_topics WHERE body LIKE ? ESCAPE '!' ORDER BY id DESC LIMIT " . self::CANDIDATE_LIMIT);
        $st->execute([$pattern]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $entries[(int)$r['id']] ??= ['id' => (int)$r['id'], 'rank' => 2.0, 'replies' => []];
        $st = $db->prepare("SELECT id, topic_id FROM app_replies WHERE body LIKE ? ESCAPE '!' ORDER BY id DESC LIMIT " . self::REPLY_LIMIT);
        $st->execute([$pattern]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $topic_id = (int)$r['topic_id'];
            if (!isset($entries[$topic_id])) $entries[$topic_id] = ['id' => $topic_id, 'rank' => 3.0, 'replies' => []];
            if (count($entries[$topic_id]['replies']) < self::REPLY_HITS_PER_TOPIC) $entries[$topic_id]['replies'][] = (int)$r['id'];
        }
        return $entries;
    }

    /** 非默认语言的译文桥接：在 plugin_i18n_content 里 LIKE 捞命中主题，译文体量小，直接扫表可接受 */
    private static function i18n_entries(string $lang, string $query, array $existing): array
    {
        if (!class_exists(Translator::class) || !Translator::enabled()) return [];
        $query = trim($query);
        if ($query === '') return [];
        try {
            $st = db()->prepare("SELECT target_id FROM plugin_i18n_content
                WHERE lang = ? AND target_type = 'topic' AND status = 'done' AND (title LIKE ? ESCAPE '!' OR body LIKE ? ESCAPE '!')
                ORDER BY target_id DESC LIMIT " . self::CANDIDATE_LIMIT);
            $st->execute([$lang, search_like_pattern($query), search_like_pattern($query)]);
        } catch (\Throwable) {
            return []; // 译文表尚未创建
        }
        $entries = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $topic_id = (int)$r['target_id'];
            if (isset($existing[$topic_id])) continue; // 中文索引已命中，保持原排序与原文展示
            $entries[$topic_id] = ['id' => $topic_id, 'rank' => 5.0, 'replies' => [], 'i18n' => 1];
        }
        return $entries;
    }

    /** @return string|null 可用的 PCRE 高亮模式，terms 全空时返回 null */
    private static function highlight_pattern(array $terms): ?string
    {
        $safe = [];
        foreach ($terms as $term) {
            $term = trim((string)$term);
            if ($term !== '') $safe[] = preg_quote($term, '/');
        }
        return $safe ? '/(' . implode('|', $safe) . ')/iu' : null;
    }

    private static function text_matches(string $text, array $terms): bool
    {
        $pattern = self::highlight_pattern($terms);
        return $pattern !== null && (bool)preg_match($pattern, $text);
    }

    /** 命中标红：先按命中切分再逐段转义，<mark> 只包住命中文本，杜绝注入 */
    private static function highlight(string $text, array $terms): string
    {
        $pattern = self::highlight_pattern($terms);
        if ($pattern === null) return h($text);
        $chunks = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($chunks === false) return h($text);
        $html = '';
        foreach ($chunks as $i => $chunk) {
            $html .= $i % 2 === 1 ? '<mark class="search-hit">' . h($chunk) . '</mark>' : h($chunk);
        }
        return $html;
    }

    /** 摘要：命中点前留一小段上下文，窗口按 UTF-8 字符边界截断；无命中时展示正文开头 */
    private static function excerpt_html(string $text, array $terms): string
    {
        if ($text === '') return '';
        $pattern = self::highlight_pattern($terms);
        $first = null;
        if ($pattern !== null && preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
            $first = [(int)$m[0][1], (int)$m[0][1] + strlen($m[0][0])];
        }
        if ($first === null) {
            preg_match('/^.{0,' . self::EXCERPT_FALLBACK_CHARS . '}/us', $text, $head);
            $head = $head[0] ?? '';
            return self::highlight($head, $terms) . (strlen($head) < strlen($text) ? '…' : '');
        }
        $from = self::char_floor($text, max(0, $first[0] - self::EXCERPT_BEFORE_BYTES));
        $to = self::char_ceil($text, min(strlen($text), $first[1] + self::EXCERPT_AFTER_BYTES));
        return ($from > 0 ? '…' : '') . self::highlight(substr($text, $from, $to - $from), $terms) . ($to < strlen($text) ? '…' : '');
    }

    /** 摘要源文本：去代码块标记、压平空白，并抹掉标题井号/加粗/行内代码记号（链接原样保留） */
    private static function plain_text(string $body): string
    {
        $body = content_preview_source_text($body);
        $body = preg_replace(['/^#{1,6}\s+/m', '/\*\*|__|`/'], '', $body) ?? $body;
        return trim((string)preg_replace('/\s+/u', ' ', $body));
    }

    /** 字节偏移回退到所在 UTF-8 字符的起始 */
    private static function char_floor(string $s, int $offset): int
    {
        while ($offset > 0 && (ord($s[$offset]) & 0xC0) === 0x80) $offset--;
        return $offset;
    }

    /** 字节偏移推进到下一个 UTF-8 字符的起始 */
    private static function char_ceil(string $s, int $offset): int
    {
        $len = strlen($s);
        while ($offset < $len && (ord($s[$offset]) & 0xC0) === 0x80) $offset++;
        return $offset;
    }
}
