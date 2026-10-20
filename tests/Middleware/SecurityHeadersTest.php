<?php

declare(strict_types=1);

namespace Kabuto\Tests\Middleware;

use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Middleware\SecurityHeaders;
use Kabuto\Tests\TestCase;

final class SecurityHeadersTest extends TestCase
{
    public function testDefaultSecurityHeadersAreSet(): void
    {
        $middleware = new SecurityHeaders();
        $request = new Request('GET', '/');
        $response = new Response();

        $res = $middleware($request, $response, static function (Request $req, Response $res): Response {
            return $res->send('ok');
        });

        $headers = $res->headers();
        self::assertSame('nosniff', $headers['X-Content-Type-Options'] ?? null);
        self::assertSame('SAMEORIGIN', $headers['X-Frame-Options'] ?? null);
        self::assertSame('1; mode=block', $headers['X-XSS-Protection'] ?? null);
        self::assertSame('strict-origin-when-cross-origin', $headers['Referrer-Policy'] ?? null);
        self::assertSame('camera=(), microphone=(), geolocation=()', $headers['Permissions-Policy'] ?? null);
    }

    public function testCustomAndHstsHeaders(): void
    {
        $middleware = new SecurityHeaders(
            frameOptions: 'DENY',
            contentSecurityPolicy: "default-src 'self'",
            hsts: true,
            hstsMaxAge: 3600,
            hstsSubDomains: false,
        );

        $request = new Request('GET', '/');
        $response = new Response();

        $res = $middleware($request, $response, static function (Request $req, Response $res): Response {
            return $res->send('ok');
        });

        $headers = $res->headers();
        self::assertSame('DENY', $headers['X-Frame-Options'] ?? null);
        self::assertSame("default-src 'self'", $headers['Content-Security-Policy'] ?? null);
        self::assertSame('max-age=3600', $headers['Strict-Transport-Security'] ?? null);
    }
}
