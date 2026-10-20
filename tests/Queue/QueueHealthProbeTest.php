<?php

declare(strict_types=1);

namespace Kabuto\Tests\Queue;

use Kabuto\Database\ConnectionManager;
use Kabuto\Database\Schema;
use Kabuto\Queue\QueueManager;
use Kabuto\Tests\TestCase;

final class QueueHealthProbeTest extends TestCase
{
    public function testDatabaseQueueProbeDoesNotProvisionMissingTable(): void
    {
        $schema = Schema::define(['datasources' => ['default' => ['driver' => 'sqlite', 'path' => ':memory:']]]);
        $connections = new ConnectionManager($schema);
        $manager = new QueueManager($connections);

        $result = $manager->probe('default');
        $table = $connections->connection()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'kabuto_queue_jobs'")->fetchColumn();

        self::assertFalse($result['ok']);
        self::assertStringContains('kabuto_queue_jobs', $result['error'] ?? '');
        self::assertFalse(is_string($table));
    }

    public function testDatabaseQueueProbeReadsCountsFromExistingTable(): void
    {
        $schema = Schema::define(['datasources' => ['default' => ['driver' => 'sqlite', 'path' => ':memory:']]]);
        $connections = new ConnectionManager($schema);
        $pdo = $connections->connection();
        $pdo->exec('CREATE TABLE kabuto_queue_jobs (id INTEGER PRIMARY KEY, queue VARCHAR(100) NOT NULL, attempts INTEGER NOT NULL, available_at INTEGER NOT NULL, reserved_at INTEGER NULL, failed_at INTEGER NULL)');
        $pdo->exec("INSERT INTO kabuto_queue_jobs (queue, attempts, available_at) VALUES ('default', 0, " . time() . ")");
        $pdo->exec("INSERT INTO kabuto_queue_jobs (queue, attempts, available_at, failed_at) VALUES ('default', 3, " . time() . ', ' . time() . ')');

        $result = (new QueueManager($connections))->probe('default');

        self::assertTrue($result['ok']);
        self::assertSame(1, $result['depth']);
        self::assertSame(1, $result['failed']);
    }
}
