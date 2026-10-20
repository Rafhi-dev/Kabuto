<?php

declare(strict_types=1);

namespace Kabuto\Auth;

interface AtomicTokenRevoker extends TokenRevoker
{
    /** Atomically mark a refresh JTI consumed; returns false if already consumed or family revoked. */
    public function consume(string $jti, string $family, int $expiresAt): bool;
}
