<?php

declare(strict_types=1);

return [
    'tasks' => [],
    'lock_store' => env('SCHEDULE_LOCK_STORE', 'memory'), // memory, database, redis
    'history_store' => env('SCHEDULE_HISTORY_STORE', 'memory'), // memory, database, none
    'shutdown_grace_seconds' => (int) env('SCHEDULE_SHUTDOWN_GRACE', 5),
    'maintenance_file' => base_path('storage/framework/down'),
];
