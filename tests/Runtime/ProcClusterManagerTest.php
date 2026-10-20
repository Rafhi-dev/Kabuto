<?php

declare(strict_types=1);

namespace Kabuto\Tests\Runtime;

use Kabuto\Runtime\ProcClusterManager;
use Kabuto\Tests\TestCase;

final class ProcClusterManagerTest extends TestCase
{
    public function testFindAvailablePortReturnsValidPort(): void
    {
        $port = ProcClusterManager::findAvailablePort('127.0.0.1', 9000);
        self::assertTrue($port >= 1024);
        self::assertTrue($port < 65535);
    }

    public function testFindAvailablePortAvoidsExcludedPorts(): void
    {
        $port1 = ProcClusterManager::findAvailablePort('127.0.0.1', 9500);
        $port2 = ProcClusterManager::findAvailablePort('127.0.0.1', 9500, [$port1]);

        self::assertTrue($port1 !== $port2);
        self::assertTrue($port2 > $port1);
    }

    public function testProcClusterManagerLifecycleState(): void
    {
        $manager = new ProcClusterManager(
            host: '127.0.0.1',
            publicPort: 8080,
            workerCount: 2,
            maxRequests: 50000,
            basePath: sys_get_temp_dir(),
            driver: 'native',
        );

        self::assertFalse($manager->isRunning());
        self::assertSame([], $manager->getWorkers());

        $manager->stop();
        self::assertFalse($manager->isRunning());
    }
}
