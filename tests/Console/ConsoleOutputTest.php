<?php

declare(strict_types=1);

namespace Kabuto\Tests\Console;

use Kabuto\Application;
use Kabuto\Console\Commands\DoctorCommand;
use Kabuto\Console\Commands\RouteListCommand;
use Kabuto\Console\Console;
use Kabuto\Tests\TestCase;

final class ConsoleOutputTest extends TestCase
{
    protected function setUp(): void { ob_start(); }

    public function testJsonListsCommandsAndAliases(): void
    {
        $console = new Console(sys_get_temp_dir(), new Application());
        $console->add(DoctorCommand::class, ['check']);
        $console->run(['kabuto', 'list', '--json']);
        $data = json_decode((string) ob_get_contents(), true);
        self::assertSame('Kabuto', $data['application']);
        self::assertSame('doctor', $data['commands'][0]['name']);
        self::assertContains('check', $data['commands'][0]['aliases']);
    }

    public function testJsonHelpReturnsMetadata(): void
    {
        $console = new Console(sys_get_temp_dir(), new Application());
        $console->add(RouteListCommand::class);
        $console->run(['kabuto', 'help', 'route:list', '--json']);
        $data = json_decode((string) ob_get_contents(), true);
        self::assertSame('route:list', $data['name']);
        self::assertStringContains('middleware', strtolower($data['description']));
    }

    public function testQuietSuppressesOutput(): void
    {
        $console = new Console(sys_get_temp_dir(), new Application());
        $console->add(DoctorCommand::class);
        $console->run(['kabuto', 'list', '--quiet']);
        self::assertSame('', (string) ob_get_contents());
    }

    protected function tearDown(): void { while (ob_get_level() > 0) ob_end_clean(); }
}
