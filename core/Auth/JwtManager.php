<?php

declare(strict_types=1);

namespace Kabuto\Auth;

use RuntimeException;

final class JwtManager
{
    public function __construct(
        private readonly string $secret,
        private readonly int $accessTtl = 900,
        private readonly int $refreshTtl = 2_592_000,
        private readonly ?string $issuer = null,
        private readonly ?string $audience = null,
        private readonly ?TokenRevoker $revoker = null,
        private readonly int $leeway = 0,
        private readonly ?int $maxAccessTtl = null,
        private readonly ?int $maxRefreshTtl = null,
    ) {}

    /** @param array<string,mixed> $claims */
    public function issueAccess(array $claims): string { return $this->issue($claims, $this->accessTtl, 'access'); }
    /** @param array<string,mixed> $claims */
    public function issueRefresh(array $claims): string { return $this->issue($claims, $this->refreshTtl, 'refresh'); }

    /** @return array{access_token:string,refresh_token:string} */
    public function rotate(string $refreshToken): array
    {
        $payload = $this->decode($refreshToken, 'refresh', false);
        $family = (string) ($payload['family'] ?? $payload['jti'] ?? '');
        if ($this->revoker !== null && $family !== '' && $this->revoker->isFamilyRevoked($family)) {
            throw new InvalidTokenException('Refresh token family has been revoked.');
        }
        if (($payload['rotated'] ?? false) === true) throw new InvalidTokenException('Refresh token has already been used.');
        if (!$this->revoker instanceof AtomicTokenRevoker) throw new RuntimeException('Refresh-token rotation requires an AtomicTokenRevoker.');
        $jti = (string) ($payload['jti'] ?? throw new InvalidTokenException('Refresh token does not contain jti.'));
        $expiresAt = (int) ($payload['exp'] ?? time());
        if (!$this->revoker->consume($jti, $family, $expiresAt)) {
            if ($family !== '') $this->revoker->revokeFamily($family, (int) ($payload['family_exp'] ?? $expiresAt));
            throw new InvalidTokenException('Refresh token reuse detected; token family has been revoked.');
        }
        $claims = $payload;
        unset($claims['iat'], $claims['exp'], $claims['jti'], $claims['typ'], $claims['rotated']);
        $claims['family'] = (string) ($payload['family'] ?? $payload['jti']);
        return ['access_token' => $this->issueAccess($claims), 'refresh_token' => $this->issueRefresh($claims)];
    }

    /** @return array<string,mixed> */
    public function verify(string $token, ?string $type = null): array
    {
        return $this->decode($token, $type, true);
    }

    /** @return array<string,mixed> */
    private function decode(string $token, ?string $type, bool $checkRevocation): array
    {
        $payload = JWT::decode($token, $this->secret, $this->leeway, $this->issuer, $this->audience, true);
        if ($type !== null && ($payload['typ'] ?? null) !== $type) throw new InvalidTokenException('Unexpected JWT token type.');
        if ($checkRevocation && $this->revoker !== null) {
            $family = (string) ($payload['family'] ?? '');
            if ($family !== '' && $this->revoker->isFamilyRevoked($family)) throw new InvalidTokenException('JWT token family has been revoked.');
            if (isset($payload['jti']) && $this->revoker->isRevoked((string) $payload['jti'])) throw new InvalidTokenException('JWT has been revoked.');
        }
        return $payload;
    }

    public function revoke(string $token): void
    {
        $payload = $this->verify($token);
        $this->revokePayload($payload);
    }

    /** @param array<string,mixed> $payload */
    private function revokePayload(array $payload): void
    {
        if ($this->revoker === null) throw new RuntimeException('JWT revocation requires a TokenRevoker.');
        $this->revoker->revoke((string) ($payload['jti'] ?? throw new InvalidTokenException('JWT does not contain jti.')), (int) ($payload['exp'] ?? time()));
    }

    /** @param array<string,mixed> $claims */
    private function issue(array $claims, int $ttl, string $type): string
    {
        if ($ttl <= 0) throw new RuntimeException('JWT TTL must be positive.');
        $maximum = $type === 'refresh' ? $this->maxRefreshTtl : $this->maxAccessTtl;
        if ($maximum !== null && ($maximum <= 0 || $ttl > $maximum)) throw new RuntimeException("JWT {$type} TTL exceeds configured maximum.");
        $claims['jti'] ??= bin2hex(random_bytes(16));
        $claims['typ'] = $type;
        $claims['family'] ??= $claims['jti'];
        if ($type === 'refresh') {
            $claims['iat'] ??= time();
            $claims['family_exp'] ??= (int) $claims['iat'] + $ttl;
            $claims['exp'] ??= (int) $claims['family_exp'];
        }
        if ($this->issuer !== null) $claims['iss'] = $this->issuer;
        if ($this->audience !== null) $claims['aud'] = $this->audience;
        if ($maximum !== null && isset($claims['exp']) && (int) $claims['exp'] > (int) $claims['iat'] + $maximum) throw new RuntimeException("JWT {$type} expiration exceeds configured maximum TTL.");
        return JWT::encode($claims, $this->secret, $ttl);
    }
}
