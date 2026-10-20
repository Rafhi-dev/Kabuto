<?php

declare(strict_types=1);

namespace Kabuto\Tests\Middleware;

use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Middleware\RateLimiter;
use Kabuto\Tests\TestCase;

final class RateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        RateLimiter::clearMemory();
    }

    public function testAllowsRequestsWithinLimit(): void
    {
        $limiter = new RateLimiter(maxAttempts: 3, decaySeconds: 60);
        $request = new Request('GET', '/test-limit', [], [], [], ['REMOTE_ADDR' => '192.168.1.1']);

        $res1 = $limiter($request, new Response(), fn ($req, $res) => $res->send('ok'));
        self::assertSame(200, $res1->getStatus());
        self::assertSame('3', $res1->headers()['X-RateLimit-Limit'] ?? null);
        self::assertSame('2', $res1->headers()['X-RateLimit-Remaining'] ?? null);

        $res2 = $limiter($request, new Response(), fn ($req, $res) => $res->send('ok'));
        self::assertSame('1', $res2->headers()['X-RateLimit-Remaining'] ?? null);

        $res3 = $limiter($request, new Response(), fn ($req, $res) => $res->send('ok'));
        self::assertSame('0', $res3->headers()['X-RateLimit-Remaining'] ?? null);
    }

    public function testBlocksRequestsExceedingLimitWith429(): void
    {
        $limiter = new RateLimiter(maxAttempts: 2, decaySeconds: 60);
        $request = new Request('GET', '/blocked', [], [], [], ['REMOTE_ADDR' => '10.0.0.1']);

        $limiter($request, new Response(), fn ($req, $res) => $res->send('ok'));
        $limiter($request, new Response(), fn ($req, $res) => $res->send('ok'));

        // 3rd attempt should be throttled
        $res3 = $limiter($request, new Response(), fn ($req, $res) => $res->send('ok'));

        self::assertSame(429, $res3->getStatus());
        self::assertSame('0', $res3->headers()['X-RateLimit-Remaining'] ?? null);
        self::assertTrue(isset($res3->headers()['Retry-After']));
        self::assertStringContains('Too many requests', $res3->getBody());
    }
}
