<?php

declare(strict_types=1);

namespace Kabuto\Auth;

final class RedisTokenRevoker implements AtomicTokenRevoker
{
    public function __construct(private readonly object $redis, private readonly string $prefix = 'kabuto:jwt:revoked:') {}

    public function revoke(string $jti, int $expiresAt): void
    {
        $ttl = max(1, $expiresAt - time());
        if (method_exists($this->redis, 'setex')) {
            $this->redis->setex($this->prefix . $jti, $ttl, '1');
            return;
        }
        if (method_exists($this->redis, 'set')) {
            $this->redis->set($this->prefix . $jti, '1', ['EX' => $ttl]);
            return;
        }
        throw new \RuntimeException('Redis client does not support expiring token revocations.');
    }

    public function isRevoked(string $jti): bool
    {
        $value = $this->redis->get($this->prefix . $jti);
        return $value !== false && $value !== null;
    }

    public function consume(string $jti, string $family, int $expiresAt): bool
    {
        if ($family !== '' && $this->isFamilyRevoked($family)) return false;
        $key = $this->prefix . $jti;
        $ttl = max(1, $expiresAt - time());
        if (method_exists($this->redis, 'set')) {
            $result = $this->redis->set($key, '1', ['nx', 'ex' => $ttl]);
            return $result === true || $result === 'OK';
        }
        if (method_exists($this->redis, 'setnx')) {
            $won = $this->redis->setnx($key, '1') === 1;
            if ($won) $this->redis->expire($key, $ttl);
            return $won;
        }
        throw new \RuntimeException('Redis client needs atomic SET NX EX or SETNX support for refresh rotation.');
    }

    public function revokeFamily(string $family, int $expiresAt): void
    {
        $this->revoke('family:' . $family, $expiresAt);
    }

    public function isFamilyRevoked(string $family): bool
    {
        return $this->isRevoked('family:' . $family);
    }
}
