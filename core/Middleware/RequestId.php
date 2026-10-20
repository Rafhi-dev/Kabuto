<?php

declare(strict_types=1);

namespace Kabuto\Middleware;

use Kabuto\Http\Request;
use Kabuto\Http\Response;

final class RequestId
{
    public function __invoke(Request $request, Response $response, callable $next): Response
    {
        $incoming = trim((string) $request->header('x-request-id', ''));
        $id = preg_match('/^[A-Za-z0-9._-]{1,128}$/', $incoming) === 1
            ? $incoming
            : Request::generateId();

        $request->set('request_id', $id);
        $response = $next($request, $response);

        return $response->header('X-Request-ID', $id);
    }
}
