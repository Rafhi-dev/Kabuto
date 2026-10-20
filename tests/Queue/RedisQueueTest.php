<?php

declare(strict_types=1);

namespace Kabuto\Tests\Queue;

use Kabuto\Queue\Job;
use Kabuto\Queue\RedisQueue;
use Kabuto\Tests\TestCase;

final class RedisQueueTest extends TestCase
{
    public function testJobCanBeDispatchedAndWorked(): void
    {
        $redis = new class {
            public array $hashes = []; public array $sorted = [];
            public function hSet(string $key, string $field, string $value): int { $this->hashes[$key][$field] = $value; return 1; }
            public function hGet(string $key, string $field): string|false { return $this->hashes[$key][$field] ?? false; }
            public function hDel(string $key, string $field): int { unset($this->hashes[$key][$field]); return 1; }
            public function hGetAll(string $key): array { return $this->hashes[$key] ?? []; }
            public function zAdd(string $key, int $score, string $member): int { $this->sorted[$key][$member] = $score; return 1; }
            public function zRangeByScore(string $key, string $min, string $max, array $options = []): array { foreach ($this->sorted[$key] ?? [] as $id => $score) if ($score <= (int) $max) return [$id]; return []; }
            public function zRem(string $key, string $member): int { unset($this->sorted[$key][$member]); return 1; }
        };
        RedisQueueTestJob::$runs = 0;
        $job = new RedisQueueTestJob();
        $queue = new RedisQueue($redis, 'default', 'app:');
        $queue->dispatch($job);
        self::assertTrue($queue->workOnce());
        self::assertSame(1, RedisQueueTestJob::$runs);
    }
}

final class RedisQueueTestJob implements Job
{
    public static int $runs = 0;
    public function handle(): void { self::$runs++; }
}
