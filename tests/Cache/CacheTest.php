<?php

declare(strict_types=1);

namespace Kabuto\Tests\Cache;

use Kabuto\Cache\Cache;
use Kabuto\Cache\FileCacheStore;
use Kabuto\Cache\MemoryCacheStore;
use Kabuto\Tests\TestCase;

final class CacheTest extends TestCase
{
    public function testMemoryStoreSupportsTtlRememberAndCounters(): void
    {
        $store = new MemoryCacheStore(); Cache::setStore($store);
        self::assertTrue(Cache::put('name', 'Kabuto', 60));
        self::assertSame('Kabuto', Cache::get('name'));
        $calls = 0;
        self::assertSame('Kabuto', Cache::remember('name', 60, static function () use (&$calls): string { $calls++; return 'other'; }));
        self::assertSame(0, $calls);
        self::assertSame(2, Cache::increment('counter', 2));
        self::assertSame(1, Cache::decrement('counter'));
        Cache::reset();
    }

    public function testFileStorePersistsAndClearsValues(): void
    {
        $dir = sys_get_temp_dir() . '/kabuto-cache-' . uniqid();
        $store = new FileCacheStore($dir);
        self::assertTrue($store->put('object', ['ok' => true], 60));
        self::assertSame(['ok' => true], $store->get('object'));
        self::assertTrue($store->clear());
        self::assertFalse($store->has('object'));
        @rmdir($dir);
    }
}
