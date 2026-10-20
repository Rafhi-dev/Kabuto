<?php

declare(strict_types=1);

namespace Kabuto\Scheduler;

use Kabuto\Observability\MetricsRegistry;

final class Scheduler
{
    /** @var list<ScheduledTask> */
    private array $tasks = [];
    /** @var list<array{task:string,error:string}> */
    private array $failures = [];
    private readonly LockStore $locks;
    private readonly ScheduleHistoryStore $history;
    private ?\Closure $maintenanceCheck = null;
    private ?MetricsRegistry $metrics = null;

    public function __construct(
        ?LockStore $locks = null,
        ?ScheduleHistoryStore $history = null,
        private readonly int $shutdownGraceSeconds = 5,
    ) {
        if ($shutdownGraceSeconds < 0) {
            throw new \InvalidArgumentException('Scheduler shutdown grace cannot be negative.');
        }
        $this->locks = $locks ?? new ArrayLockStore();
        $this->history = $history ?? new ArrayScheduleHistoryStore();
    }

    public function every(int $seconds, \Closure $callback, string $description = '', ?string $id = null): ScheduledTask
    {
        $task = new ScheduledTask($callback, $seconds, $description, $id);
        foreach ($this->tasks as $registered) {
            if ($registered->id() === $task->id()) {
                throw new \InvalidArgumentException("Schedule task ID [{$task->id()}] is already registered.");
            }
        }
        $this->tasks[] = $task;
        return $task;
    }

    public function duringMaintenance(?callable $check): self
    {
        $this->maintenanceCheck = $check === null ? null : \Closure::fromCallable($check);
        return $this;
    }

    public function withMetrics(?MetricsRegistry $metrics): self
    {
        $this->metrics = $metrics;
        return $this;
    }

    public function runDue(?int $now = null): int
    {
        $now ??= time();
        $count = 0;
        $this->failures = [];
        foreach ($this->tasks as $task) {
            if (!$task->due($now)) {
                continue;
            }
            try {
                if (!$task->runsDuringMaintenance() && $this->maintenanceCheck !== null && ($this->maintenanceCheck)()) {
                    continue;
                }
                if ($this->runTask($task, $now)) {
                    $count++;
                    $this->metrics?->increment('kabuto_scheduler_runs_total', labels: ['status' => 'success']);
                }
            } catch (\Throwable $exception) {
                $task->markAttempted($now);
                $this->failures[] = ['task' => $task->id(), 'error' => $exception->getMessage()];
                $this->metrics?->increment('kabuto_scheduler_failures_total', labels: ['task' => $task->id()]);
            }
        }
        return $count;
    }

    public function runWorker(int $pollSeconds = 1, ?int $maxRuntimeSeconds = null, ?callable $shouldStop = null): int
    {
        if ($pollSeconds < 1 || ($maxRuntimeSeconds !== null && $maxRuntimeSeconds < 1)) {
            throw new \InvalidArgumentException('Schedule worker poll and runtime limits must be positive.');
        }
        $started = time();
        $count = 0;
        while ($shouldStop === null || !$shouldStop()) {
            $count += $this->runDue();
            if ($shouldStop !== null && $shouldStop()) {
                break;
            }
            if ($maxRuntimeSeconds !== null && time() - $started >= $maxRuntimeSeconds) {
                break;
            }
            $sleep = min($pollSeconds, max(1, $this->shutdownGraceSeconds));
            if ($sleep > 0) {
                sleep($sleep);
            }
        }
        return $count;
    }

    /** @return list<array<string,mixed>> */
    public function describe(): array
    {
        return array_map(static fn (ScheduledTask $task): array => [
            'id' => $task->id(),
            'description' => $task->description(),
            'interval' => $task->interval(),
            'retries' => $task->retriesCount(),
            'retry_delay' => $task->retryDelaySeconds(),
            'timeout' => $task->timeoutSeconds(),
            'without_overlapping' => $task->protectsOverlap(),
            'on_one_server' => $task->runsOnOneServer(),
            'even_in_maintenance' => $task->runsDuringMaintenance(),
        ], $this->tasks);
    }

    /** @return list<array<string,mixed>> */
    public function history(int $limit = 20): array
    {
        return $this->history->recent($limit);
    }

    /** @return list<array{task:string,error:string}> */
    public function failures(): array
    {
        return $this->failures;
    }

    private function runTask(ScheduledTask $task, int $scheduledAt): bool
    {
        $id = $task->id();
        $slot = intdiv($scheduledAt, $task->interval());
        $owner = bin2hex(random_bytes(16));
        $slotKey = 'schedule:slot:' . hash('sha256', $id . ':' . $slot);
        $mutexKey = 'schedule:mutex:' . hash('sha256', $id);
        $slotTtl = max(1, $task->interval() - ($scheduledAt % $task->interval()) + 1);
        $slotAcquired = false;
        $mutexAcquired = false;
        $slotConsumed = false;

        try {
            if ($task->runsOnOneServer()) {
                $slotAcquired = $this->locks->acquire($slotKey, $owner, $slotTtl);
                if (!$slotAcquired) {
                    return false;
                }
            }
            if ($task->protectsOverlap()) {
                $mutexAcquired = $this->locks->acquire($mutexKey, $owner, max($task->lockTtlSeconds(), $task->timeoutSeconds()));
                if (!$mutexAcquired) {
                    if ($slotAcquired) {
                        $this->locks->release($slotKey, $owner);
                    }
                    return false;
                }
            }

            $slotConsumed = $slotAcquired;
            $last = null;
            $attempt = 0;
            do {
                $attempt++;
                $startedAt = microtime(true);
                $status = 'success';
                $error = null;
                try {
                    $task->execute($task->timeoutSeconds());
                    $task->markAttempted($scheduledAt);
                    $elapsed = microtime(true) - $startedAt;
                    if ($task->timeoutSeconds() > 0 && $elapsed > $task->timeoutSeconds()) {
                        throw new \RuntimeException(sprintf('Scheduled task exceeded timeout of %d second(s).', $task->timeoutSeconds()));
                    }
                    $last = null;
                } catch (\Throwable $exception) {
                    $last = $exception;
                    $status = 'failed';
                    $error = $exception->getMessage();
                }
                $finishedAt = microtime(true);
                $this->history->record([
                    'task_id' => $id,
                    'description' => $task->description(),
                    'scheduled_at' => $scheduledAt,
                    'attempt' => $attempt,
                    'status' => $status,
                    'started_at' => (int) $startedAt,
                    'finished_at' => (int) $finishedAt,
                    'duration_ms' => (int) round(($finishedAt - $startedAt) * 1000),
                    'error' => $error,
                ]);
                if ($last === null) {
                    return true;
                }
                if ($attempt <= $task->retriesCount() && $task->retryDelaySeconds() > 0) {
                    sleep($task->retryDelaySeconds());
                }
            } while ($attempt <= $task->retriesCount());

            throw $last ?? new \RuntimeException('Scheduled task failed without an exception.');
        } catch (\Throwable $exception) {
            if ($slotAcquired && !$slotConsumed) {
                $this->locks->release($slotKey, $owner);
            }
            throw $exception;
        } finally {
            if ($mutexAcquired) {
                $this->locks->release($mutexKey, $owner);
            }
        }
    }

    /** @return list<ScheduledTask> */
    public function tasks(): array { return $this->tasks; }
}
