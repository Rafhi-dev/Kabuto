<?php

declare(strict_types=1);

namespace Kabuto\Tests\Console;

use Kabuto\Application;
use Kabuto\Console\Commands\ContainerCheckCommand;
use Kabuto\Console\Console;
use Kabuto\Tests\TestCase;

final class ContainerCheckCommandTest extends TestCase
{
    protected function setUp(): void { ob_start(); }

    public function testReportsHealthyTarget(): void
    {
        $app = new Application();
        $status = (new ContainerCheckCommand(new Console(sys_get_temp_dir(), $app), [HealthyTarget::class]))->handle();
        $output = (string) ob_get_contents();
        self::assertSame(0, $status);
        self::assertStringContains('No dependency injection problems found.', $output);
    }

    public function testReportsMissingScalarDependency(): void
    {
        $app = new Application();
        $status = (new ContainerCheckCommand(new Console(sys_get_temp_dir(), $app), [BrokenTarget::class]))->handle();
        $output = (string) ob_get_contents();
        self::assertSame(1, $status);
        self::assertStringContains('Unable to autowire parameter', $output);
    }

    protected function tearDown(): void { while (ob_get_level() > 0) ob_end_clean(); }
}

final class HealthyTarget
{
    public function __construct(HealthyLeaf $leaf) {}
}

final class HealthyLeaf {}

final class BrokenTarget
{
    public function __construct(string $required) {}
}
