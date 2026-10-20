<?php

declare(strict_types=1);

namespace Kabuto\Cache;

use Closure;
use Kabuto\Observability\MetricsRegistry;

final class Cache
{
    private static ?CacheStore $store = null;
    private static ?MetricsRegistry $metrics = null;

    public static function setStore(CacheStore $store): void { self::$store = $store; }
    public static function setMetrics(?MetricsRegistry $metrics): void { self::$metrics = $metrics; }
    public static function store(): CacheStore { return self::$store ??= new MemoryCacheStore(); }
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::store()->get($key, $default);
        self::$metrics?->increment($value === $default ? 'kabuto_cache_misses_total' : 'kabuto_cache_hits_total');
        return $value;
    }
    public static function put(string $key, mixed $value, int $ttl = 3600): bool { return self::store()->put($key, $value, $ttl); }
    public static function forget(string $key): bool { return self::store()->forget($key); }
    public static function has(string $key): bool { return self::store()->has($key); }
    public static function clear(): bool { return self::store()->clear(); }
    public static function increment(string $key, int $amount = 1): int { return self::store()->increment($key, $amount); }
    public static function decrement(string $key, int $amount = 1): int { return self::store()->decrement($key, $amount); }
    public static function remember(string $key, int $ttl, Closure $resolver): mixed
    {
        if (self::has($key)) return self::get($key);
        $value = $resolver(); self::put($key, $value, $ttl); return $value;
    }
    public static function reset(): void { self::$store = null; self::$metrics = null; }
}
