<?php

declare(strict_types=1);

namespace Kabuto\Auth;

use JsonException;

/**
 * Native RFC 7519 JSON Web Token (JWT) implementation using HMAC-SHA256 (HS256).
 * Zero external dependencies.
 */
final class JWT
{
    public const DEFAULT_ALGO = 'HS256';

    /**
     * Encode and sign a payload into a JWT string.
     *
     * @param array<string, mixed> $payload
     * @param string|null          $secret  HMAC key. If null, reads JWT_SECRET or APP_KEY from environment.
     * @param int                  $ttl     Time-to-live in seconds (0 = no expiration).
     * @param array<string, mixed> $headers Extra header claims.
     *
     * @throws JwtException
     */
    public static function sign(
        array $payload,
        ?string $secret = null,
        int $ttl = 3600,
        array $headers = [],
    ): string {
        return self::encode($payload, $secret, $ttl, $headers);
    }

    /**
     * Encode and sign a payload into a JWT string.
     *
     * @param array<string, mixed> $payload
     * @param string|null          $secret
     * @param int                  $ttl
     * @param array<string, mixed> $headers
     *
     * @throws JwtException
     */
    public static function encode(
        array $payload,
        ?string $secret = null,
        int $ttl = 3600,
        array $headers = [],
    ): string {
        $secretKey = self::resolveSecret($secret);
        self::validateSecret($secretKey);

        $now = time();
        if (!isset($payload['iat'])) {
            $payload['iat'] = $now;
        }

        if ($ttl !== 0 && !isset($payload['exp'])) {
            $payload['exp'] = $now + $ttl;
        }

        $header = array_merge([
            'typ' => 'JWT',
            'alg' => self::DEFAULT_ALGO,
        ], $headers);

        try {
            $headerJson = json_encode($header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new InvalidTokenException('Failed to encode JWT data to JSON: ' . $e->getMessage(), 0, $e);
        }

        $headerEncoded = self::base64UrlEncode($headerJson);
        $payloadEncoded = self::base64UrlEncode($payloadJson);

        $signature = hash_hmac('sha256', "{$headerEncoded}.{$payloadEncoded}", $secretKey, true);
        $signatureEncoded = self::base64UrlEncode($signature);

        return "{$headerEncoded}.{$payloadEncoded}.{$signatureEncoded}";
    }

    /**
     * Decode and verify a JWT token string.
     *
     * @param string      $token
     * @param string|null $secret
     * @param int         $leeway Clock skew tolerance in seconds.
     *
     * @return array<string, mixed> The decoded payload.
     *
     * @throws JwtException
     */
    public static function decode(string $token, ?string $secret = null, int $leeway = 0, ?string $issuer = null, ?string $audience = null, bool $requireSubject = false): array
    {
        $parts = explode('.', trim($token));
        if (count($parts) !== 3) {
            throw new InvalidTokenException('Invalid JWT structure: token must contain 3 segments.');
        }

        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

        $headerJson = self::base64UrlDecode($headerEncoded);
        $payloadJson = self::base64UrlDecode($payloadEncoded);
        $actualSig = self::base64UrlDecode($signatureEncoded);

        if ($headerJson === null || $payloadJson === null || $actualSig === null) {
            throw new InvalidTokenException('Failed to base64url-decode JWT components.');
        }

        try {
            /** @var array<string, mixed> $header */
            $header = json_decode($headerJson, true, 512, JSON_THROW_ON_ERROR);
            /** @var array<string, mixed> $payload */
            $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidTokenException('Failed to parse JWT JSON: ' . $e->getMessage(), 0, $e);
        }

        if (($header['alg'] ?? null) !== self::DEFAULT_ALGO) {
            throw new InvalidTokenException(sprintf(
                'Unsupported JWT algorithm [%s], expected [%s].',
                $header['alg'] ?? 'none',
                self::DEFAULT_ALGO,
            ));
        }

        $secretKey = self::resolveSecret($secret);
        self::validateSecret($secretKey);
        $expectedSig = hash_hmac('sha256', "{$headerEncoded}.{$payloadEncoded}", $secretKey, true);

        if (!hash_equals($expectedSig, $actualSig)) {
            throw new InvalidTokenException('Invalid JWT signature.');
        }

        if ($requireSubject && (!isset($payload['sub']) || !is_scalar($payload['sub']) || (string) $payload['sub'] === '')) {
            throw new InvalidTokenException('JWT subject (sub) is required.');
        }
        if ($issuer !== null && ($payload['iss'] ?? null) !== $issuer) throw new InvalidTokenException('Invalid JWT issuer.');
        if ($audience !== null) {
            $audiences = is_array($payload['aud'] ?? null) ? $payload['aud'] : [$payload['aud'] ?? null];
            if (!in_array($audience, $audiences, true)) throw new InvalidTokenException('Invalid JWT audience.');
        }

        $now = time();

        // Check Not-Before claim (nbf)
        if (isset($payload['nbf']) && is_numeric($payload['nbf'])) {
            if ((int) $payload['nbf'] > ($now + $leeway)) {
                throw new InvalidTokenException('Token is not yet active (nbf).');
            }
        }

        // Check Expiration claim (exp)
        if (isset($payload['exp']) && is_numeric($payload['exp'])) {
            if ((int) $payload['exp'] < ($now - $leeway)) {
                throw new ExpiredTokenException('Token has expired.');
            }
        }

        return $payload;
    }

    /**
     * Safely verify a JWT token. Returns the decoded payload array, or null if invalid/expired.
     *
     * @param string      $token
     * @param string|null $secret
     * @param int         $leeway
     *
     * @return array<string, mixed>|null
     */
    public static function verify(string $token, ?string $secret = null, int $leeway = 0): ?array
    {
        try {
            return self::decode($token, $secret, $leeway);
        } catch (JwtException) {
            return null;
        }
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): ?string
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded !== false ? $decoded : null;
    }

    /**
     * Resolves the secret key from explicit argument or environment variables.
     *
     * @throws JwtException
     */
    public static function resolveSecret(?string $secret = null): string
    {
        if ($secret !== null && $secret !== '') {
            return $secret;
        }

        $envSecret = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: null;
        if (is_string($envSecret) && $envSecret !== '') {
            return $envSecret;
        }

        $appKey = $_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: null;
        if (is_string($appKey) && $appKey !== '') {
            return $appKey;
        }

        throw new JwtException('JWT secret is not configured. Set JWT_SECRET or APP_KEY in your environment.');
    }

    private static function validateSecret(string $secret): void
    {
        if (strlen($secret) < 32) {
            throw new JwtException('JWT secret must be at least 32 bytes long.');
        }
    }
}
