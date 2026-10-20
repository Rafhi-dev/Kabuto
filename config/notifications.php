<?php

declare(strict_types=1);

return [
    'driver' => env('NOTIFICATIONS_DRIVER', 'array'),
    'attempts' => (int) env('NOTIFICATIONS_ATTEMPTS', 1),
    'retry_delay_ms' => (int) env('NOTIFICATIONS_RETRY_DELAY_MS', 100),
    'store' => env('NOTIFICATIONS_STORE', 'memory'),
];
