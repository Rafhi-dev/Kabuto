<?php

declare(strict_types=1);

namespace Kabuto\Tests\Database;

use Kabuto\Database\ConnectionManager;
use Kabuto\Database\MigrationRunner;
use Kabuto\Database\Schema;
use Kabuto\Tests\TestCase;
use PDO;

final class MigrationRunnerTest extends TestCase
{
    private string $directory;
    private MigrationRunner $runner;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kabuto-migrations-' . uniqid();
        mkdir($this->directory, 0777, true);
        $schema = Schema::define(['datasources' => ['db' => ['driver' => 'sqlite', 'path' => ':memory:']]]);
        $connections = new ConnectionManager($schema);
        $this->runner = new MigrationRunner($connections, $this->directory);
        $connections->instance('db', new PDO('sqlite::memory:'));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) @unlink($file);
        @rmdir($this->directory);
    }

    public function testMigrateUsesOneBatchAndRollsBackLatestMigration(): void
    {
        file_put_contents($this->directory . '/001_create.php', <<<'PHP'
<?php
return new class implements \Kabuto\Database\Migration {
    public function up(\PDO $pdo): void { $pdo->exec('CREATE TABLE first_table (id INTEGER)'); }
    public function down(\PDO $pdo): void { $pdo->exec('DROP TABLE first_table'); }
};
PHP);
        file_put_contents($this->directory . '/002_create.php', <<<'PHP'
<?php
return new class implements \Kabuto\Database\Migration {
    public function up(\PDO $pdo): void { $pdo->exec('CREATE TABLE second_table (id INTEGER)'); }
    public function down(\PDO $pdo): void { $pdo->exec('DROP TABLE second_table'); }
};
PHP);

        self::assertSame(['001_create', '002_create'], $this->runner->migrate('db'));
        self::assertSame(['001_create' => 1, '002_create' => 1], $this->migrationRows());
        self::assertSame(['002_create'], $this->runner->rollback('db'));
        self::assertSame(['001_create' => 1], $this->migrationRows());
    }

    /** @return array<string,int> */
    private function migrationRows(): array
    {
        $status = $this->runner->status('db');
        $rows = [];
        foreach ($status as $item) {
            if ($item['status'] === 'applied') $rows[$item['name']] = 1;
        }
        return $rows;
    }
}
