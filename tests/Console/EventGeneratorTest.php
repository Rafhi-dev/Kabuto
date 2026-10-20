<?php

declare(strict_types=1);

namespace Kabuto\Tests\Console;

use Kabuto\Application;
use Kabuto\Console\Commands\MakeEventCommand;
use Kabuto\Console\Commands\MakeListenerCommand;
use Kabuto\Console\Console;
use Kabuto\Tests\TestCase;

final class EventGeneratorTest extends TestCase
{
    private string $base;
    protected function setUp(): void { $this->base = sys_get_temp_dir() . '/kabuto-events-' . uniqid(); mkdir($this->base, 0777, true); ob_start(); }

    public function testGeneratesEventAndListener(): void
    {
        $console = new Console($this->base, new Application(basePath: $this->base));
        self::assertSame(0, (new MakeEventCommand($console, ['UserRegistered']))->handle());
        self::assertSame(0, (new MakeListenerCommand($console, ['UserRegistered']))->handle());
        self::assertTrue(is_file($this->base . '/app/Events/UserRegistered.php'));
        self::assertTrue(is_file($this->base . '/app/Listeners/UserRegisteredListener.php'));
        self::assertStringContains('implements Event', (string) file_get_contents($this->base . '/app/Events/UserRegistered.php'));
        self::assertStringContains('implements Listener', (string) file_get_contents($this->base . '/app/Listeners/UserRegisteredListener.php'));
    }

    protected function tearDown(): void { while (ob_get_level() > 0) ob_end_clean(); $this->remove($this->base); }
    private function remove(string $path): void { if (!is_dir($path)) return; $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST); foreach ($it as $file) $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname()); @rmdir($path); }
}
