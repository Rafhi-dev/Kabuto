<?php

declare(strict_types=1);

namespace Kabuto\Tests\Console;

use Kabuto\Application;
use Kabuto\Console\Commands\RouteListCommand;
use Kabuto\Console\Console;
use Kabuto\Middleware\RequestId;
use Kabuto\Tests\TestCase;

final class RouteListCommandTest extends TestCase
{
    protected function setUp(): void { ob_start(); }

    public function testVerboseListsMiddleware(): void
    {
        $app = new Application();
        $app->get('/users/:id', static fn () => [], [RequestId::class]);
        $console = new Console(sys_get_temp_dir(), $app);
        $status = (new RouteListCommand($console, ['--verbose']))->handle();
        $output = (string) ob_get_contents();

        self::assertSame(0, $status);
        self::assertStringContains('MIDDLEWARE', $output);
        self::assertStringContains(RequestId::class, $output);
        self::assertStringContains('/users/:id', $output);
    }

    public function testDefaultOutputRemainsCompact(): void
    {
        $app = new Application();
        $app->get('/health', static fn () => []);
        (new RouteListCommand(new Console(sys_get_temp_dir(), $app), []))->handle();
        $output = (string) ob_get_contents();
        self::assertStringContains('METHOD', $output);
        self::assertFalse(str_contains($output, 'MIDDLEWARE'));
    }

    protected function tearDown(): void { while (ob_get_level() > 0) ob_end_clean(); }
}
