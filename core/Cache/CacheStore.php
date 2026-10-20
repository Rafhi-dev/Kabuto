<?php

declare(strict_types=1);

namespace Kabuto\Cache;

interface CacheStore
{
    public function get(string $key, mixed $default = null): mixed;
    public function put(string $key, mixed $value, int $ttl = 3600): bool;
    public function forget(string $key): bool;
    public function has(string $key): bool;
    public function clear(): bool;
    public function increment(string $key, int $amount = 1): int;
    public function decrement(string $key, int $amount = 1): int;
}
