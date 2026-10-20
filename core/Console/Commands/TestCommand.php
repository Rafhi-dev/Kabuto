<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\Command;
use Kabuto\Tests\Runner;

final class TestCommand extends Command
{
    public function name(): string
    {
        return 'test';
    }

    public function description(): string
    {
        return 'Run the zero-dependency test suite';
    }

    public function handle(): int
    {
        $basePath = $this->console->basePath();
        $testsPath = $basePath . '/tests';

        if (!is_dir($testsPath)) {
            $this->error('Tests directory not found: ' . $testsPath);
            return 1;
        }

        require_once $testsPath . '/TestCase.php';
        require_once $testsPath . '/Runner.php';

        spl_autoload_register(static function (string $class) use ($basePath): void {
            $prefix = 'Kabuto\\Tests\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $file = $basePath . '/tests/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });

        $filter = $this->option('filter') ?? $this->argument(0);

        return (new Runner())->run($testsPath, $filter);
    }
}
