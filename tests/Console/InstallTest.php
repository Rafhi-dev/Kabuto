<?php

declare(strict_types=1);

namespace Kabuto\Tests\Console;

use Kabuto\Application;
use Kabuto\Console\Commands\InstallCommand;
use Kabuto\Console\Console;
use Kabuto\Tests\TestCase;

final class InstallTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/kabuto-install-' . uniqid();
        mkdir($this->basePath, 0777, true);
        file_put_contents($this->basePath . '/.env.example', "APP_NAME=App\nAPP_KEY=\n");
        ob_start();
    }

    public function testInstallCreatesEnvironmentKeyAndDirectories(): void
    {
        $command = new InstallCommand(new Console($this->basePath, new Application(basePath: $this->basePath)), []);
        self::assertSame(0, $command->handle());

        self::assertTrue(is_file($this->basePath . '/.env'));
        self::assertStringContains('APP_KEY=base64:', (string) file_get_contents($this->basePath . '/.env'));
        self::assertTrue(is_dir($this->basePath . '/storage/app/public'));
        self::assertTrue(is_dir($this->basePath . '/storage/app/private'));
        self::assertTrue(is_dir($this->basePath . '/database/migrations'));
        self::assertTrue(is_dir($this->basePath . '/app/Modules'));
    }

    public function testInstallDoesNotReplaceExistingKey(): void
    {
        $key = 'base64:' . base64_encode(random_bytes(32));
        file_put_contents($this->basePath . '/.env', "APP_KEY={$key}\n");
        $command = new InstallCommand(new Console($this->basePath, new Application(basePath: $this->basePath)), []);
        self::assertSame(0, $command->handle());
        self::assertStringContains('APP_KEY=' . $key, (string) file_get_contents($this->basePath . '/.env'));
    }

    protected function tearDown(): void
    {
        while (ob_get_level() > 0) ob_end_clean();
        $this->remove($this->basePath);
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) return;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        @rmdir($path);
    }
}
