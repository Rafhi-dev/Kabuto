<?php

declare(strict_types=1);

namespace Kabuto\Tests\Database;

use Kabuto\Database\Migrator;

final class MigratorTest extends DatabaseTestCase
{
    public function testPushCreatesTables(): void
    {
        // Tables already created in setUp; push again must be a no-op.
        $migrator = new Migrator($this->schema, $this->connections);

        self::assertSame([], $migrator->push('default'));
        self::assertSame([], $migrator->push('second'));
    }

    public function testPushAddsMissingColumn(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE base_widgets ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "name" VARCHAR(50) NOT NULL)');

        $schema = \Kabuto\Database\Schema::define([
            'datasources' => ['default' => ['driver' => 'sqlite', 'path' => ':memory:']],
            'models' => ['Widget' => [
                'table' => 'widgets',
                'schema' => 'base',
                'datasource' => 'default',
                'fields' => ['id' => 'Int @id @default(autoincrement())', 'name' => 'String(50)', 'color' => 'String(20)?'],
            ]],
        ]);

        $connections = new \Kabuto\Database\ConnectionManager($schema);
        $connections->instance('default', $pdo);

        $migrator = new Migrator($schema, $connections);
        $actions = $migrator->push('default');

        self::assertCount(1, $actions);
        self::assertStringContains('ADD COLUMN', $actions[0]);

        // Second push is a no-op.
        self::assertSame([], $migrator->push('default'));
    }

    public function testPullRebuildsModels(): void
    {
        $migrator = new Migrator($this->schema, $this->connections);
        $models = $migrator->pull('default');

        self::assertArrayHasKey('User', $models);
        self::assertArrayHasKey('Post', $models);
        self::assertSame('users', $models['User']['table']);
        self::assertSame('base', $models['User']['schema']);
        self::assertSame('Int @id @default(autoincrement())', $models['User']['fields']['id']);
        self::assertSame('String(100)', $models['User']['fields']['name']);
    }

    public function testListTables(): void
    {
        $migrator = new Migrator($this->schema, $this->connections);
        $pdo = $this->connections->connection('default');

        $tables = $migrator->listTables($pdo, $this->schema->datasource('default'));

        self::assertContains('base_users', $tables);
        self::assertContains('base_posts', $tables);

        $introspector = new \Kabuto\Database\SchemaIntrospector($pdo, $this->schema->datasource('default'));
        self::assertSame('', $introspector->defaultNamespace());
    }

    /**
     * Auto-increment primary keys cannot be introduced via ALTER TABLE on any
     * driver — push must fail with a clear message instead of broken SQL.
     */
    public function testPushRejectsAddingAutoIncrementColumnToExistingTable(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE base_widgets ("name" VARCHAR(50) NOT NULL)');

        $schema = \Kabuto\Database\Schema::define([
            'datasources' => ['default' => ['driver' => 'sqlite', 'path' => ':memory:', 'schemas' => ['base']]],
            'models' => ['Widget' => [
                'table' => 'widgets',
                'schema' => 'base',
                'datasource' => 'default',
                'fields' => ['id' => 'Int @id @default(autoincrement())', 'name' => 'String(50)'],
            ]],
        ]);
        $connections = new \Kabuto\Database\ConnectionManager($schema);
        $connections->instance('default', $pdo);

        $migrator = new Migrator($schema, $connections);

        try {
            $migrator->push('default');
            self::assertTrue(false, 'Expected DatabaseException.');
        } catch (\Kabuto\Database\DatabaseException $e) {
            self::assertStringContains('Cannot add auto-increment primary key column [id]', $e->getMessage());
            self::assertStringContains('base_widgets', $e->getMessage());
        }
    }

    public function testPullInfersRelationsFromForeignKeys(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE base_authors ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "name" VARCHAR(100) NOT NULL)');
        $pdo->exec('CREATE TABLE base_posts ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "author_id" INTEGER NOT NULL REFERENCES base_authors(id))');

        $schema = \Kabuto\Database\Schema::define([
            'datasources' => ['default' => ['driver' => 'sqlite', 'path' => ':memory:', 'schemas' => ['base']]],
        ]);
        $connections = new \Kabuto\Database\ConnectionManager($schema);
        $connections->instance('default', $pdo);

        $migrator = new Migrator($schema, $connections);
        $models = $migrator->pull('default');

        self::assertArrayHasKey('Post', $models);
        self::assertArrayHasKey('Author', $models);
        self::assertSame(
            ['author' => ['model' => 'Author', 'foreignKey' => 'author_id', 'references' => 'id']],
            $models['Post']['relations'],
        );
        self::assertSame([], $models['Author']['relations']);
    }

    public function testPullForeignKeysWithoutSchemaPrefix(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE authors ("id" INTEGER PRIMARY KEY AUTOINCREMENT)');
        $pdo->exec('CREATE TABLE posts ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "author_id" INTEGER REFERENCES authors(id))');

        $schema = \Kabuto\Database\Schema::define([
            'datasources' => ['default' => ['driver' => 'sqlite', 'path' => ':memory:']],
        ]);
        $connections = new \Kabuto\Database\ConnectionManager($schema);
        $connections->instance('default', $pdo);

        $migrator = new Migrator($schema, $connections);
        $models = $migrator->pull('default');

        self::assertSame(
            ['author' => ['model' => 'Author', 'foreignKey' => 'author_id', 'references' => 'id']],
            $models['Post']['relations'],
        );
    }
}
