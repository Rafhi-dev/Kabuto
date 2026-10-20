<?php

declare(strict_types=1);

namespace Kabuto\Auth;

final class ArrayTokenRevoker implements AtomicTokenRevoker
{
    /** @var array<string,int> */
    private array $tokens = [];
    /** @var array<string,int> */
    private array $families = [];
    public function revoke(string $jti, int $expiresAt): void { $this->tokens[$jti] = $expiresAt; }
    public function isRevoked(string $jti): bool
    {
        $expiresAt = $this->tokens[$jti] ?? null;
        if ($expiresAt === null) return false;
        if ($expiresAt <= time()) { unset($this->tokens[$jti]); return false; }
        return true;
    }
    public function consume(string $jti, string $family, int $expiresAt): bool
    {
        if ($this->isFamilyRevoked($family) || $this->isRevoked($jti)) return false;
        $this->tokens[$jti] = $expiresAt;
        return true;
    }
    public function revokeFamily(string $family, int $expiresAt): void { $this->families[$family] = $expiresAt; }
    public function isFamilyRevoked(string $family): bool
    {
        $expiresAt = $this->families[$family] ?? null;
        if ($expiresAt === null) return false;
        if ($expiresAt <= time()) { unset($this->families[$family]); return false; }
        return true;
    }
}
