<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'Kabuto'),
    'env' => env('APP_ENV', 'local'),
    'debug' => filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN),
    'timezone' => env('APP_TIMEZONE', 'UTC'),
    'key' => env('APP_KEY', ''),
];
