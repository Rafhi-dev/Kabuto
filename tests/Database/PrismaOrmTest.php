<?php

declare(strict_types=1);

namespace Kabuto\Tests\Database;

use Kabuto\Exceptions\NotFoundException;

final class PrismaOrmTest extends DatabaseTestCase
{
    public function testFindUniqueOrThrow(): void
    {
        $user = $this->db->user->create(['data' => ['name' => 'John', 'email' => 'john@prisma.com']]);

        $found = $this->db->user->findUniqueOrThrow(['where' => ['id' => $user['id']]]);
        self::assertSame('John', $found['name']);

        self::assertThrows(
            NotFoundException::class,
            fn () => $this->db->user->findUniqueOrThrow(['where' => ['id' => 99999]]),
        );
    }

    public function testFindFirstOrThrow(): void
    {
        $this->db->user->create(['data' => ['name' => 'Sarah', 'email' => 'sarah@prisma.com']]);

        $found = $this->db->user->findFirstOrThrow(['where' => ['name' => 'Sarah']]);
        self::assertSame('sarah@prisma.com', $found['email']);

        self::assertThrows(
            NotFoundException::class,
            fn () => $this->db->user->findFirstOrThrow(['where' => ['name' => 'NonExistent']]),
        );
    }

    public function testCreateMany(): void
    {
        $result = $this->db->user->createMany([
            'data' => [
                ['name' => 'Alice', 'email' => 'alice@prisma.com', 'age' => 25],
                ['name' => 'Bob', 'email' => 'bob@prisma.com', 'age' => 30],
                ['name' => 'Charlie', 'email' => 'charlie@prisma.com', 'age' => 35],
            ],
        ]);

        self::assertSame(3, $result['count']);
        self::assertSame(3, $this->db->user->count());
    }

    public function testUpdateMany(): void
    {
        $this->db->user->create(['data' => ['name' => 'Alice', 'email' => 'a@x.com', 'status' => 'inactive']]);
        $this->db->user->create(['data' => ['name' => 'Bob', 'email' => 'b@x.com', 'status' => 'inactive']]);
        $this->db->user->create(['data' => ['name' => 'Charlie', 'email' => 'c@x.com', 'status' => 'active']]);

        $result = $this->db->user->updateMany([
            'where' => ['status' => 'inactive'],
            'data' => ['status' => 'active'],
        ]);

        self::assertSame(2, $result['count']);
        self::assertSame(3, $this->db->user->count(['where' => ['status' => 'active']]));
    }

    public function testDeleteMany(): void
    {
        $this->db->user->create(['data' => ['name' => 'A', 'email' => 'a@x.com', 'age' => 15]]);
        $this->db->user->create(['data' => ['name' => 'B', 'email' => 'b@x.com', 'age' => 16]]);
        $this->db->user->create(['data' => ['name' => 'C', 'email' => 'c@x.com', 'age' => 25]]);

        $result = $this->db->user->deleteMany([
            'where' => ['age' => ['lt' => 18]],
        ]);

        self::assertSame(2, $result['count']);
        self::assertSame(1, $this->db->user->count());
    }

    public function testUpsert(): void
    {
        // 1. Create on first upsert
        $user1 = $this->db->user->upsert([
            'where' => ['email' => 'upsert@prisma.com'],
            'create' => ['name' => 'Upsert Initial', 'email' => 'upsert@prisma.com', 'age' => 20],
            'update' => ['name' => 'Upsert Updated'],
        ]);

        self::assertSame('Upsert Initial', $user1['name']);
        self::assertSame(1, $this->db->user->count());

        // 2. Update on second upsert
        $user2 = $this->db->user->upsert([
            'where' => ['email' => 'upsert@prisma.com'],
            'create' => ['name' => 'Upsert Initial', 'email' => 'upsert@prisma.com'],
            'update' => ['name' => 'Upsert Updated'],
        ]);

        self::assertSame('Upsert Updated', $user2['name']);
        self::assertSame(1, $this->db->user->count());
    }

    public function testPrismaSelect(): void
    {
        $this->db->user->create(['data' => ['name' => 'Dave', 'email' => 'dave@prisma.com', 'age' => 40]]);

        // Associative map select: { id: true, name: true }
        $user = $this->db->user->findFirst([
            'where' => ['email' => 'dave@prisma.com'],
            'select' => [
                'id' => true,
                'name' => true,
            ],
        ]);

        self::assertArrayHasKey('id', $user);
        self::assertArrayHasKey('name', $user);
        self::assertFalse(array_key_exists('email', $user));
        self::assertFalse(array_key_exists('age', $user));
    }

