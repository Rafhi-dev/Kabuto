<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\Command;
use Kabuto\Scheduler\Scheduler;

final class ScheduleRunCommand extends Command
{
    public function name(): string { return 'schedule:run'; }
    public function description(): string { return 'Run scheduled tasks that are due'; }
    public function handle(): int
    {
        $scheduler = $this->console->app()->container()->get(Scheduler::class);
        $count = $scheduler->runDue();
        $this->success("{$count} scheduled task(s) executed.");
        foreach ($scheduler->failures() as $failure) {
            $this->error(sprintf('Task [%s] failed: %s', $failure['task'], $failure['error']));
        }
        if ($this->flag('history')) {
            $limit = max(1, (int) ($this->option('limit', '20') ?? 20));
            foreach ($scheduler->history($limit) as $entry) {
                $this->line(sprintf('[%s] %s attempt %d (%dms)%s', $entry['status'], $entry['task_id'], $entry['attempt'], $entry['duration_ms'], $entry['error'] ? ': ' . $entry['error'] : ''));
            }
        }
        return $scheduler->failures() === [] ? 0 : 1;
    }
}
