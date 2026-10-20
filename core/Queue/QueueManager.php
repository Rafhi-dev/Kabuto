<?php

declare(strict_types=1);

namespace Kabuto\Queue;

use Kabuto\Database\ConnectionManager;
use Kabuto\Redis\RedisConnectionFactory;

final class QueueManager
{
    public function __construct(private readonly ConnectionManager $connections) {}

    public function connection(string $datasource = 'default', string $queue = 'default'): DatabaseQueue
    {
        return new DatabaseQueue($this->connections->connection($datasource), $queue);
    }

    public function redis(string $queue = 'default', ?RedisConnectionFactory $factory = null): RedisQueue
    {
        return new RedisQueue(($factory ?? new RedisConnectionFactory())->make(), $queue);
    }

    public function configured(string $queue = 'default', ?RedisConnectionFactory $factory = null): DatabaseQueue|RedisQueue
    {
        return strtolower((string) (function_exists('config') ? config('queue.driver', 'database') : 'database')) === 'redis'
            ? $this->redis($queue, $factory)
            : $this->connection(queue: $queue);
    }

    /** @return array{depth:int,failed:int} */
    public function metrics(string $queue = 'default', ?RedisConnectionFactory $factory = null): array
    {
        return $this->configured($queue, $factory)->metrics();
    }

    public function health(string $queue = 'default', ?RedisConnectionFactory $factory = null): bool
    {
        return $this->probe($queue, $factory)['ok'];
    }

    /**
     * Read queue health and counts without constructing DatabaseQueue, whose constructor
     * provisions the queue table. Health checks must not mutate application state.
     *
     * @return array{ok:bool,depth:int,failed:int,error?:string}
     */
    public function probe(string $queue = 'default', ?RedisConnectionFactory $factory = null): array
    {
        if (strtolower((string) (function_exists('config') ? config('queue.driver', 'database') : 'database')) === 'redis') {
            try {
                $redisQueue = $this->redis($queue, $factory);
                $counts = $redisQueue->metrics();
                $healthy = $redisQueue->health();
                return ['ok' => $healthy, 'depth' => $counts['depth'], 'failed' => $counts['failed']];
            } catch (\Throwable $exception) {
                return ['ok' => false, 'depth' => 0, 'failed' => 0, 'error' => $exception->getMessage()];
            }
        }

        try {
            $pdo = $this->connections->connection();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM kabuto_queue_jobs WHERE queue = ? AND failed_at IS NULL AND reserved_at IS NULL AND available_at <= ?');
            $stmt->execute([$queue, time()]);
            $depth = (int) $stmt->fetchColumn();
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM kabuto_queue_jobs WHERE queue = ? AND failed_at IS NOT NULL');
            $stmt->execute([$queue]);
            return ['ok' => true, 'depth' => $depth, 'failed' => (int) $stmt->fetchColumn()];
        } catch (\Throwable $exception) {
            return ['ok' => false, 'depth' => 0, 'failed' => 0, 'error' => $exception->getMessage()];
        }
    }
}
