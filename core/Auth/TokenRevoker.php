<?php

declare(strict_types=1);

namespace Kabuto\Auth;

interface TokenRevoker
{
    public function revoke(string $jti, int $expiresAt): void;
    public function isRevoked(string $jti): bool;
    public function revokeFamily(string $family, int $expiresAt): void;
    public function isFamilyRevoked(string $family): bool;
}
