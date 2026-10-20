<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use RuntimeException;

final class DbSeedCommand extends DatabaseCommand
{
    public function name(): string
    {
        return 'db:seed';
    }

    public function description(): string
    {
        return 'Seed the database with records from database/seed.php or a specific seeder class';
    }

    public function handle(): int
    {
        $class = $this->option('class');
        $basePath = $this->console->basePath();

        if ($class !== null && trim($class) !== '') {
            return $this->runClassSeeder(trim($class), $basePath);
        }

        return $this->runDefaultSeedFile($basePath);
    }

    private function runDefaultSeedFile(string $basePath): int
    {
        $seedFile = $basePath . '/database/seed.php';

        if (!is_file($seedFile)) {
            $this->error(sprintf('Default seed file not found: %s', $seedFile));
            $this->info('Create database/seed.php to seed your database with initial data.');
            return 1;
        }

        $this->info(sprintf('Running seeder: %s', $seedFile));

        $start = microtime(true);
        $this->executeFile($seedFile);
        $duration = round((microtime(true) - $start) * 1000, 2);

        $this->success(sprintf('Database seeded successfully in %s ms.', $duration));
        return 0;
    }

    private function runClassSeeder(string $className, string $basePath): int
    {
        // Try file path first if passed with .php
        if (str_ends_with($className, '.php') && is_file($basePath . '/' . $className)) {
            $file = $basePath . '/' . $className;
            $this->info(sprintf('Running seeder file: %s', $file));
            $this->executeFile($file);
            $this->success(sprintf('Seeded %s successfully.', $className));
            return 0;
        }

        // Try App\Database\Seeders\<Class> or Database\Seeders\<Class>
        $candidates = [
            $className,
            'App\\Database\\Seeders\\' . $className,
            'Database\\Seeders\\' . $className,
        ];

        $targetClass = null;
        foreach ($candidates as $cand) {
            if (class_exists($cand)) {
                $targetClass = $cand;
                break;
            }
        }

        if ($targetClass === null) {
            // Check if file exists in database/seeders/<Class>.php
            $classFile = $basePath . '/database/seeders/' . $className . '.php';
            if (is_file($classFile)) {
                require_once $classFile;
                foreach ($candidates as $cand) {
                    if (class_exists($cand)) {
                        $targetClass = $cand;
                        break;
                    }
                }
            }
        }

        if ($targetClass === null) {
            $this->error(sprintf('Seeder class [%s] not found.', $className));
            return 1;
        }

        $this->info(sprintf('Running seeder: %s', $targetClass));

        $start = microtime(true);
        $instance = new $targetClass();

        if (method_exists($instance, 'run')) {
            $instance->run();
        } elseif (is_callable($instance)) {
            $instance();
        } else {
            throw new RuntimeException(sprintf('Seeder [%s] must define a run() method or be invokable.', $targetClass));
        }

        $duration = round((microtime(true) - $start) * 1000, 2);
        $this->success(sprintf('Seeded [%s] successfully in %s ms.', $targetClass, $duration));

        return 0;
    }

    private function executeFile(string $file): void
    {
        $app = $this->console->app();
        $container = $app->container();

        (static function (string $__file, $app, $container): void {
            require $__file;
        })($file, $app, $container);
    }
}
