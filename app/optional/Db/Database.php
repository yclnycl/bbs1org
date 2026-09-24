<?php

declare(strict_types=1);

namespace app\optional\Db;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\SQLiteConnection;

if (!defined('APP_ROOT')) exit;

/**
 * 把 Eloquent 接到应用已有的 PDO 上。
 *
 * db() 建连接时已经设好 WAL、busy_timeout、foreign_keys 和异常模式，这里直接复用那个
 * PDO，不让 Eloquent 自己再建一个：同一个请求里只有一个连接、一份事务状态。
 */
final class Database
{
    private static ?Capsule $capsule = null;

    public static function boot(): void
    {
        if (self::$capsule !== null) return;

        $capsule = self::$capsule = new Capsule;
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => db_config()['path'],
            'prefix' => '',
            // PHP 8.4 起父类会直接发这里指定的 BEGIN 模式；8.2 上由 SqlitePdo::beginTransaction() 决定
            'transaction_mode' => 'IMMEDIATE',
        ]);

        $manager = $capsule->getDatabaseManager();
        $manager->extend('sqlite', static fn(array $config, string $name): SQLiteConnection => new SQLiteConnection(
            db(),
            (string)$config['database'],
            (string)($config['prefix'] ?? ''),
            $config,
        ));
        // 重连同样走 app_db_connect()：默认的重连实现会经由工厂新建 PDO，那样 PRAGMA 就丢了
        $manager->setReconnector(static function (Connection $connection): void {
            $connection->setPdo(app_db_connect(db_config()));
        });

        // 让 Model 走上面这个连接
        $capsule->bootEloquent();
    }

    public static function connection(): Connection
    {
        return self::$capsule->getDatabaseManager()->connection();
    }

    public static function table(string $table): Builder
    {
        return self::connection()->table($table);
    }
}
