<?php

declare(strict_types=1);

/**
 * Module configuration.
 *
 * `modules`    – NestJS-style root modules, registered first (primary).
 * `providers`  – classic service providers for manual bindings (secondary).
 * `routes`     – directory scanned for `*\/routes.php` files (secondary).
 * `namespace`  – PSR-4 namespace that maps to the routes directory.
 * `discover`   – also register controllers annotated with #[Controller].
 *
 * @return array{modules:list<class-string>,providers:list<class-string>,routes:string,namespace:string,discover:bool}
 */

use App\AppModule;

return [
    'modules' => [
        AppModule::class,
    ],
    'providers' => [
        // Custom service providers (optional)
    ],
    'routes' => dirname(__DIR__) . '/app/Modules',
    'namespace' => 'App\\Modules',
    'discover' => true,
];
