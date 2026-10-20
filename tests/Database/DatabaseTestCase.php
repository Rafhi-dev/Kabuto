<?php

declare(strict_types=1);

namespace Kabuto\Tests\Database;

use Kabuto\Database\ConnectionManager;
use Kabuto\Database\DB;
use Kabuto\Database\Schema;
use Kabuto\Database\SchemaDefinition;
use Kabuto\Tests\TestCase;

/**
 * Shared SQLite in-memory schema for database tests.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected SchemaDefinition $schema;

    protected ConnectionManager $connections;

    protected DB $db;

    protected function setUp(): void
    {
        $this->schema = Schema::define([
            'datasources' => [
                'default' => ['driver' => 'sqlite', 'path' => ':memory:', 'schemas' => ['base']],
                'second' => ['driver' => 'sqlite', 'path' => ':memory:', 'schemas' => ['other']],
            ],
            'models' => [
                'User' => [
                    'table' => 'users',
                    'schema' => 'base',
                    'datasource' => 'default',
                    'fields' => [
                        'id' => 'Int @id @default(autoincrement())',
                        'name' => 'String(100)',
                        'email' => 'String(150) @unique',
                        'age' => 'Int?',
                        'status' => 'Enum(active,inactive) @default(active)',
                    ],
                    'relations' => ['posts' => 'Post[]'],
                ],
                'Post' => [
                    'table' => 'posts',
                    'schema' => 'base',
                    'datasource' => 'default',
                    'fields' => [
                        'id' => 'Int @id @default(autoincrement())',
                        'author_id' => 'Int @index',
                        'title' => 'String(200)',
                        'body' => 'Text?',
                    ],
                    'relations' => ['author' => 'User @relation(fields: [author_id], references: [id])'],
                ],
                'Event' => [
                    'table' => 'events',
                    'schema' => 'other',
                    'datasource' => 'second',
                    'fields' => [
                        'id' => 'Int @id @default(autoincrement())',
                        'kind' => 'String(50)',
                    ],
                ],
            ],
        ]);

        $this->connections = new ConnectionManager($this->schema);
        $this->db = new DB($this->connections);

        $migrator = new \Kabuto\Database\Migrator($this->schema, $this->connections);
        $migrator->push();
    }
}
