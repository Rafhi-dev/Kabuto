<?php

/**
 * Kabuto OPcache Preloader Script
 *
 * This script is executed during PHP server startup when configured via:
 *   opcache.preload=/path/to/preload.php
 *   opcache.preload_user=www-data
 *
 * It compiles framework core classes and application code into OPcache shared
 * memory so they are instantly accessible without disk I/O or parsing overhead.
 */

declare(strict_types=1);

$basePath = __DIR__;

// 1. Ensure Composer autoloader is loaded
$autoloadFile = $basePath . '/vendor/autoload.php';
if (is_file($autoloadFile)) {
    require_once $autoloadFile;
}

// 2. Execute OPcache preloading
if (class_exists(\Kabuto\Support\OpcachePreloader::class)) {
    $result = \Kabuto\Support\OpcachePreloader::preload($basePath);

    // If executed directly from CLI (e.g. php preload.php), display report
    if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
        echo sprintf(
            "[Kabuto OPcache] Preload complete: %d total, %d compiled, %d skipped.\n",
            $result['total'],
            $result['compiled'],
            $result['skipped']
        );
        if ($result['errors'] !== []) {
            echo "[Kabuto OPcache] Errors encountered:\n";
            foreach ($result['errors'] as $file => $err) {
                echo sprintf("  - %s: %s\n", basename($file), $err);
            }
        }
    }
}