    public function testAggregate(): void
    {
        $this->db->user->create(['data' => ['name' => 'A', 'email' => 'a@x.com', 'age' => 20]]);
        $this->db->user->create(['data' => ['name' => 'B', 'email' => 'b@x.com', 'age' => 30]]);
        $this->db->user->create(['data' => ['name' => 'C', 'email' => 'c@x.com', 'age' => 40]]);

        $agg = $this->db->user->aggregate([
            '_count' => true,
            '_avg' => ['age' => true],
            '_sum' => ['age' => true],
            '_min' => ['age' => true],
            '_max' => ['age' => true],
        ]);

        self::assertSame(3, $agg['_count']);
        self::assertSame(30, (int) $agg['_avg']['age']);
        self::assertSame(90, (int) $agg['_sum']['age']);
        self::assertSame(20, (int) $agg['_min']['age']);
        self::assertSame(40, (int) $agg['_max']['age']);
    }

    public function testGroupBy(): void
    {
        $this->db->user->create(['data' => ['name' => 'A', 'email' => 'a@x.com', 'status' => 'active']]);
        $this->db->user->create(['data' => ['name' => 'B', 'email' => 'b@x.com', 'status' => 'active']]);
        $this->db->user->create(['data' => ['name' => 'C', 'email' => 'c@x.com', 'status' => 'inactive']]);

        $groups = $this->db->user->groupBy([
            'by' => ['status'],
            '_count' => true,
            'orderBy' => ['status' => 'asc'],
        ]);

        self::assertCount(2, $groups);
        self::assertSame('active', $groups[0]['status']);
        self::assertSame(2, $groups[0]['_count']);
        self::assertSame('inactive', $groups[1]['status']);
        self::assertSame(1, $groups[1]['_count']);
    }

    public function testNestedWritesCreate(): void
    {
        $user = $this->db->user->create([
            'data' => [
                'name' => 'Author',
                'email' => 'author@prisma.com',
                'posts' => [
                    'create' => [
                        ['title' => 'First Nested Post'],
                        ['title' => 'Second Nested Post'],
                    ],
                ],
            ],
            'include' => [
                'posts' => true,
            ],
        ]);

        self::assertSame('Author', $user['name']);
        self::assertCount(2, $user['posts']);
        self::assertSame('First Nested Post', $user['posts'][0]['title']);
        self::assertSame('Second Nested Post', $user['posts'][1]['title']);
    }

    public function testWhereNotOperator(): void
    {
        $this->db->user->create(['data' => ['name' => 'A', 'email' => 'a@x.com', 'status' => 'active']]);
        $this->db->user->create(['data' => ['name' => 'B', 'email' => 'b@x.com', 'status' => 'inactive']]);

        $rows = $this->db->user->findMany([
            'where' => [
                'NOT' => ['status' => 'inactive'],
            ],
        ]);

        self::assertCount(1, $rows);
        self::assertSame('A', $rows[0]['name']);
    }

    public function testRawQueriesAndBatchTransactions(): void
    {
        $this->db->user->create(['data' => ['name' => 'RawTest', 'email' => 'raw@x.com']]);

        $rows = $this->db->queryRaw('SELECT name FROM base_users WHERE email = :email', [':email' => 'raw@x.com']);
        self::assertCount(1, $rows);
        self::assertSame('RawTest', $rows[0]['name']);

        $affected = $this->db->executeRaw('UPDATE base_users SET name = :name WHERE email = :email', [
            ':name' => 'RawUpdated',
            ':email' => 'raw@x.com',
        ]);
        self::assertSame(1, $affected);

        // Sequential batch transaction (Prisma style: prisma.$transaction([op1, op2]))
        $results = $this->db->transaction([
            fn ($db) => $db->user->create(['data' => ['name' => 'Batch1', 'email' => 'b1@x.com']]),
            fn ($db) => $db->user->create(['data' => ['name' => 'Batch2', 'email' => 'b2@x.com']]),
        ]);

        self::assertCount(2, $results);
        self::assertSame('Batch1', $results[0]['name']);
        self::assertSame('Batch2', $results[1]['name']);
    }

    public function testPrismaDollarMethodAliases(): void
    {
        // Test __call alias: $db->$queryRaw(...)
        $rows = $this->db->__call('$queryRaw', ['SELECT 1 AS num']);
        self::assertSame(1, (int) $rows[0]['num']);
    }
}
