<?php

declare(strict_types=1);

return [
    'driver' => env('QUEUE_CONNECTION', 'database'),
    'queue' => env('QUEUE_NAME', 'default'),
];
