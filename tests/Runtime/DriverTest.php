<?php

declare(strict_types=1);

namespace Kabuto\Tests\Runtime;

use Kabuto\Application;
use Kabuto\Runtime\Drivers\NativeDriver;
use Kabuto\Runtime\Drivers\RevoltDriver;
use Kabuto\Runtime\WorkerServer;
use Kabuto\Tests\TestCase;
use RuntimeException;

final class DriverTest extends TestCase
{
    public function testNativeDriverProperties(): void
    {
        $driver = new NativeDriver();
        self::assertTrue(NativeDriver::isSupported());
        self::assertSame('Native (stream_select)', $driver->name());
    }

    public function testResolveNativeDriver(): void
    {
        $driver = WorkerServer::resolveDriver('native');
        self::assertInstanceOf(NativeDriver::class, $driver);
    }

    public function testResolveAutoDriverFallbackToNative(): void
    {
        if (!class_exists(\Revolt\EventLoop::class)) {
            $driver = WorkerServer::resolveDriver('auto');
            self::assertInstanceOf(NativeDriver::class, $driver);
        } else {
            $driver = WorkerServer::resolveDriver('auto');
            self::assertInstanceOf(RevoltDriver::class, $driver);
        }
    }

    public function testResolveExplicitRevoltWithoutPackageThrowsException(): void
    {
        if (!class_exists(\Revolt\EventLoop::class)) {
            self::assertThrows(RuntimeException::class, function () {
                WorkerServer::resolveDriver('revolt');
            });
        } else {
            $driver = WorkerServer::resolveDriver('revolt');
            self::assertInstanceOf(RevoltDriver::class, $driver);
            self::assertSame('Revolt (Fiber EventLoop)', $driver->name());
        }
    }

    public function testWorkerServerConfiguresDriverCorrectly(): void
    {
        $app = new Application();
        $server = new WorkerServer($app, driver: 'native');

        self::assertSame('Native (stream_select)', $server->getDriverName());
        self::assertInstanceOf(NativeDriver::class, $server->getDriver());
    }
}
