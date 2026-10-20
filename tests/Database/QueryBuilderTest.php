<?php

declare(strict_types=1);

namespace Kabuto\Tests\Database;

use Kabuto\Database\QueryBuilder;

final class QueryBuilderTest extends DatabaseTestCase
{
    private function builder(): QueryBuilder
    {
        return new QueryBuilder(
            $this->connections->connection('default'),
            'sqlite',
            'base_users',
        );
    }

    public function testCompileSimpleWhere(): void
    {
        [$sql, $bindings] = $this->builder()->compileSelect(['where' => ['status' => 'active']]);

        self::assertSame('SELECT * FROM base_users WHERE "status" = :p1', $sql);
        self::assertSame([':p1' => 'active'], $bindings);
    }

    public function testCompileOperators(): void
    {
        [$sql, $bindings] = $this->builder()->compileSelect(['where' => [
            'age' => ['gte' => 18, 'lt' => 65],
            'name' => ['contains' => 'da'],
            'email' => ['in' => ['a@x.com', 'b@x.com']],
            'deleted' => ['isNull' => true],
        ]]);

        self::assertStringContains('"age" >= :p1', $sql);
        self::assertStringContains('"age" < :p2', $sql);
        self::assertStringContains('"name" LIKE :p3', $sql);
        self::assertStringContains('"email" IN (:p4, :p5)', $sql);
        self::assertStringContains('"deleted" IS NULL', $sql);
        self::assertSame('%da%', $bindings[':p3']);
    }

    public function testEmptyInMatchesNothing(): void
    {
        [$sql] = $this->builder()->compileSelect(['where' => ['id' => ['in' => []]]]);

        self::assertStringContains('1 = 0', $sql);
    }

    public function testSelectOrderTakeSkip(): void
    {
        [$sql] = $this->builder()->compileSelect([
            'select' => ['id', 'name'],
            'orderBy' => ['name' => 'desc', 'id' => 'asc'],
            'take' => 10,
            'skip' => 5,
        ]);

        self::assertSame(
            'SELECT "id", "name" FROM base_users ORDER BY "name" DESC, "id" ASC LIMIT 10 OFFSET 5',
            $sql,
        );
    }

    public function testUnknownOperatorThrows(): void
    {
        self::assertThrows(
            \Kabuto\Database\DatabaseException::class,
            fn () => $this->builder()->compileSelect(['where' => ['id' => ['fuzzy' => 1]]]),
        );
    }

    public function testMysqlQuoting(): void
    {
        $builder = new QueryBuilder(
            $this->connections->connection('default'),
            'mysql',
            '`app`.`users`',
        );

        [$sql] = $builder->compileSelect(['where' => ['id' => 1]]);

        self::assertSame('SELECT * FROM `app`.`users` WHERE `id` = :p1', $sql);
    }

    public function testUpdateWithoutWhereThrows(): void
    {
        self::assertThrows(
            \Kabuto\Database\DatabaseException::class,
            fn () => $this->builder()->update(['name' => 'x'], []),
        );
    }

    public function testDeleteWithoutWhereThrows(): void
    {
        self::assertThrows(
            \Kabuto\Database\DatabaseException::class,
            fn () => $this->builder()->delete([]),
        );
    }

    public function testOrGrouping(): void
    {
        [$sql, $bindings] = $this->builder()->compileSelect([
            'where' => ['OR' => [['status' => 'active'], ['age' => ['gte' => 18]]]],
        ]);

        self::assertSame('SELECT * FROM base_users WHERE (("status" = :p1) OR ("age" >= :p2))', $sql);
        self::assertSame([':p1' => 'active', ':p2' => 18], $bindings);
    }
}
