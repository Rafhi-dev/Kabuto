<?php

declare(strict_types=1);

return [
    'uploads' => [
        'max_files' => (int) env('UPLOAD_MAX_FILES', 10),
        'max_total_size' => (int) env('UPLOAD_MAX_TOTAL_SIZE', 20971520),
        'max_file_size' => (int) env('UPLOAD_MAX_FILE_SIZE', 10485760),
        'scan_driver' => env('UPLOAD_SCAN_DRIVER', 'none'),
        'clamav_socket' => env('CLAMAV_SOCKET', '/var/run/clamav/clamd.sock'),
        'quarantine_path' => env('UPLOAD_QUARANTINE_PATH', base_path('storage/quarantine')),
        'sanitize_images' => filter_var(env('UPLOAD_SANITIZE_IMAGES', false), FILTER_VALIDATE_BOOLEAN),
    ],
    's3' => [
        'bucket' => env('S3_BUCKET', ''),
        'endpoint' => env('S3_ENDPOINT', 'https://s3.amazonaws.com'),
        'region' => env('S3_REGION', 'us-east-1'),
        'access_key' => env('S3_ACCESS_KEY', ''),
        'secret_key' => env('S3_SECRET_KEY', ''),
        'session_token' => env('S3_SESSION_TOKEN', null),
        'path_style' => filter_var(env('S3_PATH_STYLE', true), FILTER_VALIDATE_BOOLEAN),
        'public' => filter_var(env('S3_PUBLIC', false), FILTER_VALIDATE_BOOLEAN),
        'timeout' => (int) env('S3_TIMEOUT', 15),
        'retries' => (int) env('S3_RETRIES', 2),
        'max_object_size' => (int) env('S3_MAX_OBJECT_SIZE', 5000000000000),
        'allowed_content_types' => array_values(array_filter(array_map('trim', explode(',', (string) env('S3_ALLOWED_CONTENT_TYPES', ''))))),
    ],
];
