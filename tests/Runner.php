<?php

declare(strict_types=1);

namespace Kabuto\Tests;

/**
 * Discovers `*Test.php` files under tests/, runs every public `test*`
 * method and reports results. Zero dependencies.
 */
final class Runner
{
    private static ?TestCase $active = null;

    /** @var list<array{file:string,class:string,method:string,error:string}> */
    private array $errors = [];

    private int $tests = 0;

    private int $assertions = 0;

    /** @var list<string> */
    private array $failures = [];

    public static function active(): TestCase
    {
        if (self::$active === null) {
            throw new \LogicException('No active test case.');
        }

        return self::$active;
    }

    public function run(string $testsPath, ?string $filter = null): int
    {
        $files = $this->discover($testsPath);

        if ($filter !== null && $filter !== '') {
            $files = array_values(array_filter(
                $files,
                static fn (string $f): bool => stripos(str_replace('\\', '/', $f), $filter) !== false,
            ));
        }

        if ($files === []) {
            $this->line('No tests found.');
            return 1;
        }

        foreach ($files as $file) {
            require_once $file;
        }

        foreach (get_declared_classes() as $class) {
            if (!is_subclass_of($class, TestCase::class)) {
                continue;
            }
            if (str_starts_with($class, 'Kabuto\\Tests\\Fixtures\\')) {
                continue;
            }
            if ($filter !== null && $filter !== '' && stripos($class, $filter) === false) {
                continue;
            }
            $this->runClass($class);
        }

        $this->report();

        return ($this->failures !== [] || $this->errors !== []) ? 1 : 0;
    }

    /** @return list<string> */
    private function discover(string $testsPath): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($testsPath, \FilesystemIterator::SKIP_DOTS),
        );

        $files = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /** @param class-string<TestCase> $class */
    private function runClass(string $class): void
    {
        $reflection = new \ReflectionClass($class);

        if ($reflection->isAbstract()) {
            return;
        }

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!str_starts_with($method->getName(), 'test')) {
                continue;
            }

            $this->tests++;
            /** @var TestCase $case */
            $case = $reflection->newInstance();
            self::$active = $case;

            try {
                self::invokeHook($case, 'setUp');
                try {
                    $case->{$method->getName()}();
                } finally {
                    self::invokeHook($case, 'tearDown');
                }
                $this->assertions += $case->assertions();
                array_push($this->failures, ...$case->failures());
            } catch (\Throwable $e) {
                $this->errors[] = [
                    'file' => $class,
                    'class' => $class,
                    'method' => $method->getName(),
                    'error' => sprintf('%s: %s', $e::class, $e->getMessage()),
                ];
            }
        }

        self::$active = null;
    }

    private static function invokeHook(TestCase $case, string $hook): void
    {
        if (!method_exists($case, $hook)) {
            return;
        }

        $method = new \ReflectionMethod($case, $hook);
        $method->invoke($case);
    }

    private function report(): void
    {
        foreach ($this->failures as $failure) {
            $this->line('FAIL ' . $failure);
        }

        foreach ($this->errors as $error) {
            $this->line(sprintf('ERROR %s::%s — %s', $error['class'], $error['method'], $error['error']));
        }

        $failed = count($this->failures) + count($this->errors);
        $this->line('');
        $this->line(sprintf(
            '%d tests, %d assertions, %d failed.',
            $this->tests,
            $this->assertions,
            $failed,
        ));
    }

    private function line(string $message): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }
}
