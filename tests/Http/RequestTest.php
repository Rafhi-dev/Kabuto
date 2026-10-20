<?php

declare(strict_types=1);

namespace Kabuto\Tests\Http;

use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Middleware\Cors;
use Kabuto\Tests\TestCase;

final class RequestTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'], $_POST['_method']);
    }

    public function testMethodOverrideViaHeader(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] = 'PUT';
        $_SERVER['REQUEST_URI'] = '/users/1';

        $request = Request::fromGlobals();

        self::assertSame('PUT', $request->method());
    }

    public function testMethodOverrideViaField(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/users/1';
        $_POST['_method'] = 'DELETE';

        $request = Request::fromGlobals();

        self::assertSame('DELETE', $request->method());
    }

    public function testNoOverrideForGetRequests(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/users';

        self::assertSame('GET', Request::fromGlobals()->method());
    }

    public function testAuthorizationFromRedirectHeader(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer secret';

        $request = Request::fromGlobals();

        self::assertSame('Bearer secret', $request->header('authorization'));
    }

    public function testJsonEncodeFailureThrows(): void
    {
        $recursive = [];
        $recursive['self'] = &$recursive;

        self::assertThrows(
            \RuntimeException::class,
            static fn () => (new Response())->json($recursive),
        );
    }

    public function testCorsSendsVaryHeader(): void
    {
        $cors = new Cors(origin: '*');
        $response = $cors(new Request('GET', '/'), new Response(), static fn ($req, $res) => $res);

        self::assertSame('Origin', $response->headers()['Vary']);
        self::assertFalse(isset($response->headers()['Access-Control-Allow-Credentials']));
    }

    public function testCorsCredentials(): void
    {
        $cors = new Cors(origin: 'https://app.test', credentials: true);
        $request = new Request('GET', '/', headers: ['origin' => 'https://app.test']);
        $response = $cors($request, new Response(), static fn ($req, $res) => $res);

        self::assertSame('true', $response->headers()['Access-Control-Allow-Credentials']);
    }
}
