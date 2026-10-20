<?php

declare(strict_types=1);

namespace Kabuto\Observability;

use Kabuto\Queue\Job;

final class TraceContextJob implements Job
{
    public function __construct(private readonly Job $job, private readonly array $context) {}

    public function handle(): void
    {
        $traces = new TraceContext();
        $traces->run($this->context, fn (): mixed => $this->job->handle());
    }

    public function context(): array
    {
        return $this->context;
    }
}
