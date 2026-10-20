<?php

declare(strict_types=1);

namespace Kabuto\Tests\Database;

use Kabuto\Database\ConnectionManager;
use Kabuto\Database\DatabaseException;
use Kabuto\Database\DB;
use Kabuto\Database\Migrator;
use Kabuto\Database\Schema;
use Kabuto\Tests\TestCase;

/**
 * Runs one behavior scenario per driver: idempotent push, CRUD, unique-violation
 * normalization, transaction commit/rollback, nested savepoints, and pull
 * round-trip. SQLite always runs; MySQL and PostgreSQL run when the TEST_*_*
 * environment variables and PDO extensions are available.
 */
final class DatabaseCompatibilityTest extends TestCase
{
    public function testSqliteCompatibility(): void
    {
        $this->runScenario(['driver' => 'sqlite', 'path' => ':memory:']);
    }

    public function testMysqlCompatibility(): void
    {
        $config = $this->envConfig('TEST_MYSQL_', 'mysql', 3306);
        if ($config === null || !extension_loaded('pdo_mysql')) {
            return;
        }

        $this->runScenario($config);
    }

    public function testPgsqlCompatibility(): void
    {
        $config = $this->envConfig('TEST_PGSQL_', 'pgsql', 5432);
        if ($config === null || !extension_loaded('pdo_pgsql')) {
            return;
        }

        $this->runScenario($config);
    }

    /** @return array<string,mixed>|null */
    private function envConfig(string $prefix, string $driver, int $defaultPort): ?array
    {
        $host = (string) (getenv($prefix . 'HOST') ?: '');
        $database = (string) (getenv($prefix . 'DATABASE') ?: '');
        $username = (string) (getenv($prefix . 'USERNAME') ?: '');

        if ($host === '' || $database === '') {
            return null;
        }

        return [
            'driver' => $driver,
            'host' => $host,
            'port' => (int) (getenv($prefix . 'PORT') ?: $defaultPort),
            'database' => $database,
            'username' => $username,
            'password' => (string) (getenv($prefix . 'PASSWORD') ?: ''),
        ];
    }

    /** @param array<string,mixed> $datasource */
    private function runScenario(array $datasource): void
    {
        $schema = Schema::define([
            'datasources' => ['db' => $datasource],
            'models' => [
                'Account' => [
                    'table' => 'accounts',
                    'datasource' => 'db',
                    'fields' => [
                        'id' => 'Int @id @default(autoincrement())',
                        'name' => 'String(100)',
                        'email' => 'String(150) @unique',
                        'active' => 'Boolean @default(true)',
                        'balance' => 'Decimal @default(0)',
                        'meta' => 'Json?',
                        'created_at' => 'DateTime @default(now())',
                    ],
                ],
            ],
        ]);

        $connections = new ConnectionManager($schema);
        $migrator = new Migrator($schema, $connections);

        // Idempotent push: first run creates, second is a no-op.
        $migrator->push('db');
        self::assertSame([], $migrator->push('db'));

        $db = new DB($connections);
        $suffix = substr(uniqid('', true), -8);
        $email = "compat-{$suffix}@example.test";

        // CRUD + defaults round-trip.
        $created = $db->account->create(['data' => [
            'name' => "row-{$suffix}",
            'email' => $email,
            'meta' => ['tier' => 1],
        ]]);

        $rows = $db->account->findMany(['where' => ['email' => $email]]);
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertArrayHasKey('id', $row);
        self::assertTrue(in_array($row['active'] ?? null, [1, '1', true, 't', 'true'], true), 'active default not applied');
        self::assertSame(0.0, (float) ($row['balance'] ?? 'nan'));
        self::assertTrue(isset($row['created_at']) && $row['created_at'] !== '', 'created_at default not applied');
        self::assertSame($created['id'], $row['id']);

        // Unique violation normalizes to the shared category.
        $uniqueCaught = false;
        try {
            $db->account->create(['data' => ['name' => "dup-{$suffix}", 'email' => $email]]);
        } catch (DatabaseException $e) {
            $uniqueCaught = true;
            self::assertSame(DatabaseException::CATEGORY_UNIQUE, $e->category());
        }
        self::assertTrue($uniqueCaught, 'Expected unique constraint violation.');

        // Transaction commit persists.
        $db->transaction(function (DB $db) use ($suffix): void {
            $db->account->create(['data' => ['name' => "commit-{$suffix}", 'email' => "commit-{$suffix}@example.test"]]);
        });
        self::assertCount(1, $db->account->findMany(['where' => ['name' => "commit-{$suffix}"]]));

        // Transaction rollback discards.
        try {
            $db->transaction(function (DB $db) use ($suffix): void {
                $db->account->create(['data' => ['name' => "rollback-{$suffix}", 'email' => "rollback-{$suffix}@example.test"]]);
                throw new \RuntimeException('abort');
            });
            self::assertTrue(false, 'Expected RuntimeException.');
        } catch (\RuntimeException) {
            // Expected.
        }
        self::assertCount(0, $db->account->findMany(['where' => ['name' => "rollback-{$suffix}"]]));

        // Nested transaction: savepoint rollback keeps outer work, drops inner.
        $db->transaction(function (DB $db) use ($suffix): void {
            $db->account->create(['data' => ['name' => "outer-{$suffix}", 'email' => "outer-{$suffix}@example.test"]]);
            try {
                $db->transaction(function (DB $db) use ($suffix): void {
                    $db->account->create(['data' => ['name' => "inner-{$suffix}", 'email' => "inner-{$suffix}@example.test"]]);
                    throw new \RuntimeException('inner abort');
                });
                self::assertTrue(false, 'Expected inner RuntimeException.');
            } catch (\RuntimeException) {
                // Inner failed; outer continues.
            }
        });

        self::assertCount(1, $db->account->findMany(['where' => ['name' => "outer-{$suffix}"]]));
        self::assertCount(0, $db->account->findMany(['where' => ['name' => "inner-{$suffix}"]]));

        // Pull round-trip.
        $models = $migrator->pull('db');
        self::assertArrayHasKey('Account', $models);
        self::assertSame('accounts', $models['Account']['table']);
        self::assertStringContains('@unique', $models['Account']['fields']['email']);
        self::assertStringContains('autoincrement', $models['Account']['fields']['id']);
    }
}
