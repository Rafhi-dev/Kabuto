<?php

declare(strict_types=1);

return [
    'host' => env('REDIS_HOST', '127.0.0.1'),
    'port' => (int) env('REDIS_PORT', 6379),
    'password' => env('REDIS_PASSWORD', null),
    'database' => (int) env('REDIS_DB', 0),
    'timeout' => (float) env('REDIS_TIMEOUT', 2),
    'retries' => (int) env('REDIS_RETRIES', 1),
    'retry_delay_ms' => (int) env('REDIS_RETRY_DELAY_MS', 100),
    'tls' => filter_var(env('REDIS_TLS', false), FILTER_VALIDATE_BOOLEAN),
    'verify_peer' => filter_var(env('REDIS_VERIFY_PEER', true), FILTER_VALIDATE_BOOLEAN),
    'verify_peer_name' => filter_var(env('REDIS_VERIFY_PEER_NAME', true), FILTER_VALIDATE_BOOLEAN),
    'cafile' => env('REDIS_CAFILE', null),
    'persistent' => filter_var(env('REDIS_PERSISTENT', false), FILTER_VALIDATE_BOOLEAN),
    'prefix' => env('REDIS_PREFIX', 'kabuto:'),
];
