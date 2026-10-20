<?php

declare(strict_types=1);

namespace Kabuto\Tests\Database;

use Kabuto\Database\DatabaseException;
use Kabuto\Database\Schema;

final class SchemaTest extends DatabaseTestCase
{
    public function testParsesFieldSpec(): void
    {
        $field = $this->schema->model('User')->field('email');

        self::assertSame('string', $field->type);
        self::assertSame(150, $field->length);
        self::assertTrue($field->has('unique'));
    }

    public function testParsesEnumWithDefault(): void
    {
        $field = $this->schema->model('User')->field('status');

        self::assertSame('enum', $field->type);
        self::assertSame(['active', 'inactive'], $field->params);
        self::assertSame('active', $field->default);
    }

    public function testUnknownDatasourceThrows(): void
    {
        self::assertThrows(
            DatabaseException::class,
            static fn () => Schema::define([
                'datasources' => ['db' => ['driver' => 'sqlite']],
                'models' => ['X' => ['table' => 'x', 'datasource' => 'nope', 'fields' => ['id' => 'Int @id @default(autoincrement())']]],
            ]),
        );
    }

    public function testUnknownSchemaThrows(): void
    {
        self::assertThrows(
            DatabaseException::class,
            static fn () => Schema::define([
                'datasources' => ['db' => ['driver' => 'sqlite', 'schemas' => ['base']]],
                'models' => ['X' => ['table' => 'x', 'schema' => 'nope', 'fields' => ['id' => 'Int @id @default(autoincrement())']]],
            ]),
        );
    }

    public function testUnknownRelationTargetThrows(): void
    {
        self::assertThrows(
            DatabaseException::class,
            static fn () => Schema::define([
                'datasources' => ['db' => ['driver' => 'sqlite']],
                'models' => ['X' => [
                    'table' => 'x',
                    'fields' => ['id' => 'Int @id @default(autoincrement())'],
                    'relations' => ['ys' => 'Missing[]'],
                ]],
            ]),
        );
    }

    public function testInvalidRelationSpecThrows(): void
    {
        self::assertThrows(
            DatabaseException::class,
            static fn () => Schema::define([
                'datasources' => ['db' => ['driver' => 'sqlite']],
                'models' => ['X' => [
                    'table' => 'x',
                    'fields' => ['id' => 'Int @id @default(autoincrement())'],
                    'relations' => ['ys' => 'bogus'],
                ]],
            ]),
        );
    }

    public function testLegacyCompactFieldThrows(): void
    {
        self::assertThrows(
            DatabaseException::class,
            static fn () => Schema::define([
                'datasources' => ['db' => ['driver' => 'sqlite']],
                'models' => ['X' => [
                    'table' => 'x',
                    'fields' => ['id' => 'increments'],
                ]],
            ]),
        );

        self::assertThrows(
            DatabaseException::class,
            static fn () => Schema::define([
                'datasources' => ['db' => ['driver' => 'sqlite']],
                'models' => ['X' => [
                    'table' => 'x',
                    'fields' => ['title' => 'string:100|nullable'],
                ]],
            ]),
        );
    }

    public function testLegacyCompactRelationThrows(): void
    {
        self::assertThrows(
            DatabaseException::class,
            static fn () => Schema::define([
                'datasources' => ['db' => ['driver' => 'sqlite']],
                'models' => [
                    'User' => [
                        'fields' => ['id' => 'Int @id @default(autoincrement())'],
                        'relations' => ['posts' => 'hasMany:Post,user_id'],
                    ],
                    'Post' => [
                        'fields' => ['id' => 'Int @id @default(autoincrement())'],
                    ],
                ],
            ]),
        );
    }

    public function testModelsForDatasource(): void
    {
        $names = array_map(
            static fn ($m) => $m->name,
            $this->schema->modelsForDatasource('second'),
        );

        self::assertSame(['Event'], $names);
    }

    public function testModelDefaults(): void
    {
        $definition = Schema::define([
            'datasources' => ['db' => ['driver' => 'sqlite']],
            'models' => ['Post' => ['fields' => ['id' => 'Int @id @default(autoincrement())', 'title' => 'String']]],
        ]);

        $model = $definition->model('Post');

        self::assertSame('posts', $model->table);
        self::assertSame('db', $model->datasource);
        self::assertSame('id', $model->primaryKey());
    }
}
