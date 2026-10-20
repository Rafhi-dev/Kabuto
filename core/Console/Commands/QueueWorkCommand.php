<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

final class QueueWorkCommand extends QueueCommand
{
    public function name(): string { return 'queue:work'; }
    public function description(): string { return 'Process queue jobs'; }

    public function handle(): int
    {
        $once = $this->flag('once');
        $max = max(1, (int) ($this->option('tries', '3') ?? 3));
        $delay = max(0, (int) ($this->option('delay', '5') ?? 5));
        $processed = 0;
        do {
            if ($this->queue()->workOnce($max, $delay)) { $processed++; continue; }
            if ($once) break;
            sleep(max(1, (int) ($this->option('sleep', '1') ?? 1)));
        } while (!$this->flag('stop-when-empty'));
        $this->success(sprintf('Processed %d queue job(s).', $processed));
        return 0;
    }
}
