<?php
declare(strict_types=1);

namespace app\optional;

use PDO;

/**
 * 百度主动推送的推送日志与每日配额跟踪。
 *
 * 日志表记录每次推送尝试（成功与失败都记），后台「话题词」tab 可查；
 * 每日已推条数按 ok=1 的 success 汇总，配额窗口按北京日重置（百度配额按天给）。
 */
final class BaiduPush
{
    private const SCHEMA_VERSION = 1;
    /** 新站实测的日配额上限 */
    public const DAILY_QUOTA = 10;
    /** 日志保留条数（老记录滚动清理） */
    private const LOG_KEEP = 500;

    /** 建表（幂等，门闩只跑一次） */
    public static function ensure_schema(): void
    {
        if ((int)setting('baidu_push_schema_version', '0') >= self::SCHEMA_VERSION) return;
        db()->exec('CREATE TABLE IF NOT EXISTS plugin_baidu_push_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            urls TEXT NOT NULL DEFAULT \'\',
            submitted INTEGER NOT NULL DEFAULT 0,
            success INTEGER NOT NULL DEFAULT 0,
            ok INTEGER NOT NULL DEFAULT 0,
            message TEXT NOT NULL DEFAULT \'\',
            created_at INTEGER NOT NULL
        )');
        save_settings_values(['baidu_push_schema_version' => (string)self::SCHEMA_VERSION]);
    }

    /** 记一次推送尝试；ok=1 时 success 是百度实际接收的条数 */
    public static function log(array $urls, int $success, bool $ok, string $message): void
    {
        self::ensure_schema();
        $st = db()->prepare('INSERT INTO plugin_baidu_push_log (urls, submitted, success, ok, message, created_at)
            VALUES (?, ?, ?, ?, ?, ?)');
        $st->execute([implode("\n", $urls), count($urls), $success, $ok ? 1 : 0, mb_substr($message, 0, 200), now()]);
        // 滚动清理：只留最近 LOG_KEEP 条
        db()->exec('DELETE FROM plugin_baidu_push_log WHERE id NOT IN
            (SELECT id FROM plugin_baidu_push_log ORDER BY id DESC LIMIT ' . self::LOG_KEEP . ')');
    }

    /** 今天（北京日）已成功推送的条数 */
    public static function pushed_today(): int
    {
        self::ensure_schema();
        // 北京当日 0 点对应的 UTC 秒：北京时间 = UTC+8，配额按北京日重置
        $beijing_now = now() + 8 * 3600;
        $day_start = intdiv($beijing_now, 86400) * 86400 - 8 * 3600;
        $st = db()->prepare('SELECT COALESCE(SUM(success), 0) FROM plugin_baidu_push_log
            WHERE ok = 1 AND created_at >= ?');
        $st->execute([$day_start]);
        return (int)$st->fetchColumn();
    }

    /** 后台日志表：最近 N 次尝试 */
    public static function recent(int $limit = 15): array
    {
        self::ensure_schema();
        return db()->query('SELECT submitted, success, ok, message, created_at FROM plugin_baidu_push_log
            ORDER BY id DESC LIMIT ' . max(1, $limit))->fetchAll(PDO::FETCH_ASSOC);
    }

    /** 后台统计：累计推送次数/成功条数/失败次数 */
    public static function totals(): array
    {
        self::ensure_schema();
        $row = db()->query('SELECT COUNT(*) attempts, COALESCE(SUM(success), 0) success,
                COALESCE(SUM(CASE WHEN ok = 0 THEN 1 ELSE 0 END), 0) failed
            FROM plugin_baidu_push_log')->fetch(PDO::FETCH_ASSOC);
        return ['attempts' => (int)$row['attempts'], 'success' => (int)$row['success'], 'failed' => (int)$row['failed']];
    }
}
