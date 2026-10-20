<?php

declare(strict_types=1);

namespace Kabuto\Scheduler;

use RuntimeException;

final class RedisLockStore implements LockStore
{
    public function __construct(private readonly object $redis, private readonly string $prefix = 'kabuto:schedule:lock:')
    {
    }

    public function acquire(string $key, string $owner, int $ttlSeconds): bool
    {
        $result = $this->redis->set($this->prefix . $key, $owner, ['NX', 'EX' => max(1, $ttlSeconds)]);
        return $result === true || $result === 'OK';
    }

    public function release(string $key, string $owner): bool
    {
        if (!method_exists($this->redis, 'eval')) {
            throw new RuntimeException('Redis scheduler locks require atomic eval() support for owner-safe release.');
        }

        $script = "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) else return 0 end";
        return (int) $this->redis->eval($script, [$this->prefix . $key, $owner], 1) === 1;
    }
}
