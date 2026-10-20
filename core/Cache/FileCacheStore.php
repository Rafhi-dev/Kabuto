<?php

declare(strict_types=1);

namespace Kabuto\Cache;

final class FileCacheStore implements CacheStore
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory)) @mkdir($directory, 0755, true);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $path = $this->path($key);
        if (!is_file($path)) return $default;
        $payload = @unserialize((string) file_get_contents($path), ['allowed_classes' => true]);
        if (!is_array($payload) || ((int) ($payload['expires'] ?? 0) > 0 && (int) $payload['expires'] <= time())) {
            @unlink($path);
            return $default;
        }
        return $payload['value'] ?? $default;
    }

    public function put(string $key, mixed $value, int $ttl = 3600): bool
    {
        return file_put_contents($this->path($key), serialize(['value' => $value, 'expires' => $ttl > 0 ? time() + $ttl : 0]), LOCK_EX) !== false;
    }

    public function forget(string $key): bool { $path = $this->path($key); return is_file($path) && unlink($path); }
    public function has(string $key): bool { $marker = new \stdClass(); return $this->get($key, $marker) !== $marker; }
    public function clear(): bool { foreach (glob($this->directory . '/*.cache') ?: [] as $file) @unlink($file); return true; }
    public function increment(string $key, int $amount = 1): int { $value = (int) $this->get($key, 0) + $amount; $this->put($key, $value); return $value; }
    public function decrement(string $key, int $amount = 1): int { return $this->increment($key, -$amount); }

    private function path(string $key): string { return rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.cache'; }
}
