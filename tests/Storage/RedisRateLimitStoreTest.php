<?php

declare(strict_types=1);

namespace Kabuto\Tests\Storage;

use Kabuto\Storage\RedisRateLimitStore;
use Kabuto\Tests\TestCase;

final class RedisRateLimitStoreTest extends TestCase
{
    public function testRedisAdapterUsesAtomicCounter(): void
    {
        $redis = new class {
            public int $count = 0; public function incr(string $key): int { return ++$this->count; }
            public function expire(string $key, int $ttl): bool { return true; }
            public function ttl(string $key): int { return 60; }
        };
        $store = new RedisRateLimitStore($redis);
        self::assertSame(1, $store->hit('ip', 60)['count']);
        self::assertSame(2, $store->hit('ip', 60)['count']);
    }
}
