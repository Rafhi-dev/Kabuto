<?php

declare(strict_types=1);

/**
 * Kabuto front controller.
 *
 * Works with or without `composer install`: if the Composer autoloader is
 * missing, a minimal PSR-4 autoloader for the framework and application
 * namespaces is registered.
 */

$basePath = dirname(__DIR__);
$autoload = $basePath . '/vendor/autoload.php';

if (is_file($autoload)) {
    require $autoload;
} else {
    $psr4 = [
        'Kabuto\\' => $basePath . '/core/',
        'App\\' => $basePath . '/app/',
    ];

    spl_autoload_register(static function (string $class) use ($psr4): void {
        foreach ($psr4 as $prefix => $dir) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            $relative = substr($class, strlen($prefix));
            $file = $dir . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require $file;
                return;
            }
        }
    });

    require $basePath . '/core/Support/helpers.php';
}

Kabuto\Support\Dotenv::load($basePath);

use Kabuto\Application;
use Kabuto\Http\Request;
use Kabuto\Middleware\Cors;
use Kabuto\Middleware\JsonBody;
use Kabuto\Middleware\SecurityHeaders;
use Kabuto\Middleware\RequestId;

$app = new Application(basePath: $basePath);

$app->use(SecurityHeaders::class);
$app->use(RequestId::class);
$app->use(Cors::class);
$app->use(JsonBody::class);

$app->boot();

$app->handle(Request::fromGlobals())->emit();
