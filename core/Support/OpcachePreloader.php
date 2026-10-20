<?php

declare(strict_types=1);

namespace Kabuto\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use Throwable;

final class OpcachePreloader
{
    /**
     * Preload PHP files into OPcache shared memory.
     *
     * @param string|null $basePath Base path of the Kabuto application
     * @param array<string, mixed> $customConfig Override config options
     * @return array{total: int, compiled: int, skipped: int, errors: array<string, string>}
     */
    public static function preload(?string $basePath = null, array $customConfig = []): array
    {
        $base = rtrim($basePath ?? dirname(__DIR__, 2), '/\\');
        $config = self::resolveConfig($base, $customConfig);

        if (!($config['enabled'] ?? true)) {
            return [
                'total' => 0,
                'compiled' => 0,
                'skipped' => 0,
                'errors' => ['preloader' => 'OPcache preloading is disabled in configuration.'],
            ];
        }

        $paths = (array) ($config['paths'] ?? ['core', 'app']);
        $excludes = (array) ($config['exclude'] ?? []);

        $files = self::getFilesToPreload($base, $paths, $excludes);
        $total = count($files);
        $compiled = 0;
        $skipped = 0;
        $errors = [];

        $hasOpcacheCompile = function_exists('opcache_compile_file');

        foreach ($files as $file) {
            if (!$hasOpcacheCompile) {
                $skipped++;
                continue;
            }

            try {
                // Skip the preloader itself since it is already defined in memory
                if (realpath($file) === __FILE__) {
                    $skipped++;
                    continue;
                }

                // If the file requires an uninstalled PHP extension (e.g. openswoole), skip gracefully
                if (str_contains($file, 'OpenSwooleDriver') && !extension_loaded('openswoole')) {
                    $skipped++;
                    continue;
                }

                $success = @opcache_compile_file($file);
                if ($success) {
                    $compiled++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                $skipped++;
                $errors[$file] = $e->getMessage();
            }
        }

        return [
            'total' => $total,
            'compiled' => $compiled,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Resolve list of absolute file paths to preload based on paths and exclude patterns.
     *
     * @param string $basePath
     * @param array<string> $paths
     * @param array<string> $excludes
     * @return list<string>
     */
    public static function getFilesToPreload(string $basePath, array $paths, array $excludes): array
    {
        $files = [];

        foreach ($paths as $relPath) {
            $fullPath = $basePath . DIRECTORY_SEPARATOR . ltrim($relPath, '/\\');
            if (!is_dir($fullPath)) {
                continue;
            }

            $dirIterator = new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS);
            $iterator = new RecursiveIteratorIterator($dirIterator);
            $phpFiles = new RegexIterator($iterator, '/^.+\.php$/i', RegexIterator::GET_MATCH);

            foreach ($phpFiles as $match) {
                $file = realpath($match[0]);
                if ($file === false) {
                    continue;
                }

                if (self::isExcluded($file, $excludes)) {
                    continue;
                }

                $files[] = $file;
            }
        }

        sort($files);
        return array_values(array_unique($files));
    }

    /**
     * Check if a file matches any of the exclude rules.
     *
     * @param string $filePath
     * @param array<string> $excludes
     */
    public static function isExcluded(string $filePath, array $excludes): bool
    {
        $normalizedPath = str_replace('\\', '/', $filePath);

        foreach ($excludes as $pattern) {
            if (empty($pattern)) {
                continue;
            }

            // If it's a regex pattern starting and ending with delimiter:
            if (str_starts_with($pattern, '#') && str_ends_with($pattern, '#')) {
                if (@preg_match($pattern, $filePath) === 1 || @preg_match($pattern, $normalizedPath) === 1) {
                    return true;
                }
            } else {
                $normalizedPattern = str_replace('\\', '/', $pattern);
                if (str_contains($normalizedPath, $normalizedPattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if OPcache is enabled and usable in the current PHP runtime.
     */
    public static function isOpcacheActive(): bool
    {
        if (!extension_loaded('Zend OPcache')) {
            return false;
        }

        $enabled = (bool) ini_get('opcache.enable');
        if (PHP_SAPI === 'cli') {
            return $enabled && (bool) ini_get('opcache.enable_cli');
        }

        return $enabled;
    }

    /**
     * Resolve configuration array from config file and custom overrides.
     *
     * @param string $basePath
     * @param array<string, mixed> $customConfig
     * @return array<string, mixed>
     */
    private static function resolveConfig(string $basePath, array $customConfig): array
    {
        $configFile = $basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'opcache.php';
        $fileConfig = [];

        if (is_file($configFile)) {
            $loaded = require $configFile;
            if (is_array($loaded)) {
                $fileConfig = $loaded;
            }
        }

        return array_replace_recursive($fileConfig, $customConfig);
    }
}
