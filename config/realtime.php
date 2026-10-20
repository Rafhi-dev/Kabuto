<?php

declare(strict_types=1);

return [
    'driver' => env('REALTIME_DRIVER', 'array'),
    'replay_capacity' => (int) env('REALTIME_REPLAY_CAPACITY', 100),
    'heartbeat_seconds' => (int) env('REALTIME_HEARTBEAT_SECONDS', 15),
    'idle_timeout_seconds' => (int) env('REALTIME_IDLE_TIMEOUT_SECONDS', 0),
    'max_duration_seconds' => (int) env('REALTIME_MAX_DURATION_SECONDS', 0),
    'poll_interval_ms' => (int) env('REALTIME_POLL_INTERVAL_MS', 200),
    'fanout_redis' => filter_var(env('REALTIME_FANOUT_REDIS', false), FILTER_VALIDATE_BOOLEAN),
    'gateways' => [
        'path' => base_path('app/Gateways'),
        'namespace' => env('REALTIME_GATEWAY_NAMESPACE', 'App\\Gateways'),
    ],
];
