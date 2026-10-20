<?php

declare(strict_types=1);

namespace Kabuto\Tests\Console;

use Kabuto\Application;
use Kabuto\Console\Commands\DoctorCommand;
use Kabuto\Console\Console;
use Kabuto\Tests\TestCase;

final class DoctorTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/kabuto-doctor-' . uniqid();
        mkdir($this->basePath . '/config', 0777, true);
        mkdir($this->basePath . '/storage', 0777, true);
        file_put_contents($this->basePath . '/.env', "APP_KEY=base64:" . base64_encode(random_bytes(32)) . "\nJWT_SECRET=" . str_repeat('j', 32) . "\nAPP_ENV=local\nAPP_DEBUG=false\n");
        file_put_contents($this->basePath . '/.env', "APP_KEY=base64:" . base64_encode(random_bytes(32)) . "\nJWT_SECRET=" . str_repeat('j', 32) . "\nAPP_ENV=local\nAPP_DEBUG=false\nDB_DRIVER=sqlite\nDB_PATH=:memory:\n");
        ob_start();
    }

    public function testHealthyProjectReturnsSuccess(): void
    {
        $command = new DoctorCommand(new Console($this->basePath, new Application(basePath: $this->basePath)), []);
        $status = $command->handle();
        $output = (string) ob_get_contents();

        self::assertSame(extension_loaded('openssl') ? 0 : 1, $status);
        self::assertStringContains(extension_loaded('openssl') ? 'Doctor found no problems.' : 'Doctor found 1 problem(s).', $output);
        self::assertStringContains('Database connection', $output);
    }

    public function testMissingEnvReturnsFailureWithoutBootingApplication(): void
    {
        unlink($this->basePath . '/.env');
        $command = new DoctorCommand(new Console($this->basePath, new Application(basePath: $this->basePath)), []);
        $status = $command->handle();

        self::assertSame(1, $status);
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
