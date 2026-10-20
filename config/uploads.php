<?php

declare(strict_types=1);

return [
    'max_files' => (int) env('UPLOAD_MAX_FILES', 10),
    'max_total_size' => (int) env('UPLOAD_MAX_TOTAL_SIZE', 20971520),
    'max_file_size' => (int) env('UPLOAD_MAX_FILE_SIZE', 10485760),
    'scan_driver' => env('UPLOAD_SCAN_DRIVER', 'none'),
    'clamav_socket' => env('CLAMAV_SOCKET', '/var/run/clamav/clamd.sock'),
    'quarantine_path' => env('UPLOAD_QUARANTINE_PATH', base_path('storage/quarantine')),
    'sanitize_images' => filter_var(env('UPLOAD_SANITIZE_IMAGES', false), FILTER_VALIDATE_BOOLEAN),
];
