<?php

declare(strict_types=1);

namespace Kabuto\Middleware;

use Kabuto\Auth\ExpiredTokenException;
use Kabuto\Auth\InvalidTokenException;
use Kabuto\Auth\JWT;
use Kabuto\Auth\JwtException;
use Kabuto\Http\Request;
use Kabuto\Http\Response;

final class JwtAuth
{
    /**
     * @param string|null        $secret           HMAC secret key. If null, resolved from JWT_SECRET or APP_KEY.
     * @param list<string>       $cookieNames      Cookie names to check if Authorization header is absent.
     * @param string             $requestAttribute Request attribute key to store decoded user payload.
     */
    public function __construct(
        private readonly ?string $secret = null,
        private readonly array $cookieNames = ['token', 'jwt', 'access_token'],
        private readonly string $requestAttribute = 'user',
    ) {
    }

    public function __invoke(Request $req, Response $res, callable $next): Response
    {
        $token = $this->extractToken($req);

        if ($token === null || $token === '') {
            return (new Response())
                ->status(401)
                ->json([
                    'statusCode' => 401,
                    'error' => 'Unauthorized',
                    'message' => 'Authentication token missing.',
                ]);
        }

        try {
            $payload = JWT::decode($token, $this->secret);
        } catch (ExpiredTokenException) {
            return (new Response())
                ->status(401)
                ->json([
                    'statusCode' => 401,
                    'error' => 'Unauthorized',
                    'message' => 'Authentication token expired.',
                ]);
        } catch (InvalidTokenException|JwtException $e) {
            return (new Response())
                ->status(401)
                ->json([
                    'statusCode' => 401,
                    'error' => 'Unauthorized',
                    'message' => 'Invalid authentication token: ' . $e->getMessage(),
                ]);
        }

        $req->set($this->requestAttribute, $payload);

        return $next($req, $res);
    }

    public function extractToken(Request $req): ?string
    {
        // 1. Authorization: Bearer <token>
        $authHeader = $req->header('Authorization') ?? $req->header('authorization');
        if ($authHeader !== null && preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $matches)) {
            return trim($matches[1]);
        }

        // 2. Cookie fallback ('token', 'jwt', 'access_token')
        foreach ($this->cookieNames as $cookieName) {
            $cookieVal = $req->cookie($cookieName);
            if ($cookieVal !== null && $cookieVal !== '') {
                return $cookieVal;
            }
        }

        return null;
    }
}
