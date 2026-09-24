<?php
/**
 * 在容器里给 SQLite 做一次一致性快照，供发布前备份使用。
 *
 * VACUUM INTO 是 SQLite 官方的在线备份方式：会等写锁、输出一个整理过的完整副本，
 * 不像 cp 那样可能拷到写了一半的文件；配合 WAL 使用也安全。
 *
 * 用法：php docker/db-backup.php [输出文件]
 * 输出的路径也会打印出来，方便调用方再拷到宿主机。
 */

declare(strict_types=1);

define('APP_ROOT', getenv('APP_ROOT') ?: dirname(__DIR__));

$config_file = APP_ROOT . '/app/data/db.php';
$config = is_file($config_file) ? include $config_file : [];
$name = basename((string)($config['database'] ?? $config['db_file'] ?? 'forum.sqlite'));
$source = APP_ROOT . '/app/data/' . $name;

if (!is_file($source)) {
    fwrite(STDERR, "找不到数据库文件：$source\n");
    exit(1);
}

$target = $argv[1] ?? (APP_ROOT . '/app/data/backup-' . date('Ymd-His') . '.sqlite');

$pdo = new PDO('sqlite:' . $source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('VACUUM INTO ' . $pdo->quote($target));
unset($pdo);

printf("%s\t%d\n", $target, (int)filesize($target));
