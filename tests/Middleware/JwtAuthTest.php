<?php

declare(strict_types=1);

namespace Kabuto\Tests\Middleware;

use Kabuto\Auth\JWT;
use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Middleware\JwtAuth;
use Kabuto\Tests\TestCase;

final class JwtAuthTest extends TestCase
{
    private const SECRET = 'test-secret-key-32-chars-long-12345';

    private ?string $backupEnv = null;

    protected function setUp(): void
    {
        $this->backupEnv = $_ENV['JWT_SECRET'] ?? null;
        $_ENV['JWT_SECRET'] = self::SECRET;
    }

    protected function tearDown(): void
    {
        if ($this->backupEnv === null) {
            unset($_ENV['JWT_SECRET']);
        } else {
            $_ENV['JWT_SECRET'] = $this->backupEnv;
        }
    }

    public function testBearerTokenPassesAndSetsUser(): void
    {
        $token = JWT::sign(['id' => 10, 'username' => 'alice']);
        $req = new Request('GET', '/profile', [], [], ['authorization' => "Bearer {$token}"]);
        $res = new Response();

        $middleware = new JwtAuth();
        $called = false;

        $response = $middleware($req, $res, function (Request $req, Response $res) use (&$called): Response {
            $called = true;
            self::assertSame(10, $req->user('id'));
            self::assertSame('alice', $req->user('username'));
            return $res->json(['ok' => true]);
        });

        self::assertTrue($called);
        self::assertSame(200, $response->getStatus());
    }

    public function testCookieTokenFallbackPasses(): void
    {
        $token = JWT::sign(['id' => 20, 'role' => 'editor']);
        $req = new Request('GET', '/dashboard', [], [], [], [], ['jwt' => $token]);
        $res = new Response();

        $middleware = new JwtAuth();
        $called = false;

        $response = $middleware($req, $res, function (Request $req, Response $res) use (&$called): Response {
            $called = true;
            self::assertSame(20, $req->user('id'));
            self::assertSame('editor', $req->user('role'));
            return $res->json(['ok' => true]);
        });

        self::assertTrue($called);
        self::assertSame(200, $response->getStatus());
    }

    public function testMissingTokenReturns401(): void
    {
        $req = new Request('GET', '/protected');
        $res = new Response();

        $middleware = new JwtAuth();
        $called = false;

        $response = $middleware($req, $res, function (Request $req, Response $res) use (&$called): Response {
            $called = true;
            return $res;
        });

        self::assertFalse($called);
        self::assertSame(401, $response->getStatus());
        self::assertStringContains('Authentication token missing', (string) $response->getBody());
    }

    public function testExpiredTokenReturns401(): void
    {
        $token = JWT::encode(['id' => 30], self::SECRET, ttl: -30);
        $req = new Request('GET', '/protected', [], [], ['authorization' => "Bearer {$token}"]);
        $res = new Response();

        $middleware = new JwtAuth();
        $called = false;

        $response = $middleware($req, $res, function (Request $req, Response $res) use (&$called): Response {
            $called = true;
            return $res;
        });

        self::assertFalse($called);
        self::assertSame(401, $response->getStatus());
        self::assertStringContains('Authentication token expired', (string) $response->getBody());
    }

    public function testInvalidSignatureReturns401(): void
    {
        $token = JWT::sign(['id' => 40], 'different-secret-that-is-at-least-32-bytes');
        $req = new Request('GET', '/protected', [], [], ['authorization' => "Bearer {$token}"]);
        $res = new Response();

        $middleware = new JwtAuth();
        $called = false;

        $response = $middleware($req, $res, function (Request $req, Response $res) use (&$called): Response {
            $called = true;
            return $res;
        });

        self::assertFalse($called);
        self::assertSame(401, $response->getStatus());
        self::assertStringContains('Invalid authentication token', (string) $response->getBody());
    }
}
