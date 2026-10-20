<?php

declare(strict_types=1);

namespace Kabuto\Tests\Database;

final class DBClientTest extends DatabaseTestCase
{
    public function testCrudRoundTrip(): void
    {
        $created = $this->db->user->create(['data' => ['name' => 'Ada', 'email' => 'ada@x.com', 'age' => 36]]);

        self::assertSame('Ada', $created['name']);
        self::assertTrue(isset($created['id']));

        $found = $this->db->user->findUnique(['where' => ['id' => $created['id']]]);
        self::assertSame('ada@x.com', $found['email']);

        $updated = $this->db->user->update(['where' => ['id' => $created['id']], 'data' => ['name' => 'A. Lovelace']]);
        self::assertSame('A. Lovelace', $updated['name']);

        self::assertSame(1, $this->db->user->delete(['where' => ['id' => $created['id']]]));
        self::assertNull($this->db->user->findUnique(['where' => ['id' => $created['id']]]));
    }

    public function testFindManyWithOperators(): void
    {
        $this->db->user->create(['data' => ['name' => 'Ada', 'email' => 'a@x.com', 'age' => 36]]);
        $this->db->user->create(['data' => ['name' => 'Bo', 'email' => 'b@x.com', 'age' => 20]]);
        $this->db->user->create(['data' => ['name' => 'Cy', 'email' => 'c@x.com']]);

        $rows = $this->db->user->findMany([
            'where' => ['age' => ['gte' => 18]],
            'orderBy' => ['age' => 'desc'],
        ]);

        self::assertCount(2, $rows);
        self::assertSame('Ada', $rows[0]['name']);

        $page = $this->db->user->findMany(['take' => 1, 'skip' => 1, 'orderBy' => ['id' => 'asc']]);
        self::assertCount(1, $page);

        self::assertSame(3, $this->db->user->count());
        self::assertSame(1, $this->db->user->count(['where' => ['age' => ['isNull' => true]]]));
    }

    public function testHasManyInclude(): void
    {
        $user = $this->db->user->create(['data' => ['name' => 'Ada', 'email' => 'a@x.com']]);
        $this->db->post->create(['data' => ['author_id' => $user['id'], 'title' => 'One']]);
        $this->db->post->create(['data' => ['author_id' => $user['id'], 'title' => 'Two']]);

        $found = $this->db->user->findUnique(['where' => ['id' => $user['id']], 'include' => ['posts' => true]]);

        self::assertCount(2, $found['posts']);
        self::assertSame('One', $found['posts'][0]['title']);
    }

    public function testBelongsToInclude(): void
    {
        $user = $this->db->user->create(['data' => ['name' => 'Ada', 'email' => 'a@x.com']]);
        $this->db->post->create(['data' => ['author_id' => $user['id'], 'title' => 'One']]);

        $rows = $this->db->post->findMany(['include' => ['author' => true]]);

        self::assertSame('Ada', $rows[0]['author']['name']);
    }

    public function testCamelCaseModelAccess(): void
    {
        $row = $this->db->user->create(['data' => ['name' => 'Ada', 'email' => 'a@x.com']]);

        self::assertTrue(isset($this->db->user));
        self::assertFalse(isset($this->db->missing));
        self::assertSame($row['id'], $this->db->User->findUnique(['where' => ['id' => $row['id']]])['id']);
    }

    public function testMultiDatasourceIsolation(): void
    {
        $this->db->user->create(['data' => ['name' => 'Ada', 'email' => 'a@x.com']]);

        self::assertSame(1, $this->db->user->count());
        self::assertSame(0, $this->db->schema('second')->event->count());

        $this->db->schema('second')->event->create(['data' => ['kind' => 'login']]);
        self::assertSame(1, $this->db->schema('second')->event->count());

        // Unknown model in scope throws.
        self::assertThrows(
            \Kabuto\Database\DatabaseException::class,
            fn () => $this->db->schema('second')->user->count(),
        );
    }

    public function testTransactionCommitsAndRollsBack(): void
    {
        $this->db->transaction(function ($db): void {
            $db->user->create(['data' => ['name' => 'Tx', 'email' => 'tx@x.com']]);
        });
        self::assertSame(1, $this->db->user->count());

        try {
            $this->db->transaction(function ($db): void {
                $db->user->create(['data' => ['name' => 'Nope', 'email' => 'no@x.com']]);
                throw new \RuntimeException('abort');
            });
            self::assertTrue(false, 'Expected RuntimeException.');
        } catch (\RuntimeException) {
            // Expected.
        }

        self::assertSame(1, $this->db->user->count());
    }

    /**
     * Regression: schema-scoped DB clients share one PDO connection, so
     * savepoint names must never repeat across instances — otherwise a deep
     * RELEASE removes a middle-level savepoint and the outer work is lost.
     */
    public function testNestedSavepointsAcrossSchemaScopedInstances(): void
    {
        $suffix = substr(uniqid('', true), -8);
        $db = $this->db;

        $db->transaction(function (\Kabuto\Database\DB $db) use ($suffix): void {
            $scoped = $db->schema('base');

            $scoped->transaction(function (\Kabuto\Database\DB $scoped) use ($db, $suffix): void {
                $scoped->user->create(['data' => ['name' => "mid-{$suffix}", 'email' => "mid-{$suffix}@x.com"]]);

                try {
                    $db->transaction(function (\Kabuto\Database\DB $db) use ($suffix): void {
                        $db->user->create(['data' => ['name' => "deep-{$suffix}", 'email' => "deep-{$suffix}@x.com"]]);
                        throw new \RuntimeException('deep abort');
                    });
                    self::assertTrue(false, 'Expected deep RuntimeException.');
                } catch (\RuntimeException) {
                    // Deep level rolled back; middle level continues.
                }
            });
        });

        self::assertCount(1, $this->db->user->findMany(['where' => ['name' => "mid-{$suffix}"]]));
        self::assertCount(0, $this->db->user->findMany(['where' => ['name' => "deep-{$suffix}"]]));
    }

    public function testUnknownFieldsAreFiltered(): void
    {
        $row = $this->db->user->create(['data' => ['name' => 'Ada', 'email' => 'a@x.com', 'hacker' => 'x']]);

        self::assertFalse(isset($row['hacker']));
    }

    public function testMissingArgumentsThrow(): void
    {
        self::assertThrows(
            \Kabuto\Database\DatabaseException::class,
            fn () => $this->db->user->findUnique([]),
        );
        self::assertThrows(
            \Kabuto\Database\DatabaseException::class,
            fn () => $this->db->user->create([]),
        );
    }

    public function testConnectAndDisconnect(): void
    {
        $this->db->connect();
        self::assertTrue($this->connections->isConnected());

        $this->db->disconnect();
        self::assertFalse($this->connections->isConnected());

        $this->db->connect();
        self::assertTrue($this->connections->isConnected());
    }
}
