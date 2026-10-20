<?php

declare(strict_types=1);

namespace Kabuto\Cache;

final class MemoryCacheStore implements CacheStore
{
    /** @var array<string,array{value:mixed,expires:int}> */
    private array $items = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->has($key)) return $default;
        return $this->items[$key]['value'];
    }

    public function put(string $key, mixed $value, int $ttl = 3600): bool
    {
        $this->items[$key] = ['value' => $value, 'expires' => $ttl > 0 ? time() + $ttl : 0];
        return true;
    }

    public function forget(string $key): bool
    {
        $exists = array_key_exists($key, $this->items);
        unset($this->items[$key]);
        return $exists;
    }

    public function has(string $key): bool
    {
        if (!array_key_exists($key, $this->items)) return false;
        if ($this->items[$key]['expires'] > 0 && $this->items[$key]['expires'] <= time()) {
            unset($this->items[$key]);
            return false;
        }
        return true;
    }

    public function clear(): bool { $this->items = []; return true; }

    public function increment(string $key, int $amount = 1): int
    {
        $value = (int) $this->get($key, 0) + $amount;
        $this->put($key, $value);
        return $value;
    }

    public function decrement(string $key, int $amount = 1): int { return $this->increment($key, -$amount); }
}
