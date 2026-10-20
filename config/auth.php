<?php

declare(strict_types=1);

return [
    'deny_by_default' => filter_var(env('AUTH_DENY_BY_DEFAULT', false), FILTER_VALIDATE_BOOLEAN),
    'policies' => [
        'path' => base_path('app/Policies'),
        'namespace' => env('AUTH_POLICY_NAMESPACE', 'App\\Policies'),
        'models' => env('AUTH_MODEL_NAMESPACE', 'App\\Models'),
    ],
    'jwt' => [
        'secret' => env('JWT_SECRET', env('APP_KEY', '')),
        'access_ttl' => (int) env('JWT_ACCESS_TTL', 900),
        'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 2592000),
        'issuer' => env('JWT_ISSUER', null),
        'audience' => env('JWT_AUDIENCE', null),
        'leeway' => (int) env('JWT_LEEWAY', 0),
        'max_access_ttl' => (int) env('JWT_MAX_ACCESS_TTL', env('JWT_ACCESS_TTL', 900)),
        'max_refresh_ttl' => (int) env('JWT_MAX_REFRESH_TTL', env('JWT_REFRESH_TTL', 2592000)),
        'revocation' => env('JWT_REVOCATION', 'memory'),
        'revocation_path' => env('JWT_REVOCATION_PATH', base_path('storage/framework/jwt-revocations.json')),
    ],
];
