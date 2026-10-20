<?php

declare(strict_types=1);

namespace Kabuto\Middleware;

use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Observability\MetricsRegistry;
use Kabuto\Observability\TraceContext;
use Throwable;

final class Observability
{
    public function __construct(private readonly MetricsRegistry $metrics, private readonly TraceContext $traces) {}

    public function __invoke(Request $request, Response $response, callable $next): Response
    {
        $context = $this->traces->start($request);
        $this->traces->apply($request, $response, $context);
        $started = microtime(true);
        $this->metrics->increment('kabuto_requests_total', labels: ['method' => $request->method(), 'path' => $request->path()]);
        try {
            $result = $this->traces->run($context, fn (): Response => $next($request, $response));
            $this->metrics->increment('kabuto_responses_total', labels: ['status' => (string) $result->getStatus()]);
            return $this->traces->apply($request, $result, $context);
        } catch (Throwable $exception) {
            $this->metrics->increment('kabuto_exceptions_total', labels: ['type' => $exception::class]);
            throw $exception;
        } finally {
            $this->metrics->observe('kabuto_request_duration_ms', (microtime(true) - $started) * 1000, ['method' => $request->method()]);
        }
    }
}
