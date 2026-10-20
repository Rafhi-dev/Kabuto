<?php

declare(strict_types=1);

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefixes = [
        'Kabuto\\' => $root . '/core/',
        'App\\' => $root . '/app/',
    ];
    foreach ($prefixes as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) continue;
        $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) require_once $file;
        return;
    }
});
require_once $root . '/core/Support/helpers.php';
