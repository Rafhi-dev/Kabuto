<?php

declare(strict_types=1);

namespace Kabuto\Queue;

interface QueueableJob extends Job
{
    public function maxAttempts(): int;

    /** Retry delay in seconds. */
    public function retryDelay(): int;

    public function failed(\Throwable $failure): void;
}
