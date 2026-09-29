<?php
declare(strict_types=1);

namespace app\optional;

use PDO;
use Throwable;

/**
 * 站内搜索词回流：记录访客真实输入的查询词并按词聚合，给话题词库提供「用户在搜什么」的一手信号。
 *
 * 与 GSC 查询词互补：GSC 反映搜索引擎上的需求，这里反映已到站用户的需求；
 * 两条候选词来源都在后台「话题词」tab 汇总，一键导入词库后由 TDK/翻译队列补全落地页。
 */
final class SearchLog
{
    private const SCHEMA_VERSION = 1;
    /** 入库词长边界：太短的词噪声大，太长的多是长句查询，对建话题词没价值 */
    private const TERM_MIN_CHARS = 2;
    private const TERM_MAX_CHARS = 30;

    /** 建表（幂等，门闩只跑一次） */
    public static function ensure_schema(): void
    {
        if ((int)setting('search_log_schema_version', '0') >= self::SCHEMA_VERSION) return;
        db()->exec('CREATE TABLE IF NOT EXISTS plugin_search_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            term TEXT NOT NULL UNIQUE,
            hits INTEGER NOT NULL DEFAULT 1,
            last_at INTEGER NOT NULL,
            created_at INTEGER NOT NULL
        )');
        save_settings_values(['search_log_schema_version' => (string)self::SCHEMA_VERSION]);
    }

    /** 记一次搜索：同词聚合 hits+1；记录失败不影响搜索主流程 */
    public static function record(string $term): void
    {
        $term = trim($term);
        $len = mb_strlen($term);
        if ($len < self::TERM_MIN_CHARS || $len > self::TERM_MAX_CHARS) return;
        try {
            self::ensure_schema();
            $st = db()->prepare('INSERT INTO plugin_search_log (term, hits, last_at, created_at) VALUES (?, 1, ?, ?)
                ON CONFLICT(term) DO UPDATE SET hits = hits + 1, last_at = excluded.last_at');
            $st->execute([$term, now(), now()]);
        } catch (Throwable) {
        }
    }

    /** 热词榜：先按次数再按新鲜度排 */
    public static function top(int $limit = 20): array
    {
        try {
            self::ensure_schema();
            $st = db()->prepare('SELECT term, hits, last_at FROM plugin_search_log ORDER BY hits DESC, last_at DESC LIMIT ' . max(1, $limit));
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return array_map(static fn(array $r): array => [
                'term' => (string)$r['term'],
                'hits' => (int)$r['hits'],
                'last_at' => (int)$r['last_at'],
            ], $rows);
        } catch (Throwable) {
            return [];
        }
    }

    public static function total_terms(): int
    {
        try {
            self::ensure_schema();
            return (int)db()->query('SELECT COUNT(*) FROM plugin_search_log')->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    public static function clear(): void
    {
        self::ensure_schema();
        db()->exec('DELETE FROM plugin_search_log');
    }
}
