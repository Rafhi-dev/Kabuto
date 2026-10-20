<?php

declare(strict_types=1);

namespace Kabuto\Queue;

use RuntimeException;
use Throwable;

final class RedisQueue
{
    private string $ready;
    private string $reserved;
    private string $failed;

    public function __construct(private readonly object $redis, private readonly string $queue = 'default', private readonly string $prefix = 'kabuto:queue:')
    {
        $base = $this->prefix . $this->queue;
        $this->ready = $base . ':ready'; $this->reserved = $base . ':reserved'; $this->failed = $base . ':failed';
    }

    public function dispatch(Job $job, int $delay = 0): string
    {
        $id = bin2hex(random_bytes(16));
        $payload = json_encode(['id' => $id, 'payload' => base64_encode(serialize($job)), 'attempts' => 0, 'available_at' => time() + max(0, $delay), 'last_error' => null], JSON_THROW_ON_ERROR);
        $this->redis->hSet($this->reserved, $id, $payload);
        $this->redis->zAdd($this->ready, time() + max(0, $delay), $id);
        return $id;
    }

    /** @return array<string,mixed>|null */
    public function reserve(int $visibilityTimeout = 60): ?array
    {
        $this->recoverExpired();
        $id = $this->claimReadyId();
        if ($id === null) return null;
        $raw = $this->redis->hGet($this->reserved, $id);
        if (!is_string($raw)) return null;
        $row = json_decode($raw, true);
        if (!is_array($row)) return null;
        $row['attempts'] = ((int) ($row['attempts'] ?? 0)) + 1;
        $row['reserved_until'] = time() + max(1, $visibilityTimeout);
        $this->redis->hSet($this->reserved, $id, json_encode($row, JSON_THROW_ON_ERROR));
        return $row;
    }

    private function claimReadyId(): ?string
    {
        if (method_exists($this->redis, 'eval')) {
            $script = <<<'LUA'
local ids = redis.call('ZRANGE', KEYS[1], 0, 0, 'BYSCORE', '-inf', ARGV[1])
if #ids == 0 then return false end
if redis.call('ZREM', KEYS[1], ids[1]) == 1 then return ids[1] end
return false
LUA;
            $id = $this->redis->eval($script, [$this->ready, time()], 1);
            return is_string($id) && $id !== '' ? $id : null;
        }
        $ids = $this->redis->zRangeByScore($this->ready, '-inf', (string) time(), ['limit' => [0, 1]]);
        $id = $ids[0] ?? null;
        return is_string($id) && $this->redis->zRem($this->ready, $id) === 1 ? $id : null;
    }

    private function recoverExpired(): void
    {
        $now = time();
        foreach ($this->decodeHash($this->redis->hGetAll($this->reserved)) as $row) {
            if ((int) ($row['reserved_until'] ?? PHP_INT_MAX) <= $now) {
                $id = (string) ($row['id'] ?? '');
                if ($id !== '') $this->redis->zAdd($this->ready, $now, $id);
            }
        }
    }

    public function workOnce(int $maxAttempts = 3, int $retryDelay = 5, int $timeout = 60): bool
    {
        $row = $this->reserve($timeout); if ($row === null) return false;
        $id = (string) $row['id'];
        $job = null;
        try {
            $job = unserialize(base64_decode((string) $row['payload'], true) ?: '', ['allowed_classes' => true]);
            if (!$job instanceof Job) throw new RuntimeException('Queue payload does not contain a valid Job.');
            $job->handle(); $this->redis->hDel($this->reserved, $id);
        } catch (Throwable $e) {
            $row['last_error'] = $e->getMessage();
            $effectiveMaxAttempts = $job instanceof QueueableJob ? max(1, $job->maxAttempts()) : $maxAttempts;
            $effectiveRetryDelay = $job instanceof QueueableJob ? max(0, $job->retryDelay()) : max(0, $retryDelay);
            if ((int) $row['attempts'] >= $effectiveMaxAttempts) {
                if ($job instanceof QueueableJob) {
                    try { $job->failed($e); } catch (Throwable) { }
                }
                $this->redis->hSet($this->failed, $id, json_encode($row, JSON_THROW_ON_ERROR));
                $this->redis->hDel($this->reserved, $id);
            } else {
                $this->redis->hSet($this->reserved, $id, json_encode($row, JSON_THROW_ON_ERROR));
                $this->redis->zAdd($this->ready, time() + $effectiveRetryDelay, $id);
            }
        }
        return true;
    }

    /** @return list<array<string,mixed>> */
    public function failed(): array { return $this->decodeHash($this->redis->hGetAll($this->failed)); }
    /** @return array{depth:int,failed:int} */
    public function metrics(): array
    {
        $depth = method_exists($this->redis, 'zCard') ? (int) $this->redis->zCard($this->ready) : count($this->redis->zRangeByScore($this->ready, '-inf', (string) time()));
        $failed = method_exists($this->redis, 'hLen') ? (int) $this->redis->hLen($this->failed) : count($this->redis->hGetAll($this->failed));
        return ['depth' => $depth, 'failed' => $failed];
    }
    public function health(): bool
    {
        try { return method_exists($this->redis, 'ping') ? ($this->redis->ping() === true || $this->redis->ping() === '+PONG') : method_exists($this->redis, 'hGetAll'); } catch (Throwable) { return false; }
    }
    public function retry(string $id, ?int $availableAt = null): void { $row = $this->redis->hGet($this->failed, $id); if ($row === false) return; $this->redis->hDel($this->failed, $id); $this->redis->hSet($this->reserved, $id, $row); $this->redis->zAdd($this->ready, $availableAt ?? time(), $id); }
    /** @return list<array<string,mixed>> */
    private function decodeHash(array $items): array { return array_values(array_filter(array_map(static fn ($v) => is_string($v) ? json_decode($v, true) : null, $items), 'is_array')); }
}
