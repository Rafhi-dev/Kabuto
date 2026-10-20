<?php

declare(strict_types=1);

namespace Kabuto\Storage;

final class RedisRateLimitStore implements RateLimitStore
{
    public function __construct(private readonly object $redis, private readonly string $prefix = 'kabuto:rate:') {}
    public function hit(string $key, int $decaySeconds): array
    {
        $key = $this->prefix . hash('sha256', $key);
        $count = (int) $this->redis->incr($key);
        if ($count === 1) $this->redis->expire($key, max(1, $decaySeconds));
        $ttl = (int) $this->redis->ttl($key);
        return ['count' => $count, 'reset' => time() + max(1, $ttl)];
    }
}
