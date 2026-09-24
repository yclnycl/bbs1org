<?php
/**
 * 打印当前 PHP 环境的关键特征，供 docker/env-check.sh 在本地与线上之间逐项比对。
 *
 * 用法：docker compose exec -T php php /var/www/html/docker/env-snapshot.php
 * 只读，不写任何文件；输出按行 key=value，便于 diff。
 */

declare(strict_types=1);

// 线上没有这个脚本时会把它塞到 /tmp 执行，那时用 APP_ROOT 告诉它应用根在哪
define('APP_ROOT', getenv('APP_ROOT') ?: dirname(__DIR__));

echo 'php_version=' . PHP_VERSION . "\n";
echo 'php_major_minor=' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . "\n";

$ini_keys = [
    'memory_limit',
    'max_execution_time',
    'max_input_vars',
    'upload_max_filesize',
    'post_max_size',
    'max_file_uploads',
    'date.timezone',
    'display_errors',
    'error_reporting',
    'log_errors',
    'realpath_cache_size',
    'realpath_cache_ttl',
    'opcache.enable',
    'opcache.validate_timestamps',
    'opcache.memory_consumption',
    'opcache.save_comments',
    'opcache.jit',
    'session.cookie_secure',
    'expose_php',
];
foreach ($ini_keys as $key) {
    printf("ini:%s=%s\n", $key, (string)ini_get($key));
}

// 应用真正依赖的扩展：缺一个就直接不可用，单独列出来便于一眼定位
$required = ['pdo_sqlite', 'sqlite3', 'mbstring', 'json', 'tokenizer', 'ctype', 'fileinfo', 'session', 'dom', 'xml', 'zlib', 'openssl'];
foreach ($required as $ext) {
    printf("ext:%s=%s\n", $ext, extension_loaded($ext) ? 'yes' : 'MISSING');
}

$extensions = get_loaded_extensions();
sort($extensions);
echo 'extensions=' . implode(';', $extensions) . "\n";

$lock = APP_ROOT . '/composer.lock';
echo 'composer_lock=' . (is_file($lock) ? hash_file('sha256', $lock) : 'missing') . "\n";

$version_file = APP_ROOT . '/app/version.php';
if (is_file($version_file)) {
    $version = include $version_file;
}
echo 'app_version=' . (defined('APP_VERSION') ? APP_VERSION : 'unknown') . "\n";
echo 'vendor=' . (is_file(APP_ROOT . '/vendor/autoload.php') ? 'present' : 'MISSING') . "\n";

// 数据目录可写是应用启动的前提（SQLite 库、Twig 缓存、初始化标记都在里面）
$data_dir = APP_ROOT . '/app/data';
echo 'data_dir_writable=' . (is_dir($data_dir) && is_writable($data_dir) ? 'yes' : 'no') . "\n";
echo 'uid=' . (function_exists('posix_getuid') ? posix_getuid() : 'n/a') . "\n";
