<?php

declare(strict_types=1);

namespace Kabuto\Tests\Observability;

use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Observability\TraceContext;
use Kabuto\Tests\TestCase;

final class TraceContextTest extends TestCase
{
    public function testStartsFromRequestAndPropagatesTraceparent(): void
    {
        $request = Request::fromRaw("GET / HTTP/1.1\r\nX-Request-ID: req-123\r\nTraceparent: 00-0123456789abcdef0123456789abcdef-0123456789abcdef-01\r\n\r\n");
        $traces = new TraceContext();
        $context = $traces->start($request);
        $response = $traces->apply($request, new Response(), $context);

        self::assertSame('req-123', $context['request_id']);
        self::assertSame('0123456789abcdef0123456789abcdef', $context['trace_id']);
        self::assertSame('0123456789abcdef', $context['parent_span_id']);
        self::assertSame('req-123', $response->headers()['X-Request-ID']);
        self::assertStringContains('00-0123456789abcdef0123456789abcdef-', $response->headers()['Traceparent']);
    }

    public function testRejectsMalformedIncomingIdsAndInjectsSafeCarrier(): void
    {
        $request = Request::fromRaw("GET / HTTP/1.1\r\nX-Request-ID: bad id\r\nTraceparent: malformed\r\n\r\n");
        $traces = new TraceContext();
        $context = $traces->start($request);
        $carrier = $traces->inject([], $context);

        self::assertTrue(str_starts_with($context['request_id'], 'req_'));
        self::assertSame($context['request_id'], $carrier['x-request-id']);
        self::assertStringContains('00-' . $context['trace_id'], $carrier['traceparent']);
        self::assertNull($traces->parse('broken'));
    }
}
