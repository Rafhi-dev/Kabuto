<?php

declare(strict_types=1);

namespace Kabuto\Database;

use PDO;
use RuntimeException;

final class MigrationRunner
{
    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly string $directory,
    ) {}

    /** @return list<array{name:string,status:string}> */
    public function status(string $datasource): array
    {
        $pdo = $this->connections->connection($datasource);
        $this->ensureTable($pdo, $this->connections->schema()->datasource($datasource)->driver);
        $applied = $this->applied($pdo);
        $result = [];
        foreach ($this->load() as $migration) {
            $result[] = ['name' => $migration['name'], 'status' => isset($applied[$migration['name']]) ? 'applied' : 'pending'];
        }
        return $result;
    }

    /** @return list<string> */
    public function migrate(string $datasource, bool $dryRun = false): array
    {
        $pdo = $this->connections->connection($datasource);
        $driver = $this->connections->schema()->datasource($datasource)->driver;
        $this->ensureTable($pdo, $driver);
        $applied = $this->applied($pdo);
        $executed = [];
        $batch = $this->nextBatch($pdo);
        foreach ($this->load() as $migration) {
            if (isset($applied[$migration['name']])) continue;
            if (!$dryRun) {
                $pdo->beginTransaction();
                try {
                    $migration['instance']->up($pdo);
                    $stmt = $pdo->prepare('INSERT INTO kabuto_migrations (migration, batch, applied_at) VALUES (?, ?, ?)');
                    $stmt->execute([$migration['name'], $batch, date('Y-m-d H:i:s')]);
                    $pdo->commit();
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw DatabaseException::wrap($e, $driver, sprintf('migration [%s] failed', $migration['name']));
                }
            }
            $executed[] = $migration['name'];
        }
        return $executed;
    }

    /** @return list<string> */
    public function rollback(string $datasource): array
    {
        $pdo = $this->connections->connection($datasource);
        $driver = $this->connections->schema()->datasource($datasource)->driver;
        $this->ensureTable($pdo, $driver);
        $row = $pdo->query('SELECT migration, batch FROM kabuto_migrations ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return [];
        $all = $this->load();
        foreach ($all as $migration) {
            if ($migration['name'] !== $row['migration']) continue;
            $pdo->beginTransaction();
            try {
                $migration['instance']->down($pdo);
                $stmt = $pdo->prepare('DELETE FROM kabuto_migrations WHERE migration = ?');
                $stmt->execute([$migration['name']]);
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw DatabaseException::wrap($e, $driver, sprintf('rollback [%s] failed', $migration['name']));
            }
            return [$migration['name']];
        }
        throw new RuntimeException('Applied migration file [' . $row['migration'] . '] was not found.');
    }

    private function ensureTable(PDO $pdo, string $driver): void
    {
        $id = SchemaCompiler::autoIncrementPkFor($driver);
        $pdo->exec("CREATE TABLE IF NOT EXISTS kabuto_migrations ({$id}, migration VARCHAR(255) NOT NULL UNIQUE, batch INTEGER NOT NULL, applied_at VARCHAR(32) NOT NULL)");
    }

    /** @return array<string,int> */
    private function applied(PDO $pdo): array
    {
        $result = [];
        foreach ($pdo->query('SELECT migration, batch FROM kabuto_migrations')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['migration']] = (int) $row['batch'];
        }
        return $result;
    }

    private function nextBatch(PDO $pdo): int
    {
        return ((int) $pdo->query('SELECT COALESCE(MAX(batch), 0) FROM kabuto_migrations')->fetchColumn()) + 1;
    }

    /** @return list<array{name:string,instance:Migration}> */
    private function load(): array
    {
        if (!is_dir($this->directory)) return [];
        $files = glob(rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files);
        $migrations = [];
        foreach ($files as $file) {
            $migration = require $file;
            if (!$migration instanceof Migration) {
                throw new RuntimeException('Migration file must return an instance of ' . Migration::class . ': ' . basename($file));
            }
            $migrations[] = ['name' => basename($file, '.php'), 'instance' => $migration];
        }
        return $migrations;
    }
}
