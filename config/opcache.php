<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | OPcache Preload Configuration
    |--------------------------------------------------------------------------
    |
    | When PHP boots up with OPcache preloading enabled (`opcache.preload`),
    | Kabuto compiles and loads framework core files and application classes
    | into shared memory so they never need to be parsed or re-compiled again.
    |
    */

    'enabled' => (bool) env('OPCACHE_PRELOAD_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Directories to Preload
    |--------------------------------------------------------------------------
    |
    | Directories relative to project root that should be scanned and preloaded.
    |
    */
    'paths' => [
        'core',
        'app',
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Files and Patterns
    |--------------------------------------------------------------------------
    |
    | Regular expressions or string subpaths that should be excluded from
    | preloading (e.g. tests, optional extension drivers, or dev tools).
    |
    */
    'exclude' => [
        '#(/|\\\\)tests(/|\\\\)#',
        '#(/|\\\\)public(/|\\\\)#',
        '#(/|\\\\)storage(/|\\\\)#',
        '#(/|\\\\)vendor(/|\\\\)#',
        'OpcachePreloader.php',
    ],

    /*
    |--------------------------------------------------------------------------
    | Recommended php.ini Directives
    |--------------------------------------------------------------------------
    |
    | Optimal production OPcache and JIT settings for high-throughput Kabuto.
    |
    */
    'recommended' => [
        'opcache.enable' => 1,
        'opcache.enable_cli' => 1,
        'opcache.memory_consumption' => 256,
        'opcache.interned_strings_buffer' => 16,
        'opcache.max_accelerated_files' => 20000,
        'opcache.validate_timestamps' => 0,
        'opcache.save_comments' => 1,
        'opcache.jit' => 'tracing',
        'opcache.jit_buffer_size' => '128M',
    ],
];
