<?php

declare(strict_types=1);

namespace Kabuto\Tests\Fixtures;

final class FakeRedis
{
    public array $strings = [];
    public array $hashes = [];
    public array $sets = [];
    public array $published = [];
    public array $expires = [];

    public function get(string $key): string|false { return $this->strings[$key] ?? false; }
    public function setex(string $key, int $ttl, string $value): bool { $this->strings[$key] = $value; $this->expires[$key] = time() + $ttl; return true; }
    public function del(string|array $keys): int { $keys = is_array($keys) ? $keys : [$keys]; $count = 0; foreach ($keys as $key) { if (isset($this->strings[$key])) { unset($this->strings[$key]); $count++; } unset($this->hashes[$key], $this->sets[$key]); } return $count; }
    public function exists(string $key): bool { return isset($this->strings[$key]); }
    public function hSet(string $key, string $field, string $value): int { $this->hashes[$key][$field] = $value; return 1; }
    public function hGet(string $key, string $field): string|false { return $this->hashes[$key][$field] ?? false; }
    public function hGetAll(string $key): array { return $this->hashes[$key] ?? []; }
    public function hDel(string $key, string $field): int { unset($this->hashes[$key][$field]); return 1; }
    public function incr(string $key): int { return $this->strings[$key] = ((int) ($this->strings[$key] ?? 0)) + 1; }
    public function incrBy(string $key, int $amount): int { return $this->strings[$key] = ((int) ($this->strings[$key] ?? 0)) + $amount; }
    public function decrBy(string $key, int $amount): int { return $this->strings[$key] = ((int) ($this->strings[$key] ?? 0)) - $amount; }
    public function expire(string $key, int $ttl): bool { $this->expires[$key] = time() + $ttl; return true; }
    public function ttl(string $key): int { return max(1, (int) (($this->expires[$key] ?? time() + 60) - time())); }
    public function publish(string $channel, string $payload): int { $this->published[] = [$channel, $payload]; return 1; }
    public function scan(&$iterator, string $pattern, int $count = 100): array { $iterator = 0; return array_values(array_filter(array_keys($this->strings), static fn (string $key): bool => str_ends_with($pattern, '*') ? str_starts_with($key, rtrim($pattern, '*')) : $key === $pattern)); }
}
