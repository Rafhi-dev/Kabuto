<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\Command;
use Kabuto\Scheduler\Scheduler;

final class ScheduleListCommand extends Command
{
    public function name(): string { return 'schedule:list'; }
    public function description(): string { return 'List configured scheduled tasks and their policies'; }

    public function handle(): int
    {
        $tasks = $this->console->app()->container()->get(Scheduler::class)->describe();
        if ($tasks === []) {
            $this->line('No scheduled tasks are configured.');
            return 0;
        }

        foreach ($tasks as $task) {
            $this->line(sprintf('%s — %s (every %ds; retries=%d, timeout=%ds, overlap=%s, one-server=%s)',
                $task['id'], $task['description'] !== '' ? $task['description'] : '(no description)',
                $task['interval'], $task['retries'], $task['timeout'],
                $task['without_overlapping'] ? 'locked' : 'allowed', $task['on_one_server'] ? 'yes' : 'no'));
        }
        if ($this->flag('history')) {
            foreach ($this->console->app()->container()->get(Scheduler::class)->history(max(1, (int) ($this->option('limit', '20') ?? 20))) as $entry) {
                $this->line(sprintf('  [%s] %s attempt %d — %s%s', $entry['status'], $entry['task_id'], $entry['attempt'], $entry['description'], $entry['error'] ? ': ' . $entry['error'] : ''));
            }
        }
        return 0;
    }
}
