<?php

declare(strict_types=1);

namespace Kabuto\Tests\Runtime;

use Kabuto\Application;
use Kabuto\Database\ConnectionManager;
use Kabuto\Database\DB;
use Kabuto\Tests\TestCase;

final class ApplicationTest extends TestCase
{
    public function testBootRegistersDatabaseAutomatically(): void
    {
        $app = new Application(basePath: dirname(__DIR__, 2));
        $app->boot();

        $container = $app->container();

        self::assertTrue($container->has(ConnectionManager::class));
        self::assertTrue($container->has(DB::class));

        $db1 = $container->get(DB::class);
        $db2 = $container->get(DB::class);

        self::assertTrue($db1 instanceof DB);
        self::assertSame($db1, $db2);

        $cm1 = $container->get(ConnectionManager::class);
        $cm2 = $container->get(ConnectionManager::class);

        self::assertTrue($cm1 instanceof ConnectionManager);
        self::assertSame($cm1, $cm2);
    }
}
