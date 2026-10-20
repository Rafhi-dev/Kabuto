<?php

declare(strict_types=1);

namespace Kabuto\Tests\Observability;

use Kabuto\Observability\HealthRegistry;
use Kabuto\Tests\TestCase;

final class HealthRegistryTest extends TestCase
{
    public function testLiveOnlyRunsNonReadinessChecks(): void
    {
        $calls = [];
        $health = new HealthRegistry();
        $health->check('process', static function () use (&$calls): bool {
            $calls[] = 'process';
            return true;
        }, readiness: false);
        $health->check('database', static function () use (&$calls): bool {
            $calls[] = 'database';
            return true;
        });

        $live = $health->live();
        self::assertSame('ok', $live['status']);
        self::assertSame(['process'], $calls);
        self::assertArrayHasKey('process', $live['checks']);
    }

    public function testReadinessCapturesFailuresWithoutThrowing(): void
    {
        $health = new HealthRegistry();
        $health->check('database', static function (): bool {
            throw new \RuntimeException('database unavailable');
        });
        $result = $health->ready();

        self::assertSame('failed', $result['status']);
        self::assertFalse($result['checks']['database']['ok']);
        self::assertSame('database unavailable', $result['checks']['database']['error']);
    }
}
