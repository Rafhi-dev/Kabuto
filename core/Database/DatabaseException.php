<?php

declare(strict_types=1);

namespace Kabuto\Database;

/**
 * Normalized database error. Every driver failure is funneled through
 * `DatabaseException::wrap()` so callers get a stable `category()` regardless
 * of SQLite, MySQL, or PostgreSQL error codes.
 */
final class DatabaseException extends \RuntimeException
{
    public const CATEGORY_UNIQUE = 'unique-constraint';
    public const CATEGORY_FOREIGN_KEY = 'foreign-key-constraint';
    public const CATEGORY_NOT_NULL = 'not-null-constraint';
    public const CATEGORY_CONSTRAINT = 'constraint';
    public const CATEGORY_SYNTAX = 'syntax';
    public const CATEGORY_CONNECTION = 'connection';
    public const CATEGORY_LOCKED = 'lock-timeout';
    public const CATEGORY_UNKNOWN = 'unknown';

    private string $category = self::CATEGORY_UNKNOWN;

    public function category(): string
    {
        return $this->category;
    }

    public static function isCategory(\Throwable $e, string $category): bool
    {
        return $e instanceof self && $e->category() === $category;
    }

    /**
     * Wrap any driver error into a normalized DatabaseException.
     *
     * @param string $driver  sqlite | mysql | pgsql (aliases accepted)
     * @param string $context short operation label, e.g. "connect db"
     */
    public static function wrap(\Throwable $e, string $driver = '', string $context = ''): self
    {
        if ($e instanceof self && $context === '') {
            return $e;
        }

        $category = self::categorize($e, DriverCapabilities::normalize($driver));
        $prefix = '[' . $category . ']';
        if ($context !== '') {
            $prefix .= ' ' . $context . ':';
        }

        $wrapped = new self(trim($prefix . ' ' . $e->getMessage()), 0, $e);
        $wrapped->category = $category;

        return $wrapped;
    }

    public static function categorize(\Throwable $e, string $driver = ''): string
    {
        $sqlstate = '';
        $driverCode = 0;
        if ($e instanceof \PDOException && isset($e->errorInfo) && is_array($e->errorInfo)) {
            $sqlstate = (string) ($e->errorInfo[0] ?? '');
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
        }

        $message = strtolower($e->getMessage());

        return match (true) {
            // Unique / duplicate
            $sqlstate === '23505',
            $driverCode === 1062,
            str_contains($message, 'unique constraint'),
            str_contains($message, 'duplicate entry'),
            str_contains($message, 'duplicate key') => self::CATEGORY_UNIQUE,

            // Foreign key
            $sqlstate === '23503',
            in_array($driverCode, [1451, 1452], true),
            str_contains($message, 'foreign key constraint') => self::CATEGORY_FOREIGN_KEY,

            // Not null
            $sqlstate === '23502',
            $driverCode === 1048,
            str_contains($message, 'not null constraint') => self::CATEGORY_NOT_NULL,

            // Serialization failure / deadlock / busy database
            in_array($sqlstate, ['40001', '40P01'], true),
            in_array($driverCode, [1205, 1213], true),
            str_contains($message, 'database is locked'),
            str_contains($message, 'deadlock') => self::CATEGORY_LOCKED,

            // Connection problems
            str_starts_with($sqlstate, '08'),
            in_array($driverCode, [1040, 1044, 1045, 1129, 1130, 1203, 2002, 2003, 2005, 2006, 2013], true),
            str_contains($message, 'unable to open database'),
            str_contains($message, 'server has gone away') => self::CATEGORY_CONNECTION,

            // Syntax / unknown statement
            str_starts_with($sqlstate, '42'),
            $driverCode === 1064,
            str_contains($message, 'syntax error') => self::CATEGORY_SYNTAX,

            // Remaining integrity-class errors
            str_starts_with($sqlstate, '23') => self::CATEGORY_CONSTRAINT,

            default => self::CATEGORY_UNKNOWN,
        };
    }
}
