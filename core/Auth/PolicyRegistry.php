<?php

declare(strict_types=1);

namespace Kabuto\Auth;

final class PolicyRegistry
{
    /** @var array<class-string,object> */
    private array $policies = [];

    public function register(string $model, object $policy): self { $this->policies[$model] = $policy; return $this; }
    public function for(mixed $model): ?object { return $this->policies[is_object($model) ? $model::class : get_debug_type($model)] ?? null; }
    /** @return array<class-string,object> */
    public function all(): array { return $this->policies; }

    public function discover(string $directory, string $namespace = 'App\\Policies', string $modelNamespace = 'App\\Models'): int
    {
        if (!is_dir($directory)) return 0;
        $count = 0;
        $root = rtrim($directory, '/\\');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || !str_ends_with($fileInfo->getFilename(), 'Policy.php')) continue;
            $relative = substr($fileInfo->getPathname(), strlen($root) + 1, -4);
            $class = trim($namespace, '\\') . '\\' . str_replace(['/', '\\'], '\\', $relative);
            if (!class_exists($class)) continue;
            $model = substr($class, strrpos($class, '\\') + 1, -6);
            $modelClass = trim($modelNamespace, '\\') . '\\' . $model;
            if (class_exists($modelClass)) { $this->register($modelClass, new $class()); $count++; }
        }
        return $count;
    }
}
