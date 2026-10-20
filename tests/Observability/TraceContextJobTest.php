<?php

declare(strict_types=1);

namespace Kabuto\Tests\Observability;

use Kabuto\Observability\TraceContext;
use Kabuto\Observability\TraceContextJob;
use Kabuto\Queue\Job;
use Kabuto\Tests\TestCase;

final class TraceContextJobTest extends TestCase
{
    public function testRestoresContextWhileJobRuns(): void
    {
        $seen = null;
        $job = new class ($seen) implements Job {
            public function __construct(private mixed &$seen) {}
            public function handle(): void { $this->seen = TraceContext::current(); }
        };
        $context = ['request_id' => 'req-1', 'correlation_id' => 'req-1', 'trace_id' => str_repeat('a', 32), 'span_id' => str_repeat('b', 16), 'parent_span_id' => null];
        (new TraceContextJob($job, $context))->handle();

        self::assertSame($context, $seen);
        self::assertNull(TraceContext::current());
    }
}
