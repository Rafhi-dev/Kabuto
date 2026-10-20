<?php

declare(strict_types=1);

namespace Kabuto\Tests\Auth;

use Kabuto\Auth\RedisSessionStore;
use Kabuto\Tests\TestCase;

final class RedisSessionStoreTest extends TestCase
{
    public function testSessionDataIsStoredWithTtl(): void
    {
        $redis = new class {
            public array $items = [];
            public function setex(string $key, int $ttl, string $value): bool { $this->items[$key] = $value; return true; }
            public function get(string $key): string|false { return $this->items[$key] ?? false; }
            public function del(string $key): int { unset($this->items[$key]); return 1; }
        };
        $store = new RedisSessionStore($redis);
        $store->write('session', ['user' => 7], time() + 60);
        self::assertSame(['user' => 7], $store->read('session'));
        $store->delete('session');
        self::assertNull($store->read('session'));
    }
}
