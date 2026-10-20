<?php

declare(strict_types=1);

namespace Kabuto\Middleware;

use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Support\Logger;
use Throwable;

final class RequestLogger
{
    public function __construct(private readonly Logger $logger) {}

    public function __invoke(Request $request, Response $response, callable $next): Response
    {
        $started = microtime(true);
        try {
            $result = $next($request, $response);
            $this->logger->info('request.completed', [
                'request_id' => $request->get('request_id'),
                'method' => $request->method(),
                'path' => $request->path(),
                'status' => $result->getStatus(),
                'duration_ms' => round((microtime(true) - $started) * 1000, 2),
            ]);
            return $result;
        } catch (Throwable $exception) {
            $this->logger->exception($exception, [
                'event' => 'request.failed',
                'request_id' => $request->get('request_id'),
                'method' => $request->method(),
                'path' => $request->path(),
                'duration_ms' => round((microtime(true) - $started) * 1000, 2),
            ]);
            throw $exception;
        }
    }
}
