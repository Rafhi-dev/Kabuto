<?php

declare(strict_types=1);

namespace Kabuto\Tests\Database;

use Kabuto\Database\Schema;
use Kabuto\Database\SchemaCompiler;

final class ForeignKeyTest extends DatabaseTestCase
{
    public function testEmitsForeignKeyConstraintForBelongsTo(): void
    {
        $schema = Schema::define([
            'datasources' => ['db' => ['driver' => 'sqlite']],
            'models' => [
                'User' => [
                    'table' => 'users',
                    'fields' => ['id' => 'Int @id @default(autoincrement())', 'name' => 'String(100)'],
                ],
                'Post' => [
                    'table' => 'posts',
                    'fields' => [
                        'id' => 'Int @id @default(autoincrement())',
                        'title' => 'String(200)',
                        'author_id' => 'Int',
                    ],
                    'relations' => [
                        'author' => 'User @relation(fields: [author_id], references: [id])',
                    ],
                ],
            ],
        ]);

        $ds = $schema->datasource();
        $compiler = new SchemaCompiler($ds, $schema->modelsForDatasource($ds->name));
        $postModel = $schema->model('Post');

        $sql = $compiler->createTable($postModel);

        self::assertStringContains('FOREIGN KEY', $sql);
        self::assertStringContains('author_id', $sql);
        self::assertStringContains('REFERENCES', $sql);
        self::assertStringContains('users', $sql);
    }

    public function testDoesNotEmitForeignKeyForHasMany(): void
    {
        $schema = Schema::define([
            'datasources' => ['db' => ['driver' => 'sqlite']],
            'models' => [
                'User' => [
                    'table' => 'users',
                    'fields' => ['id' => 'Int @id @default(autoincrement())', 'name' => 'String(100)'],
                    'relations' => ['posts' => 'Post[]'],
                ],
                'Post' => [
                    'table' => 'posts',
                    'fields' => [
                        'id' => 'Int @id @default(autoincrement())',
                        'title' => 'String(200)',
                        'user_id' => 'Int',
                    ],
                ],
            ],
        ]);

        $ds = $schema->datasource();
        $compiler = new SchemaCompiler($ds, $schema->modelsForDatasource($ds->name));
        $userModel = $schema->model('User');

        $sql = $compiler->createTable($userModel);

        self::assertFalse(str_contains($sql, 'FOREIGN KEY'));
    }

    public function testReferencesCustomColumn(): void
    {
        $schema = Schema::define([
            'datasources' => ['db' => ['driver' => 'sqlite']],
            'models' => [
                'Category' => [
                    'table' => 'categories',
                    'fields' => ['uuid' => 'String(36) @unique', 'name' => 'String(100)'],
                ],
                'Article' => [
                    'table' => 'articles',
                    'fields' => [
                        'id' => 'Int @id @default(autoincrement())',
                        'title' => 'String(200)',
                        'category_uuid' => 'String(36)',
                    ],
                    'relations' => [
                        'category' => 'Category @relation(fields: [category_uuid], references: [uuid])',
                    ],
                ],
            ],
        ]);

        $ds = $schema->datasource();
        $compiler = new SchemaCompiler($ds, $schema->modelsForDatasource($ds->name));
        $articleModel = $schema->model('Article');

        $sql = $compiler->createTable($articleModel);

        self::assertStringContains('FOREIGN KEY', $sql);
        self::assertStringContains('category_uuid', $sql);
        self::assertStringContains('REFERENCES', $sql);
        self::assertStringContains('categories', $sql);
        self::assertStringContains('uuid', $sql);
    }

    public function testForeignKeyDdlUsesDriverSpecificQualifiedReferences(): void
    {
        foreach (['mysql', 'pgsql'] as $driver) {
            $schema = Schema::define([
                'datasources' => ['db' => ['driver' => $driver, 'schemas' => ['app']]],
                'models' => [
                    'User' => [
                        'table' => 'users', 'schema' => 'app',
                        'fields' => ['id' => 'Int @id @default(autoincrement())'],
                    ],
                    'Post' => [
                        'table' => 'posts', 'schema' => 'app',
                        'fields' => ['id' => 'Int @id @default(autoincrement())', 'user_id' => 'Int'],
                        'relations' => ['user' => 'User @relation(fields: [user_id], references: [id])'],
                    ],
                ],
            ]);
            $datasource = $schema->datasource('db');
            $sql = (new SchemaCompiler($datasource))->createTable($schema->model('Post'));

            self::assertStringContains('FOREIGN KEY', $sql);
            self::assertStringContains('REFERENCES', $sql);
            self::assertStringContains($driver === 'mysql' ? '`app`.`users`' : '"app"."users"', $sql);
        }
    }
}
