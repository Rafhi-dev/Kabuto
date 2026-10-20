<?php

declare(strict_types=1);

namespace Kabuto\Tests\Http;

use Kabuto\Common\Cookie as CookieAttr;
use Kabuto\Container\Container;
use Kabuto\Http\Cookie;
use Kabuto\Http\HandlerResolver;
use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Tests\TestCase;

final class CookieTest extends TestCase
{
    private ?string $backupKey = null;

    protected function setUp(): void
    {
        $this->backupKey = $_SERVER['APP_KEY'] ?? null;
        $_SERVER['APP_KEY'] = 'base64:' . base64_encode(random_bytes(32));
        $_ENV['APP_KEY'] = $_SERVER['APP_KEY'];
    }

    protected function tearDown(): void
    {
        if ($this->backupKey === null) {
            unset($_SERVER['APP_KEY'], $_ENV['APP_KEY']);
        } else {
            $_SERVER['APP_KEY'] = $this->backupKey;
            $_ENV['APP_KEY'] = $this->backupKey;
        }
    }

    public function testCookieBuilderAndToString(): void
    {
        $cookie = Cookie::make('session_id', 'xyz789', 60)
            ->path('/app')
            ->domain('example.com')
            ->secure(true)
            ->httpOnly(true)
            ->sameSite('Strict');

        $str = $cookie->toString();

        self::assertStringContains('session_id=xyz789', $str);
        self::assertStringContains('Path=/app', $str);
        self::assertStringContains('Domain=example.com', $str);
        self::assertStringContains('Secure', $str);
        self::assertStringContains('HttpOnly', $str);
        self::assertStringContains('SameSite=Strict', $str);
        self::assertStringContains('Max-Age=', $str);
    }

    public function testCookieForeverAndForget(): void
    {
        $forever = Cookie::forever('pref', 'dark');
        self::assertStringContains('pref=dark', $forever->toString());
        self::assertTrue($forever->getExpires() > time() + (365 * 24 * 3600));

        $forget = Cookie::forget('pref');
        self::assertStringContains('pref=', $forget->toString());
        self::assertSame(1, $forget->getExpires());
        self::assertStringContains('Max-Age=0', $forget->toString());
    }

    public function testEncryptedCookieLifecycle(): void
    {
        if (!extension_loaded('openssl')) {
            self::assertTrue(true, 'openssl missing, skipped encryption test.');
            return;
        }

        $cookie = Cookie::make('secret_token', 'my-super-secret-123')
            ->encrypt();

        $rawHeader = $cookie->toString();
        self::assertFalse(str_contains($rawHeader, 'my-super-secret-123'));

        // Extract encrypted value
        preg_match('/secret_token=([^;]+)/', $rawHeader, $matches);
        $encryptedVal = rawurldecode($matches[1] ?? '');

        $req = new Request('GET', '/', [], [], [], [], ['secret_token' => $encryptedVal]);

        // Raw read
        self::assertSame($encryptedVal, $req->cookie('secret_token'));

        // Decrypted read
        self::assertSame('my-super-secret-123', $req->cookie('secret_token', decrypt: true));
    }

    public function testResponseMultipleCookies(): void
    {
        $res = new Response();
        $res->withCookie(Cookie::make('cookie1', 'val1'))
            ->withCookie(Cookie::make('cookie2', 'val2'));

        $cookieHeaders = $res->cookieHeaders();

        self::assertCount(2, $cookieHeaders);
        self::assertStringContains('cookie1=val1', $cookieHeaders[0]);
        self::assertStringContains('cookie2=val2', $cookieHeaders[1]);
    }

    public function testResponseWithoutCookie(): void
    {
        $res = new Response();
        $res->withoutCookie('remember_me');

        $cookieHeaders = $res->cookieHeaders();
        self::assertCount(1, $cookieHeaders);
        self::assertStringContains('remember_me=', $cookieHeaders[0]);
        self::assertStringContains('Max-Age=0', $cookieHeaders[0]);
    }

    public function testControllerCookieAttributeInjection(): void
    {
        $hasOpenssl = extension_loaded('openssl');

        $controller = new class {
            public function show(
                #[CookieAttr('theme')] ?string $theme,
                #[CookieAttr('user_id')] ?string $userId,
                #[CookieAttr('missing', default: 'fallback')] string $missing,
            ): array {
                return [
                    'theme' => $theme,
                    'user_id' => $userId,
                    'missing' => $missing,
                ];
            }
        };

        $userIdVal = 'user_99';

        $req = new Request('GET', '/', [], [], [], [], [
            'theme' => 'solarized',
            'user_id' => $userIdVal,
        ]);

        $resolver = new HandlerResolver(new Container());
        $handler = $resolver->resolve([$controller, 'show']);

        $data = $handler($req, new Response());

        self::assertSame('solarized', $data['theme']);
        self::assertSame('user_99', $data['user_id']);
        self::assertSame('fallback', $data['missing']);
    }

    public function testGlobalCookieHelper(): void
    {
        $c = cookie('test', '123', 30, ['secure' => true]);

        self::assertInstanceOf(Cookie::class, $c);
        self::assertSame('test', $c->getName());
        self::assertSame('123', $c->getValue());
        self::assertTrue($c->isSecure());
    }
}
