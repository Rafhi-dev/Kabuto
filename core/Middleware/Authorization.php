<?php

declare(strict_types=1);

namespace Kabuto\Middleware;

use Kabuto\Auth\Gate;
use Kabuto\Http\Request;
use Kabuto\Http\Response;

final class Authorization
{
    public function __invoke(Request $request, Response $response, callable $next): Response
    {
        if ($request->get('_route_public', false)) return $next($request, $response);
        if (($request->get('_route_metadata', [])['protected'] ?? false) === true) return $next($request, $response);
        if ($request->user() === null) return (new Response())->status(401)->json(['statusCode' => 401, 'error' => 'Unauthorized', 'message' => 'Authentication required.']);
        $authorization = $request->get('_route_authorization');
        if (is_array($authorization) && isset($authorization['ability'])) {
            Gate::forUser($request->user())->authorizeModel((string) $authorization['ability'], $request->get($authorization['model'] ?? '') ?? $request->param($authorization['parameter'] ?? 'id'), $authorization['model'] ?? '');
        }
        return $next($request, $response);
    }
}
