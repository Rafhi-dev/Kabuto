<?php

declare(strict_types=1);

namespace Kabuto\Queue;

use PDO;
use RuntimeException;
use Throwable;

final class DatabaseQueue
{
    public function __construct(private readonly PDO $pdo, private readonly string $queue = 'default')
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->ensureTable();
    }

    public function dispatch(Job $job, int $delay = 0): int|string
    {
        $payload = serialize($job);
        $stmt = $this->pdo->prepare('INSERT INTO kabuto_queue_jobs (queue, payload, attempts, available_at, reserved_at, failed_at, last_error, created_at) VALUES (?, ?, 0, ?, NULL, NULL, NULL, ?)');
        $now = time();
        $stmt->execute([$this->queue, $payload, $now + max(0, $delay), $now]);
        try {
            return $this->pdo->lastInsertId();
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return array<string,mixed>|null */
    public function reserve(int $timeout = 60): ?array
    {
        $now = time();
        $this->releaseExpired($now - max(1, $timeout));
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM kabuto_queue_jobs WHERE queue = ? AND failed_at IS NULL AND reserved_at IS NULL AND available_at <= ? ORDER BY id ASC LIMIT 1');
            $stmt->execute([$this->queue, $now]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($job)) {
                $this->pdo->commit();
                return null;
            }
            $update = $this->pdo->prepare('UPDATE kabuto_queue_jobs SET reserved_at = ?, attempts = attempts + 1 WHERE id = ? AND reserved_at IS NULL');
            $update->execute([$now, $job['id']]);
            if ($update->rowCount() !== 1) {
                $this->pdo->rollBack();
                return null;
            }
            $this->pdo->commit();
            $job['attempts'] = (int) $job['attempts'] + 1;
            return $job;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function workOnce(int $maxAttempts = 3, int $retryDelay = 5, int $timeout = 60): bool
    {
        $row = $this->reserve($timeout);
        if ($row === null) return false;
        $job = null;
        try {
            $job = unserialize((string) $row['payload'], ['allowed_classes' => true]);
            if (!$job instanceof Job) throw new RuntimeException('Queue payload does not contain a valid Job.');
            $job->handle();
            $this->delete((int) $row['id']);
        } catch (Throwable $e) {
            $attempts = (int) $row['attempts'];
            $effectiveMaxAttempts = $job instanceof QueueableJob ? max(1, $job->maxAttempts()) : $maxAttempts;
            $effectiveRetryDelay = $job instanceof QueueableJob ? max(0, $job->retryDelay()) : max(0, $retryDelay);
            if ($attempts >= $effectiveMaxAttempts) {
                if ($job instanceof QueueableJob) {
                    try { $job->failed($e); } catch (Throwable) { }
                }
                $this->fail((int) $row['id'], $e->getMessage());
            } else {
                $this->reschedule((int) $row['id'], time() + $effectiveRetryDelay, $e->getMessage());
            }
        }
        return true;
    }

    /** @return list<array<string,mixed>> */
    public function failed(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM kabuto_queue_jobs WHERE queue = ? AND failed_at IS NOT NULL ORDER BY id DESC');
        $stmt->execute([$this->queue]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{depth:int,failed:int} */
    public function metrics(): array
    {
        $now = time();
        $pending = $this->pdo->prepare('SELECT COUNT(*) FROM kabuto_queue_jobs WHERE queue = ? AND failed_at IS NULL AND reserved_at IS NULL AND available_at <= ?');
        $pending->execute([$this->queue, $now]);
        $failed = $this->pdo->prepare('SELECT COUNT(*) FROM kabuto_queue_jobs WHERE queue = ? AND failed_at IS NOT NULL');
        $failed->execute([$this->queue]);
        return ['depth' => (int) $pending->fetchColumn(), 'failed' => (int) $failed->fetchColumn()];
    }

    public function health(): bool
    {
        try { return (int) $this->pdo->query('SELECT 1')->fetchColumn() === 1; } catch (Throwable) { return false; }
    }

    public function retry(int $id, ?int $availableAt = null, ?string $error = null): void
    {
        $stmt = $this->pdo->prepare('UPDATE kabuto_queue_jobs SET reserved_at = NULL, failed_at = NULL, attempts = 0, available_at = ?, last_error = ? WHERE id = ? AND queue = ?');
        $stmt->execute([$availableAt ?? time(), $error, $id, $this->queue]);
    }

    private function reschedule(int $id, int $availableAt, string $error): void
    {
        $stmt = $this->pdo->prepare('UPDATE kabuto_queue_jobs SET reserved_at = NULL, available_at = ?, last_error = ? WHERE id = ? AND queue = ?');
        $stmt->execute([$availableAt, $error, $id, $this->queue]);
    }

    public function forget(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM kabuto_queue_jobs WHERE id = ? AND queue = ?');
        $stmt->execute([$id, $this->queue]);
    }

    private function delete(int $id): void { $this->forget($id); }

    private function fail(int $id, string $error): void
    {
        $stmt = $this->pdo->prepare('UPDATE kabuto_queue_jobs SET reserved_at = NULL, failed_at = ?, last_error = ? WHERE id = ?');
        $stmt->execute([time(), $error, $id]);
    }

    private function releaseExpired(int $before): void
    {
        $stmt = $this->pdo->prepare('UPDATE kabuto_queue_jobs SET reserved_at = NULL WHERE queue = ? AND reserved_at IS NOT NULL AND reserved_at < ? AND failed_at IS NULL');
        $stmt->execute([$this->queue, $before]);
    }

    private function ensureTable(): void
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $id = \Kabuto\Database\SchemaCompiler::autoIncrementPkFor($driver, 'id', true);
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS kabuto_queue_jobs ({$id}, queue VARCHAR(100) NOT NULL, payload TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, available_at INTEGER NOT NULL, reserved_at INTEGER NULL, failed_at INTEGER NULL, last_error TEXT NULL, created_at INTEGER NOT NULL)");
        $this->ensureQueueIndex($driver);
    }

    /**
     * MySQL has no `CREATE INDEX IF NOT EXISTS`; check the catalog first.
     */
    private function ensureQueueIndex(string $driver): void
    {
        if ($driver === 'mysql') {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
            $stmt->execute(['kabuto_queue_jobs', 'kabuto_queue_jobs_ready']);
            if ((int) $stmt->fetchColumn() > 0) {
                return;
            }
            $this->pdo->exec('CREATE INDEX kabuto_queue_jobs_ready ON kabuto_queue_jobs (queue, failed_at, reserved_at, available_at)');
            return;
        }

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS kabuto_queue_jobs_ready ON kabuto_queue_jobs (queue, failed_at, reserved_at, available_at)');
    }
}
