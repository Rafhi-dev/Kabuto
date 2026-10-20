<?php

declare(strict_types=1);

return [
    'driver' => env('SESSION_DRIVER', 'file'),
    'cookie' => env('SESSION_COOKIE', 'kabuto_session'),
    'lifetime' => (int) env('SESSION_LIFETIME', 7200),
    'secure' => filter_var(env('SESSION_SECURE', false), FILTER_VALIDATE_BOOLEAN),
];
