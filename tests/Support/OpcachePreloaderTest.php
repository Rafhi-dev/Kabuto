<?php

declare(strict_types=1);

namespace Kabuto\Tests\Support;

use Kabuto\Support\OpcachePreloader;
use Kabuto\Tests\TestCase;

final class OpcachePreloaderTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = dirname(__DIR__, 2);
    }

    public function testIsOpcacheActiveReturnsBoolean(): void
    {
        $active = OpcachePreloader::isOpcacheActive();
        $this->assertTrue(is_bool($active));
    }

    public function testGetFilesToPreloadFindsCoreFiles(): void
    {
        $files = OpcachePreloader::getFilesToPreload(
            $this->basePath,
            ['core'],
            ['#(/|\\\\)tests(/|\\\\)#']
        );

        $this->assertTrue(count($files) > 10);

        // Verify all returned items are real PHP files
        foreach ($files as $file) {
            $this->assertTrue(is_file($file));
            $this->assertTrue(str_ends_with(strtolower($file), '.php'));
            $this->assertFalse(str_contains($file, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR));
        }
    }

    public function testIsExcludedRecognizesRegexAndStringSubpaths(): void
    {
        $this->assertTrue(OpcachePreloader::isExcluded(
            '/var/www/tests/Runtime/DriverTest.php',
            ['#(/|\\\\)tests(/|\\\\)#']
        ));

        $this->assertTrue(OpcachePreloader::isExcluded(
            '/var/www/core/Runtime/Drivers/OpenSwooleDriver.php',
            ['OpenSwooleDriver.php']
        ));

        $this->assertFalse(OpcachePreloader::isExcluded(
            '/var/www/core/Application.php',
            ['#(/|\\\\)tests(/|\\\\)#', 'OpenSwooleDriver.php']
        ));
    }

    public function testPreloadGracefulExecution(): void
    {
        $result = OpcachePreloader::preload($this->basePath, [
            'enabled' => true,
            'paths' => ['core/Support'],
            'exclude' => [],
        ]);

        $this->assertTrue(is_array($result));
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('compiled', $result);
        $this->assertArrayHasKey('skipped', $result);
        $this->assertArrayHasKey('errors', $result);

        $this->assertTrue($result['total'] > 0);
        $this->assertSame($result['total'], $result['compiled'] + $result['skipped']);
    }

    public function testPreloadDisabledReturnsZero(): void
    {
        $result = OpcachePreloader::preload($this->basePath, [
            'enabled' => false,
        ]);

        $this->assertSame(0, $result['total']);
        $this->assertSame(0, $result['compiled']);
        $this->assertSame(0, $result['skipped']);
        $this->assertArrayHasKey('preloader', $result['errors']);
    }

    public function testPreloadRootScriptExists(): void
    {
        $scriptPath = $this->basePath . DIRECTORY_SEPARATOR . 'preload.php';
        $this->assertTrue(is_file($scriptPath));
        $this->assertTrue(is_readable($scriptPath));

        $content = (string) file_get_contents($scriptPath);
        $this->assertStringContains('OpcachePreloader::preload', $content);
    }
}
