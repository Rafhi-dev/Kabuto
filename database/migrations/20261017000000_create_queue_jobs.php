<?php

declare(strict_types=1);

use Kabuto\Database\Migration;
use Kabuto\Database\SchemaCompiler;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $id = SchemaCompiler::autoIncrementPkFor($driver, 'id', true);
        $pdo->exec("CREATE TABLE IF NOT EXISTS kabuto_queue_jobs ({$id}, queue VARCHAR(100) NOT NULL, payload TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, available_at INTEGER NOT NULL, reserved_at INTEGER NULL, failed_at INTEGER NULL, last_error TEXT NULL, created_at INTEGER NOT NULL)");
        if ($driver === 'mysql') {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
            $stmt->execute(['kabuto_queue_jobs', 'kabuto_queue_jobs_ready']);
            if ((int) $stmt->fetchColumn() === 0) {
                $pdo->exec('CREATE INDEX kabuto_queue_jobs_ready ON kabuto_queue_jobs (queue, failed_at, reserved_at, available_at)');
            }
        } else {
            $pdo->exec('CREATE INDEX IF NOT EXISTS kabuto_queue_jobs_ready ON kabuto_queue_jobs (queue, failed_at, reserved_at, available_at)');
        }
    }

    public function down(PDO $pdo): void { $pdo->exec('DROP TABLE IF EXISTS kabuto_queue_jobs'); }
};
