<?php

declare(strict_types=1);

namespace Kabuto\Tests\Console;

use Kabuto\Application;
use Kabuto\Console\Commands\DbSeedCommand;
use Kabuto\Console\Console;
use Kabuto\Tests\TestCase;

final class DbSeedTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/kabuto-seed-test-' . uniqid();
        mkdir($this->basePath . '/database', 0777, true);
        ob_start();
    }

    public function testRunsDefaultSeedFile(): void
    {
        $flagFile = $this->basePath . '/seeded.txt';
        $seedCode = "<?php file_put_contents('" . addslashes($flagFile) . "', 'seeded_ok');\n";
        file_put_contents($this->basePath . '/database/seed.php', $seedCode);

        // Minimal schema.php for DatabaseCommand
        file_put_contents($this->basePath . '/database/schema.php', <<<'PHP'
<?php
use Kabuto\Database\Schema;
return Schema::define([
    'datasource' => [
        'provider' => 'sqlite',
        'url' => ':memory:',
    ],
    'models' => [
        'Item' => [
            'table' => 'items',
            'fields' => ['id' => 'Int @id @default(autoincrement())'],
        ],
    ],
]);
PHP);

        $app = new Application(basePath: $this->basePath);
        $console = new Console($this->basePath, $app);

        $status = (new DbSeedCommand($console, []))->handle();

        self::assertSame(0, $status);
        self::assertTrue(is_file($flagFile));
        self::assertSame('seeded_ok', file_get_contents($flagFile));
    }

    public function testMissingSeedFileReturnsError(): void
    {
        file_put_contents($this->basePath . '/database/schema.php', <<<'PHP'
<?php
use Kabuto\Database\Schema;
return Schema::define([
    'datasource' => ['provider' => 'sqlite', 'url' => ':memory:'],
    'models' => ['Item' => ['table' => 'items', 'fields' => ['id' => 'Int @id']]],
]);
PHP);

        $app = new Application(basePath: $this->basePath);
        $console = new Console($this->basePath, $app);

        $status = (new DbSeedCommand($console, []))->handle();
        self::assertSame(1, $status);
    }

    protected function tearDown(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $this->remove($this->basePath);
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($path);
    }
}
