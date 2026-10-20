<?php

declare(strict_types=1);

namespace Kabuto\Tests\Observability;

use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Middleware\Observability;
use Kabuto\Observability\MetricsRegistry;
use Kabuto\Observability\TraceContext;
use Kabuto\Tests\TestCase;

final class ObservabilityMiddlewareTest extends TestCase
{
    public function testRecordsRequestResponseDurationAndHeaders(): void
    {
        $metrics = new MetricsRegistry();
        $middleware = new Observability($metrics, new TraceContext());
        $request = Request::fromRaw("GET /orders HTTP/1.1\r\n\r\n");
        $response = $middleware($request, new Response(), static fn (Request $request, Response $response): Response => $response->status(204));

        self::assertSame(1, $metrics->counter('kabuto_requests_total', ['method' => 'GET', 'path' => '/orders']));
        self::assertSame(1, $metrics->counter('kabuto_responses_total', ['status' => '204']));
        self::assertSame(204, $response->getStatus());
        self::assertArrayHasKey('X-Correlation-ID', $response->headers());
        self::assertArrayHasKey('Traceparent', $response->headers());
    }

    public function testCountsExceptionsAndRethrows(): void
    {
        $metrics = new MetricsRegistry();
        $middleware = new Observability($metrics, new TraceContext());
        $request = Request::fromRaw("GET /boom HTTP/1.1\r\n\r\n");
        self::assertThrows(\RuntimeException::class, static function () use ($middleware, $request): void {
            $middleware($request, new Response(), static function (): never {
                throw new \RuntimeException('boom');
            });
        });
        self::assertSame(1, $metrics->counter('kabuto_exceptions_total', ['type' => \RuntimeException::class]));
    }
}
