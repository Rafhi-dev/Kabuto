<?php

declare(strict_types=1);

namespace Kabuto\Observability;

use Kabuto\Http\Request;
use Kabuto\Http\Response;

final class TraceContext
{
    private static ?array $active = null;

    public static function current(): ?array
    {
        return self::$active;
    }
    /** @return array{correlation_id:string,request_id:string,trace_id:string,span_id:string,parent_span_id:?string} */
    public function start(Request $request): array
    {
        $existing = $request->get('request_id');
        $requestId = is_string($existing) && $this->valid($existing)
            ? $existing
            : ($this->valid($request->header('x-request-id')) ? (string) $request->header('x-request-id') : $this->id('req_'));
        $traceparent = trim((string) $request->header('traceparent', ''));
        $traceId = $this->traceId($traceparent) ?? $this->hex(16);
        $parent = $this->spanId($traceparent);
        $span = $this->hex(8);
        return ['correlation_id' => $requestId, 'request_id' => $requestId, 'trace_id' => $traceId, 'span_id' => $span, 'parent_span_id' => $parent];
    }

    public function apply(Request $request, Response $response, array $context): Response
    {
        foreach ($context as $key => $value) $request->set($key, $value);
        $request->set('trace_context', $context);
        return $response
            ->header('X-Request-ID', (string) $context['request_id'])
            ->header('X-Correlation-ID', (string) $context['correlation_id'])
            ->header('Traceparent', '00-' . $context['trace_id'] . '-' . $context['span_id'] . '-01');
    }

    public function run(array $context, callable $callback): mixed
    {
        $previous = self::$active;
        self::$active = $context;
        try {
            return $callback();
        } finally {
            self::$active = $previous;
        }
    }

    /** @param array<string,string> $carrier @return array<string,string> */
    public function inject(array $carrier, array $context): array
    {
        $carrier['x-request-id'] = (string) $context['request_id'];
        $carrier['x-correlation-id'] = (string) $context['correlation_id'];
        $carrier['traceparent'] = '00-' . $context['trace_id'] . '-' . $context['span_id'] . '-01';
        return $carrier;
    }

    /** @return array{trace_id:string,parent_span_id:?string}|null */
    public function parse(string $traceparent): ?array
    {
        $trace = $this->traceId($traceparent);
        if ($trace === null) return null;
        return ['trace_id' => $trace, 'parent_span_id' => $this->spanId($traceparent)];
    }

    private function valid(?string $value): bool { return $value !== null && preg_match('/^[A-Za-z0-9._-]{1,128}$/', $value) === 1; }
    private function id(string $prefix): string { return $prefix . bin2hex(random_bytes(16)); }
    private function hex(int $bytes): string { return bin2hex(random_bytes($bytes)); }
    private function traceId(string $value): ?string { return preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/i', $value, $m) === 1 ? strtolower($m[1]) : null; }
    private function spanId(string $value): ?string { return preg_match('/^00-[0-9a-f]{32}-([0-9a-f]{16})-[0-9a-f]{2}$/i', $value, $m) === 1 ? strtolower($m[1]) : null; }
}
