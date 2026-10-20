<?php

declare(strict_types=1);

return [
    'channel' => env('LOG_CHANNEL', 'app'),
    'level' => env('LOG_LEVEL', 'info'),
    'path' => env('LOG_PATH', base_path('storage/logs/kabuto.log')),
    'http' => [
        'enabled' => filter_var(env('LOG_HTTP', false), FILTER_VALIDATE_BOOLEAN),
    ],
];
