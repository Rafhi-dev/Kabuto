<?php

declare(strict_types=1);

namespace Kabuto\Middleware;

use Kabuto\Http\Request;
use Kabuto\Http\Response;

final class Cors
{
    /**
     * @param string|null $origin Allowed origin, e.g. "*" or "https://app.test".
     *                            Null reads CORS_ORIGIN and otherwise denies CORS.
     */
    public function __construct(
        private readonly ?string $origin = null,
        private readonly string $methods = 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
        private readonly string $headers = 'Content-Type, Authorization',
        private readonly bool $credentials = false,
    ) {
    }

    public function __invoke(Request $req, Response $res, callable $next): Response
    {
        $configuredOrigin = $this->origin ?? (string) (function_exists('env') ? env('CORS_ORIGIN', '') : '');
        $requestOrigin = $req->header('origin');
        $allowedOrigin = '';

        if ($configuredOrigin === '*') {
            // Wildcard is intentionally never combined with credentials.
            $allowedOrigin = $this->credentials ? '' : '*';
        } elseif ($configuredOrigin !== '' && $requestOrigin !== null && hash_equals($configuredOrigin, $requestOrigin)) {
            $allowedOrigin = $configuredOrigin;
        }

        if ($allowedOrigin !== '') {
            $res->header('Access-Control-Allow-Origin', $allowedOrigin);
        }
        $res->header('Vary', 'Origin')
            ->header('Access-Control-Allow-Methods', $this->methods)
            ->header('Access-Control-Allow-Headers', $this->headers);

        if ($this->credentials) {
            $res->header('Access-Control-Allow-Credentials', 'true');
        }

        if ($req->is('OPTIONS')) {
            return $res->status(204);
        }

        return $next($req, $res);
    }
}
