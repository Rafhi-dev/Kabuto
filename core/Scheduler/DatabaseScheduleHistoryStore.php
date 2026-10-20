<?php

declare(strict_types=1);

namespace Kabuto\Scheduler;

use PDO;

final class DatabaseScheduleHistoryStore implements ScheduleHistoryStore
{
    public function __construct(private readonly PDO $pdo)
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS kabuto_schedule_history (id VARCHAR(64) PRIMARY KEY, task_id VARCHAR(191) NOT NULL, description VARCHAR(255) NOT NULL, scheduled_at INTEGER NOT NULL, attempt INTEGER NOT NULL, status VARCHAR(32) NOT NULL, started_at INTEGER NOT NULL, finished_at INTEGER NOT NULL, duration_ms INTEGER NOT NULL, error TEXT NULL)');
    }

    public function record(array $entry): void
    {
        $statement = $this->pdo->prepare('INSERT INTO kabuto_schedule_history (id, task_id, description, scheduled_at, attempt, status, started_at, finished_at, duration_ms, error) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([
            bin2hex(random_bytes(16)),
            substr((string) ($entry['task_id'] ?? ''), 0, 191),
            substr((string) ($entry['description'] ?? ''), 0, 255),
            (int) ($entry['scheduled_at'] ?? time()),
            (int) ($entry['attempt'] ?? 1),
            (string) ($entry['status'] ?? 'failed'),
            (int) ($entry['started_at'] ?? time()),
            (int) ($entry['finished_at'] ?? time()),
            (int) ($entry['duration_ms'] ?? 0),
            isset($entry['error']) ? substr((string) $entry['error'], 0, 4000) : null,
        ]);
    }

    public function recent(int $limit = 20): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Schedule history limit must be positive.');
        }
        $statement = $this->pdo->prepare('SELECT task_id, description, scheduled_at, attempt, status, started_at, finished_at, duration_ms, error FROM kabuto_schedule_history ORDER BY started_at DESC, id DESC LIMIT ?');
        $statement->bindValue(1, $limit, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
