<?php

declare(strict_types=1);

namespace app\optional\Db;

use PDO;

if (!defined('APP_ROOT')) exit;

/**
 * 让 PDO 的事务记账和裸 SQL 对得上，并且让事务一开始就拿到写锁。
 *
 * 有两个坑要一起解决：
 *
 * 1. SQLite 默认的 BEGIN 是 deferred。事务里先读后写要等锁升级，而锁升级失败是
 *    立即返回 SQLITE_BUSY，busy_timeout 不会介入（那是死锁，不是等待）。所以写事务
 *    必须用 BEGIN IMMEDIATE 一开始就占住写锁。PDO::beginTransaction() 写死了发
 *    "BEGIN"，没有地方能改，只能在这里换成 IMMEDIATE。
 *
 * 2. PDO 只在走 PDO::beginTransaction() 时才认为事务开着。一旦自己 exec('BEGIN')，
 *    PDO::inTransaction() 就一直是 false，于是 PDO::commit() 抛 "There is no active
 *    transaction"、PDO::rollBack() 静默什么都不做——事务不会提交也不会回滚。这里自己
 *    跟踪事务状态，并把 commit()/rollBack() 改成直接发 SQL，两者就不再打架。
 */
final class SqlitePdo extends PDO
{
    private bool $inTransaction = false;

    public function exec(string $statement): int|false
    {
        $result = parent::exec($statement);
        if ($result !== false) $this->track($statement);
        return $result;
    }

    public function beginTransaction(): bool
    {
        if ($this->inTransaction) {
            throw new \PDOException('There is already an active transaction');
        }
        $this->inTransaction = parent::exec('BEGIN IMMEDIATE TRANSACTION') !== false;
        return $this->inTransaction;
    }

    public function commit(): bool
    {
        if (!$this->inTransaction) return false;
        $this->inTransaction = false;
        return parent::exec('COMMIT') !== false;
    }

    public function rollBack(): bool
    {
        if (!$this->inTransaction) return false;
        $this->inTransaction = false;
        return parent::exec('ROLLBACK') !== false;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    private function track(string $statement): void
    {
        $sql = strtoupper(ltrim($statement));
        if (str_starts_with($sql, 'BEGIN')) {
            $this->inTransaction = true;
        } elseif (str_starts_with($sql, 'COMMIT') || str_starts_with($sql, 'END')) {
            $this->inTransaction = false;
        } elseif (str_starts_with($sql, 'ROLLBACK')) {
            // ROLLBACK TO SAVEPOINT 只退到保存点，事务还开着
            $this->inTransaction = str_starts_with(substr($sql, 8), ' TO');
        }
    }
}
