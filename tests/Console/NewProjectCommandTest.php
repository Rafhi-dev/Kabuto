<?php

declare(strict_types=1);

namespace Kabuto\Tests\Console;

use Kabuto\Application;
use Kabuto\Console\Commands\NewProjectCommand;
use Kabuto\Console\Console;
use Kabuto\Tests\TestCase;

final class NewProjectCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/kabuto-new-' . uniqid();
        mkdir($this->basePath, 0777, true);
        ob_start();
    }

    public function testNewProjectContainsComposerAndInstallableSkeleton(): void
    {
        $command = new NewProjectCommand(new Console($this->basePath, new Application(basePath: $this->basePath)), ['demo-app']);
        self::assertSame(0, $command->handle());
        $project = $this->basePath . '/DemoApp';
        self::assertTrue(is_file($project . '/composer.json'));
        self::assertTrue(is_file($project . '/kabuto'));
        self::assertTrue(is_file($project . '/README.md'));
        $composer = json_decode((string) file_get_contents($project . '/composer.json'), true);
        self::assertSame('project', $composer['type']);
        self::assertSame('php kabuto install', $composer['scripts']['install']);
        self::assertTrue(is_file($project . '/storage/logs/.gitkeep'));
    }

    public function testMinimalStarterOmitsDefaultModel(): void
    {
        $command = new NewProjectCommand(new Console($this->basePath, new Application(basePath: $this->basePath)), ['minimal-app', '--starter=minimal']);
        self::assertSame(0, $command->handle());
        $schema = (string) file_get_contents($this->basePath . '/MinimalApp/database/schema.php');
        $composer = json_decode((string) file_get_contents($this->basePath . '/MinimalApp/composer.json'), true);
        self::assertFalse(str_contains($schema, "'User'"));
        self::assertSame('A Kabuto minimal application.', $composer['description']);
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
