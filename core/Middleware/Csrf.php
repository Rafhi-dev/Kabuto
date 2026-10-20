<?php

declare(strict_types=1);

namespace Kabuto\Middleware;

use Kabuto\Http\Request;
use Kabuto\Http\Response;

final class Csrf
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * @param list<string> $except Path patterns to exempt from CSRF checks, e.g. ['api/*', 'webhook']
     * @param string $cookieName Cookie name for XSRF (readable by JavaScript/Axios)
     * @param int $lifetime Token lifetime in seconds (default 2 hours)
     * @param bool $secure Set true to only transmit cookie over HTTPS
     */
    public function __construct(
        private readonly array $except = [],
        private readonly string $cookieName = 'XSRF-TOKEN',
        private readonly int $lifetime = 7200,
        private readonly ?bool $secure = null,
    ) {
    }

    public function __invoke(Request $req, Response $res, callable $next): Response
    {
        // 1. Safe methods: issue/refresh CSRF cookie and continue
        if (in_array($req->method(), self::SAFE_METHODS, true)) {
            /** @var Response $response */
            $response = $next($req, $res);

            $existing = $req->cookie($this->cookieName);
            if ($existing === null || !$this->verifyToken($existing)) {
                $newToken = $this->generateToken();
                $response->cookie($this->cookieName, $newToken, [
                    'expires' => time() + $this->lifetime,
                    'path' => '/',
                    'secure' => $this->secure ?? $this->defaultSecure(),
                    'httponly' => false, // accessible to client-side Axios/fetch
                    'samesite' => 'Lax',
                ]);
            }

            return $response;
        }

        // 2. State-changing methods (POST, PUT, PATCH, DELETE)
        if ($this->isExempt($req->path())) {
            return $next($req, $res);
        }

        $token = $this->extractToken($req);

        if ($token === null || !$this->verifyToken($token)) {
            return (new Response())
                ->status(403)
                ->json([
                    'statusCode' => 403,
                    'error' => 'Forbidden',
                    'message' => 'CSRF token mismatch or expired.',
                ]);
        }

        return $next($req, $res);
    }

    public function generateToken(): string
    {
        $nonce = bin2hex(random_bytes(16));
        $expires = time() + $this->lifetime;
        $payload = $nonce . '.' . $expires;
        $signature = hash_hmac('sha256', $payload, appKey());

        return $payload . '.' . $signature;
    }

    public function verifyToken(string $token): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        [$nonce, $expiresStr, $signature] = $parts;
        $expires = (int) $expiresStr;

        if ($expires <= time()) {
            return false;
        }

        $payload = $nonce . '.' . $expiresStr;
        $expectedSignature = hash_hmac('sha256', $payload, appKey());

        return hash_equals($expectedSignature, $signature);
    }

    private function extractToken(Request $req): ?string
    {
        // 1. From standard headers (X-XSRF-TOKEN or X-CSRF-TOKEN)
        $header = $req->header('x-xsrf-token') ?? $req->header('x-csrf-token');
        if ($header !== null && $header !== '') {
            return $header;
        }

        // 2. From body payload (_token or csrf_token)
        $bodyToken = $req->input('_token') ?? $req->input('csrf_token');
        if (is_string($bodyToken) && $bodyToken !== '') {
            return $bodyToken;
        }

        return null;
    }

    private function isExempt(string $path): bool
    {
        $normalizedPath = '/' . trim($path, '/');

        foreach ($this->except as $pattern) {
            $normalizedPattern = '/' . trim($pattern, '/');

            if ($normalizedPattern === $normalizedPath) {
                return true;
            }

            if (str_ends_with($normalizedPattern, '/*')) {
                $prefix = substr($normalizedPattern, 0, -2);
                if (str_starts_with($normalizedPath, $prefix . '/') || $normalizedPath === $prefix) {
                    return true;
                }
            }
        }

        return false;
    }

    private function defaultSecure(): bool
    {
        $environment = function_exists('env') ? (string) env('APP_ENV', 'local') : (string) ($_ENV['APP_ENV'] ?? 'local');
        return strtolower($environment) === 'production';
    }
}
