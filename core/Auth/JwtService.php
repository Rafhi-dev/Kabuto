<?php

declare(strict_types=1);

namespace Kabuto\Auth;

final class JwtService
{
    public function __construct(private readonly JwtManager $manager) {}

    /** @param array<string,mixed> $claims @return array{access_token:string,refresh_token:string} */
    public function issuePair(array $claims): array
    {
        return ['access_token' => $this->manager->issueAccess($claims), 'refresh_token' => $this->manager->issueRefresh($claims)];
    }

    /** @return array{access_token:string,refresh_token:string} */
    public function refresh(string $refreshToken): array
    {
        return $this->manager->rotate($refreshToken);
    }

    public function revoke(string $token): void
    {
        $this->manager->revoke($token);
    }
}
