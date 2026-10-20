<?php

declare(strict_types=1);

namespace Kabuto\Scheduler;

use PDO;
use PDOException;

final class DatabaseLockStore implements LockStore
{
    public function __construct(private readonly PDO $pdo)
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS kabuto_schedule_locks (lock_key VARCHAR(191) PRIMARY KEY, owner VARCHAR(64) NOT NULL, expires_at INTEGER NOT NULL)");
    }

    public function acquire(string $key, string $owner, int $ttlSeconds): bool
    {
        if (strlen($key) > 191) {
            throw new \InvalidArgumentException('Scheduler lock key cannot exceed 191 bytes.');
        }
        $now = time();
        $delete = $this->pdo->prepare('DELETE FROM kabuto_schedule_locks WHERE lock_key = ? AND expires_at <= ?');
        $delete->execute([$key, $now]);

        try {
            $insert = $this->pdo->prepare('INSERT INTO kabuto_schedule_locks (lock_key, owner, expires_at) VALUES (?, ?, ?)');
            $insert->execute([$key, $owner, $now + max(1, $ttlSeconds)]);
            return true;
        } catch (PDOException $exception) {
            $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());
            if (in_array($state, ['23000', '23505', '19'], true) || str_starts_with($state, '23')) {
                return false;
            }
            throw $exception;
        }
    }

    public function release(string $key, string $owner): bool
    {
        $delete = $this->pdo->prepare('DELETE FROM kabuto_schedule_locks WHERE lock_key = ? AND owner = ?');
        $delete->execute([$key, $owner]);
        return $delete->rowCount() === 1;
    }
}
