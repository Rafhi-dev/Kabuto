<?php

declare(strict_types=1);

namespace Kabuto\Tests\Database;

use Kabuto\Database\DatabaseException;
use Kabuto\Tests\TestCase;
use PDOException;

final class DatabaseExceptionTest extends TestCase
{
    public function testSqliteUniqueViolationIsNormalized(): void
    {
        $e = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: users.email');
        $e->errorInfo = ['23000', 19, 'UNIQUE constraint failed: users.email'];

        $wrapped = DatabaseException::wrap($e, 'sqlite', 'insert');

        self::assertSame(DatabaseException::CATEGORY_UNIQUE, $wrapped->category());
        self::assertStringContains('[unique-constraint]', $wrapped->getMessage());
        self::assertStringContains('insert', $wrapped->getMessage());
        self::assertInstanceOf(PDOException::class, $wrapped->getPrevious());
    }

    public function testPgsqlUniqueViolationIsNormalized(): void
    {
        $e = new PDOException('SQLSTATE[23505]: Unique violation');
        $e->errorInfo = ['23505', null, 'Key (email) already exists.'];

        self::assertSame(DatabaseException::CATEGORY_UNIQUE, DatabaseException::wrap($e, 'pgsql')->category());
    }

    public function testMysqlForeignKeyViolationIsNormalized(): void
    {
        $e = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row');
        $e->errorInfo = ['23000', 1452, 'Cannot add or update a child row'];

        self::assertSame(DatabaseException::CATEGORY_FOREIGN_KEY, DatabaseException::wrap($e, 'mysql')->category());
    }

    public function testNotNullViolationIsNormalized(): void
    {
        $e = new PDOException('SQLSTATE[23502]: Not-null violation');
        $e->errorInfo = ['23502', null, 'null value in column "name"'];

        self::assertSame(DatabaseException::CATEGORY_NOT_NULL, DatabaseException::wrap($e, 'pgsql')->category());
    }

    public function testSyntaxAndConnectionAndLockAreNormalized(): void
    {
        $syntax = new PDOException('SQLSTATE[42601]: Syntax error');
        $syntax->errorInfo = ['42601', null, 'syntax error at or near'];
        self::assertSame(DatabaseException::CATEGORY_SYNTAX, DatabaseException::wrap($syntax, 'pgsql')->category());

        $mysqlSyntax = new PDOException('SQLSTATE[42000]: Syntax error: 1064');
        $mysqlSyntax->errorInfo = ['42000', 1064, 'You have an error in your SQL syntax'];
        self::assertSame(DatabaseException::CATEGORY_SYNTAX, DatabaseException::wrap($mysqlSyntax, 'mysql')->category());

        $conn = new PDOException('SQLSTATE[HY000] [2002] Connection refused');
        $conn->errorInfo = ['HY000', 2002, 'Connection refused'];
        self::assertSame(DatabaseException::CATEGORY_CONNECTION, DatabaseException::wrap($conn, 'mysql')->category());

        $locked = new PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
        $locked->errorInfo = ['HY000', 5, 'database is locked'];
        self::assertSame(DatabaseException::CATEGORY_LOCKED, DatabaseException::wrap($locked, 'sqlite')->category());
    }

    public function testDeadlockIsLockTimeout(): void
    {
        $e = new PDOException('SQLSTATE[40001]: Serialization failure');
        $e->errorInfo = ['40001', null, 'could not serialize access'];

        self::assertSame(DatabaseException::CATEGORY_LOCKED, DatabaseException::wrap($e, 'pgsql')->category());
    }

    public function testUnknownErrorsKeepCategoryUnknown(): void
    {
        $e = new PDOException('something odd happened');
        $e->errorInfo = ['HY000', 1, 'something odd happened'];

        self::assertSame(DatabaseException::CATEGORY_UNKNOWN, DatabaseException::wrap($e, 'sqlite')->category());
    }

    public function testIsCategoryHelper(): void
    {
        $e = new PDOException('UNIQUE constraint failed: t.c');
        $e->errorInfo = ['23000', 19, 'UNIQUE constraint failed: t.c'];
        $wrapped = DatabaseException::wrap($e, 'sqlite');

        self::assertTrue(DatabaseException::isCategory($wrapped, DatabaseException::CATEGORY_UNIQUE));
        self::assertFalse(DatabaseException::isCategory($wrapped, DatabaseException::CATEGORY_FOREIGN_KEY));
    }

    public function testContextlessRerwrapReturnsSameInstance(): void
    {
        $e = new PDOException('UNIQUE constraint failed: t.c');
        $e->errorInfo = ['23000', 19, 'UNIQUE constraint failed: t.c'];
        $wrapped = DatabaseException::wrap($e, 'sqlite');

        self::assertSame($wrapped, DatabaseException::wrap($wrapped, 'sqlite'));
    }
}
