<?php

declare(strict_types=1);

namespace Kabuto\Tests\Console;

use Kabuto\Application;
use Kabuto\Console\Commands\OpenApiGenerateCommand;
use Kabuto\Console\Console;
use Kabuto\Tests\TestCase;

final class OpenApiGenerateCommandTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/kabuto-openapi-' . uniqid();
        mkdir($this->base, 0777, true);
        ob_start();
    }

    public function testWritesOpenApiJsonToConfiguredOutput(): void
    {
        $app = new Application(basePath: $this->base);
        $app->get('/health', static fn (): array => ['ok' => true]);
        $output = $this->base . '/storage/spec.json';
        $command = new OpenApiGenerateCommand(new Console($this->base, $app), ['--output=' . $output, '--title=Health API']);
        self::assertSame(0, $command->handle());
        self::assertTrue(is_file($output));
        $document = json_decode((string) file_get_contents($output), true);
        self::assertSame('Health API', $document['info']['title']);
        self::assertArrayHasKey('/health', $document['paths']);
    }

    protected function tearDown(): void
    {
        while (ob_get_level() > 0) ob_end_clean();
        $this->remove($this->base);
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) return;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        @rmdir($path);
    }
}
