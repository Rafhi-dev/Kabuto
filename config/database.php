<?php

declare(strict_types=1);

return [
    // Every value comes from the environment (.env file or real variables).
    // See .env.example for documentation.
    'default' => env('DB_CONNECTION', 'db'),

    'connections' => [
        'db' => [
            'driver' => env('DB_DRIVER', 'mysql'),
            'path' => env('DB_PATH', base_path('database/app.sqlite')),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', 'app'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'schemas' => ['base'],
        ],
    ],
];
