<?php

declare(strict_types=1);

namespace Kabuto\Tests\Support;

use Kabuto\Support\Logger;
use Kabuto\Tests\TestCase;

final class LoggerTest extends TestCase
{
    public function testWritesJsonAndRedactsSensitiveContext(): void
    {
        $lines = [];
        $logger = new Logger(writer: static function (string $line) use (&$lines): void { $lines[] = $line; });
        $logger->info('user.login', [
            'request_id' => 'req_123',
            'email' => 'user@example.test',
            'password' => 'do-not-log',
            'nested' => ['access_token' => 'secret-token'],
        ]);

        $record = json_decode($lines[0], true);
        self::assertSame('info', $record['level']);
        self::assertSame('user.login', $record['message']);
        self::assertSame('req_123', $record['context']['request_id']);
        self::assertSame('[REDACTED]', $record['context']['password']);
        self::assertSame('[REDACTED]', $record['context']['nested']['access_token']);
        self::assertStringContains('user@example.test', $lines[0]);
        self::assertFalse(str_contains($lines[0], 'do-not-log'));
    }

    public function testMinimumLevelFiltersLowerPriorityRecords(): void
    {
        $lines = [];
        $logger = new Logger(minimumLevel: 'warning', writer: static function (string $line) use (&$lines): void { $lines[] = $line; });
        $logger->info('ignored');
        $logger->warning('kept');

        self::assertCount(1, $lines);
        self::assertStringContains('kept', $lines[0]);
    }
}
