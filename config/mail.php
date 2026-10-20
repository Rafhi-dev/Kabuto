<?php

declare(strict_types=1);

return [
    'driver' => env('MAIL_DRIVER', 'array'),
    'from' => env('MAIL_FROM_ADDRESS', ''),
    'host' => env('MAIL_HOST', '127.0.0.1'),
    'port' => (int) env('MAIL_PORT', 587),
    'username' => env('MAIL_USERNAME', null),
    'password' => env('MAIL_PASSWORD', null),
    'tls' => filter_var(env('MAIL_TLS', true), FILTER_VALIDATE_BOOLEAN),
    'allow_insecure_auth' => filter_var(env('MAIL_ALLOW_INSECURE_AUTH', false), FILTER_VALIDATE_BOOLEAN),
    'ehlo_name' => env('MAIL_EHLO_NAME', 'kabuto.local'),
    'retries' => (int) env('MAIL_RETRIES', 2),
    'timeout' => (float) env('MAIL_TIMEOUT', 10),
];
