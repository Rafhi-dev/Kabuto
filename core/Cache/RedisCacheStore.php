<?php

declare(strict_types=1);

namespace Kabuto\Cache;

use RuntimeException;

/** Adapter for ext-redis; Redis remains an optional runtime dependency. */
final class RedisCacheStore implements CacheStore
{
    public function __construct(private readonly object $redis, private readonly string $prefix = 'kabuto:') {}
    private function key(string $key): string { return $this->prefix . $key; }
    public function get(string $key, mixed $default = null): mixed { $value = $this->redis->get($this->key($key)); return $value === false ? $default : unserialize((string) $value, ['allowed_classes' => true]); }
    public function put(string $key, mixed $value, int $ttl = 3600): bool { return (bool) $this->redis->setex($this->key($key), max(1, $ttl), serialize($value)); }
    public function forget(string $key): bool { return (bool) $this->redis->del($this->key($key)); }
    public function has(string $key): bool { return (bool) $this->redis->exists($this->key($key)); }
    public function clear(): bool
    {
        if (!method_exists($this->redis, 'scan')) throw new RuntimeException('Redis client does not support SCAN.');
        $iterator = null; $deleted = 0; $pattern = $this->prefix . '*';
        do {
            $keys = $this->redis->scan($iterator, $pattern, 250);
            if (is_array($keys) && $keys !== []) $deleted += (int) $this->redis->del($keys);
        } while ($iterator !== 0 && $iterator !== null);
        return $deleted >= 0;
    }
    public function increment(string $key, int $amount = 1): int { return (int) $this->redis->incrBy($this->key($key), $amount); }
    public function decrement(string $key, int $amount = 1): int { return (int) $this->redis->decrBy($this->key($key), $amount); }
}
