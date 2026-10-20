<?php

declare(strict_types=1);

namespace Kabuto\Tests\Middleware;

use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Middleware\Csrf;
use Kabuto\Tests\TestCase;

final class CsrfTest extends TestCase
{
    public function testGetSetsXsrfTokenCookie(): void
    {
        $csrf = new Csrf();
        $request = new Request('GET', '/profile');
        $response = new Response();

        $res = $csrf($request, $response, fn ($req, $res) => $res->send('ok'));

        $setCookie = $res->headers()['Set-Cookie'] ?? '';
        self::assertStringContains('XSRF-TOKEN=', $setCookie);
    }

    public function testPostWithoutTokenIsForbidden403(): void
    {
        $csrf = new Csrf();
        $request = new Request('POST', '/submit');
        $response = new Response();

        $res = $csrf($request, $response, fn ($req, $res) => $res->send('ok'));

        self::assertSame(403, $res->getStatus());
        self::assertStringContains('Forbidden', $res->getBody());
    }

    public function testPostWithValidHeaderPasses(): void
    {
        $csrf = new Csrf();
        $token = $csrf->generateToken();

        $request = new Request('POST', '/submit', [], [], ['x-xsrf-token' => $token]);
        $response = new Response();

        $res = $csrf($request, $response, fn ($req, $res) => $res->send('success'));

        self::assertSame(200, $res->getStatus());
        self::assertSame('success', $res->getBody());
    }

    public function testExemptRoutePassesWithoutToken(): void
    {
        $csrf = new Csrf(except: ['api/*']);
        $request = new Request('POST', '/api/users');
        $response = new Response();

        $res = $csrf($request, $response, fn ($req, $res) => $res->send('created'));

        self::assertSame(200, $res->getStatus());
        self::assertSame('created', $res->getBody());
    }
}
