<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

final class QueueFailedCommand extends QueueCommand
{
    public function name(): string { return 'queue:failed'; }
    public function description(): string { return 'List failed queue jobs'; }
    public function handle(): int
    {
        $failed = $this->queue()->failed();
        if ($failed === []) { $this->info('No failed queue jobs.'); return 0; }
        foreach ($failed as $job) $this->line(sprintf('#%s attempts=%s %s', $job['id'], $job['attempts'] ?? '?', $job['last_error'] ?? ''));
        return 0;
    }
}
