<?php

declare(strict_types=1);

namespace Kabuto\Tests\Database;

use Kabuto\Database\ConnectionManager;
use Kabuto\Database\DB;
use Kabuto\Database\Migrator;
use Kabuto\Database\Schema;
use Kabuto\Tests\TestCase;

final class ForeignKeyMigrateTest extends TestCase
{
    public function testForeignKeyConstraintIsCreatedDuringPush(): void
    {
        $schema = Schema::define([
            'datasources' => ['db' => ['driver' => 'sqlite', 'path' => ':memory:']],
            'models' => [
                'Author' => [
                    'table' => 'authors',
                    'fields' => ['id' => 'Int @id @default(autoincrement())', 'name' => 'String(100)'],
                ],
                'Article' => [
                    'table' => 'articles',
                    'fields' => [
                        'id' => 'Int @id @default(autoincrement())',
                        'title' => 'String(200)',
                        'author_id' => 'Int',
                    ],
                    'relations' => [
                        'author' => 'Author @relation(fields: [author_id], references: [id])',
                    ],
                ],
            ],
        ]);

        $connections = new ConnectionManager($schema);
        $db = new DB($connections);
        $migrator = new Migrator($schema, $connections);
        $migrator->push();

        // Query sqlite_master to verify foreign key was created
        $pdo = $connections->connection('db');
        $stmt = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='articles'");
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        self::assertNotFalse($result);
        self::assertStringContains('FOREIGN KEY', $result['sql']);
        self::assertStringContains('author_id', $result['sql']);
        self::assertStringContains('REFERENCES', $result['sql']);
        self::assertStringContains('authors', $result['sql']);
    }

    public function testForeignKeyEnforcesReferentialIntegrity(): void
    {
        $schema = Schema::define([
            'datasources' => ['db' => ['driver' => 'sqlite', 'path' => ':memory:']],
            'models' => [
                'Author' => [
                    'table' => 'authors',
                    'fields' => ['id' => 'Int @id @default(autoincrement())', 'name' => 'String(100)'],
                ],
                'Article' => [
                    'table' => 'articles',
                    'fields' => [
                        'id' => 'Int @id @default(autoincrement())',
                        'title' => 'String(200)',
                        'author_id' => 'Int',
                    ],
                    'relations' => [
                        'author' => 'Author @relation(fields: [author_id], references: [id])',
                    ],
                ],
            ],
        ]);

        $connections = new ConnectionManager($schema);
        $db = new DB($connections);
        $migrator = new Migrator($schema, $connections);
        $migrator->push();

        // Enable foreign key enforcement in SQLite
        $pdo = $connections->connection('db');
        $pdo->exec('PRAGMA foreign_keys = ON');

        // Try to insert an article with a non-existent author
        $caught = false;
        try {
            $pdo->exec("INSERT INTO articles (title, author_id) VALUES ('Test', 999)");
        } catch (\PDOException $e) {
            $caught = true;
            self::assertStringContains('FOREIGN KEY constraint failed', $e->getMessage());
        }

        self::assertTrue($caught, 'Expected foreign key constraint violation');
    }
}
