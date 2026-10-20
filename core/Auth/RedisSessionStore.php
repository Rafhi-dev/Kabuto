<?php

declare(strict_types=1);

namespace Kabuto\Auth;

final class RedisSessionStore implements SessionStore
{
    public function __construct(private readonly object $redis, private readonly string $prefix = 'kabuto:session:') {}
    private function key(string $id): string { return $this->prefix . hash('sha256', $id); }
    public function read(string $id): ?array
    {
        $value = $this->redis->get($this->key($id));
        if ($value === false || $value === null) return null;
        $data = json_decode((string) $value, true);
        return is_array($data) ? $data : null;
    }
    public function write(string $id, array $data, int $expiresAt): void
    {
        $ttl = max(1, $expiresAt - time());
        $this->redis->setex($this->key($id), $ttl, json_encode($data, JSON_THROW_ON_ERROR));
    }
    public function delete(string $id): void { $this->redis->del($this->key($id)); }
}
