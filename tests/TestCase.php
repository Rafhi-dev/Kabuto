<?php

declare(strict_types=1);

namespace Kabuto\Tests;

/**
 * Minimal zero-dependency test case with a PHPUnit-compatible assertion API.
 * When PHPUnit becomes available, tests can migrate by extending
 * PHPUnit\Framework\TestCase instead — method names follow the `test*`
 * convention and assertions mirror PHPUnit's.
 */
abstract class TestCase
{
    private int $assertions = 0;

    /** @var list<string> */
    private array $failures = [];

    public function assertions(): int
    {
        return $this->assertions;
    }

    /** @return list<string> */
    public function failures(): array
    {
        return $this->failures;
    }

    public function recordFailure(string $message): void
    {
        $this->failures[] = $message;
    }

    public static function assertTrue(mixed $condition, string $message = ''): void
    {
        self::current()->check($condition === true, $message !== '' ? $message : 'Failed asserting that value is true.');
    }

    public static function assertFalse(mixed $condition, string $message = ''): void
    {
        self::current()->check($condition === false, $message !== '' ? $message : 'Failed asserting that value is false.');
    }

    public static function assertNull(mixed $value, string $message = ''): void
    {
        self::current()->check($value === null, $message !== '' ? $message : 'Failed asserting that value is null.');
    }

    public static function assertNotFalse(mixed $value, string $message = ''): void
    {
        self::current()->check($value !== false, $message !== '' ? $message : 'Failed asserting that value is not false.');
    }

    public static function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::current()->check(
            $expected === $actual,
            $message !== '' ? $message : sprintf(
                "Failed asserting that two values are identical.\nExpected: %s\nActual:   %s",
                self::export($expected),
                self::export($actual),
            ),
        );
    }

    public static function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::current()->check(
            $expected == $actual,
            $message !== '' ? $message : sprintf(
                "Failed asserting that two values are equal.\nExpected: %s\nActual:   %s",
                self::export($expected),
                self::export($actual),
            ),
        );
    }

    public static function assertCount(int $expected, array $value, string $message = ''): void
    {
        self::current()->check(
            count($value) === $expected,
            $message !== '' ? $message : sprintf('Failed asserting count %d matches actual %d.', $expected, count($value)),
        );
    }

    public static function assertContains(mixed $needle, array $haystack, string $message = ''): void
    {
        self::current()->check(
            in_array($needle, $haystack, true),
            $message !== '' ? $message : sprintf('Failed asserting that %s is in array.', self::export($needle)),
        );
    }

    public static function assertArrayHasKey(string|int $key, array $value, string $message = ''): void
    {
        self::current()->check(
            array_key_exists($key, $value),
            $message !== '' ? $message : sprintf('Failed asserting that array has key %s.', self::export($key)),
        );
    }

    public static function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        self::current()->check(
            str_contains($haystack, $needle),
            $message !== '' ? $message : sprintf('Failed asserting that "%s" contains "%s".', $haystack, $needle),
        );
    }

    public static function assertInstanceOf(string $expected, mixed $actual, string $message = ''): void
    {
        self::current()->check(
            $actual instanceof $expected,
            $message !== '' ? $message : sprintf('Failed asserting that %s is instance of %s.', self::export($actual), $expected),
        );
    }

    /**
     * @param class-string<\Throwable> $expected
     */
    public static function assertThrows(string $expected, callable $callback, string $message = ''): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            self::current()->check(
                $e instanceof $expected,
                $message !== '' ? $message : sprintf('Failed asserting exception %s, got %s.', $expected, $e::class),
            );
            return;
        }

        self::current()->check(false, $message !== '' ? $message : sprintf('Failed asserting that %s is thrown.', $expected));
    }

    private function check(bool $passed, string $message): void
    {
        $this->assertions++;

        if (!$passed) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
            $caller = $trace[2] ?? ['class' => '?', 'function' => '?'];
            $this->failures[] = sprintf(
                '%s::%s — %s',
                $caller['class'] ?? '?',
                $caller['function'] ?? '?',
                $message,
            );
        }
    }

    private static function export(mixed $value): string
    {
        if (is_string($value)) {
            return "'" . $value . "'";
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_null($value)) {
            return 'null';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: get_debug_type($value);
    }

    private static function current(): self
    {
        return Runner::active();
    }
}
