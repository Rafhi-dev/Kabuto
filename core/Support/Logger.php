<?php

declare(strict_types=1);

namespace Kabuto\Support;

use Throwable;

final class Logger
{
    private const LEVELS = ['debug' => 10, 'info' => 20, 'warning' => 30, 'error' => 40];

    /** @param callable(string):void|null $writer */
    public function __construct(
        private readonly string $channel = 'app',
        private readonly string $minimumLevel = 'info',
        private readonly ?string $path = null,
        private readonly mixed $writer = null,
    ) {
    }

    /** @param array<string,mixed> $context */
    public function debug(string $message, array $context = []): void { $this->write('debug', $message, $context); }

    /** @param array<string,mixed> $context */
    public function info(string $message, array $context = []): void { $this->write('info', $message, $context); }

    /** @param array<string,mixed> $context */
    public function warning(string $message, array $context = []): void { $this->write('warning', $message, $context); }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = []): void { $this->write('error', $message, $context); }

    /** @param array<string,mixed> $context */
    public function exception(Throwable $exception, array $context = []): void
    {
        $this->error($exception->getMessage(), array_merge($context, [
            'exception' => $exception::class,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]));
    }

    /** @param array<string,mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        $minimum = self::LEVELS[strtolower($this->minimumLevel)] ?? self::LEVELS['info'];
        if ((self::LEVELS[$level] ?? 20) < $minimum) return;

        $record = [
            'timestamp' => gmdate('c'),
            'level' => $level,
            'channel' => $this->channel,
            'message' => $message,
            'context' => $this->sanitize($context),
        ];
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;

        if (is_callable($this->writer)) {
            ($this->writer)($line);
            return;
        }

        $path = $this->path ?? (function_exists('base_path') ? base_path('storage/logs/kabuto.log') : getcwd() . '/storage/logs/kabuto.log');
        $directory = dirname($path);
        if (!is_dir($directory)) @mkdir($directory, 0755, true);
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    private function sanitize(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match('/pass(word)?|secret|token|authorization|cookie|api[_-]?key|private[_-]?key/i', $key)) return '[REDACTED]';
        if (is_array($value)) {
            $result = [];
            foreach ($value as $childKey => $childValue) $result[(string) $childKey] = $this->sanitize($childValue, (string) $childKey);
            return $result;
        }
        if (is_object($value)) return '[object ' . $value::class . ']';
        if (is_resource($value)) return '[resource]';
        return $value;
    }
}
