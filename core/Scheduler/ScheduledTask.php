<?php

declare(strict_types=1);

namespace Kabuto\Scheduler;

final class ScheduledTask
{
    private ?int $lastRun = null;
    private int $retries = 0;
    private int $retryDelaySeconds = 0;
    private int $timeoutSeconds = 0;
    private int $lockTtlSeconds = 3600;
    private bool $overlapProtection = true;
    private bool $singleServer = true;
    private bool $runsDuringMaintenance = false;

    public function __construct(
        private readonly \Closure $callback,
        private readonly int $intervalSeconds,
        private readonly string $description = '',
        private readonly ?string $taskId = null,
    )
    {
        if ($intervalSeconds < 1) {
            throw new \InvalidArgumentException('Schedule interval must be at least one second.');
        }
    }

    public function due(?int $now = null): bool
    {
        $now ??= time();
        return $this->lastRun === null || $now - $this->lastRun >= $this->intervalSeconds;
    }

    public function run(?int $now = null): void
    {
        $now ??= time();
        ($this->callback)();
        $this->lastRun = $now;
    }

    public function markAttempted(?int $now = null): void
    {
        $this->lastRun = $now ?? time();
    }

    public function retries(int $retries, int $delaySeconds = 0): self
    {
        if ($retries < 0 || $delaySeconds < 0) {
            throw new \InvalidArgumentException('Schedule retries and retry delay cannot be negative.');
        }
        $this->retries = $retries;
        $this->retryDelaySeconds = $delaySeconds;
        return $this;
    }

    public function timeout(int $seconds): self
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('Schedule timeout cannot be negative.');
        }
        $this->timeoutSeconds = $seconds;
        return $this;
    }

    public function withoutOverlapping(int $lockTtlSeconds = 3600): self
    {
        if ($lockTtlSeconds < 1) {
            throw new \InvalidArgumentException('Schedule lock TTL must be at least one second.');
        }
        $this->overlapProtection = true;
        $this->lockTtlSeconds = $lockTtlSeconds;
        return $this;
    }

    public function allowOverlapping(): self
    {
        $this->overlapProtection = false;
        return $this;
    }

    public function onOneServer(bool $enabled = true): self
    {
        $this->singleServer = $enabled;
        return $this;
    }

    public function evenInMaintenance(bool $enabled = true): self
    {
        $this->runsDuringMaintenance = $enabled;
        return $this;
    }

    public function id(): string
    {
        if ($this->taskId !== null && trim($this->taskId) !== '') {
            return $this->taskId;
        }
        if ($this->description !== '') {
            return $this->description;
        }
        $reflection = new \ReflectionFunction($this->callback);
        return ($reflection->getFileName() ?: 'closure') . ':' . $reflection->getStartLine();
    }

    public function execute(int $timeoutSeconds): void
    {
        if ($timeoutSeconds < 1 || !function_exists('pcntl_alarm') || !function_exists('pcntl_signal')) {
            ($this->callback)();
            return;
        }

        $previousAsync = pcntl_async_signals();
        $previousHandler = pcntl_signal_get_handler(SIGALRM);
        try {
            pcntl_async_signals(true);
            pcntl_signal(SIGALRM, static function (): never {
                throw new \RuntimeException('Scheduled task timed out.');
            });
            pcntl_alarm($timeoutSeconds);
            ($this->callback)();
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previousHandler);
            pcntl_async_signals($previousAsync);
        }
    }

    public function retriesCount(): int { return $this->retries; }
    public function retryDelaySeconds(): int { return $this->retryDelaySeconds; }
    public function timeoutSeconds(): int { return $this->timeoutSeconds; }
    public function lockTtlSeconds(): int { return $this->lockTtlSeconds; }
    public function protectsOverlap(): bool { return $this->overlapProtection; }
    public function runsOnOneServer(): bool { return $this->singleServer; }
    public function runsDuringMaintenance(): bool { return $this->runsDuringMaintenance; }
    public function description(): string { return $this->description; }
    public function interval(): int { return $this->intervalSeconds; }
}
