<?php

declare(strict_types=1);

namespace app\optional;

use Throwable;

if (!defined('APP_ROOT')) exit;

/**
 * Debug 模式下的错误日志写入。
 * 同一指纹在 DEBUG_LOG_DEDUP_SECONDS 内只记一次，避免单个错误刷满日志文件。
 */
final class DebugLog
{
    public static function write(string $message, ?Throwable $e = null): void
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
}
