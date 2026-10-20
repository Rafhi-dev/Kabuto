<?php

declare(strict_types=1);

return [
    'http' => [
        'enabled' => filter_var(env('OBSERVABILITY_HTTP', true), FILTER_VALIDATE_BOOLEAN),
    ],
    'metrics' => [
        'enabled' => filter_var(env('OBSERVABILITY_METRICS', true), FILTER_VALIDATE_BOOLEAN),
    ],
];
