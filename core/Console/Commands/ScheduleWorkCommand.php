<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\Command;
use Kabuto\Scheduler\Scheduler;

final class ScheduleWorkCommand extends Command
{
    public function name(): string { return 'schedule:work'; }
    public function description(): string { return 'Run the scheduler worker until stopped'; }

    public function handle(): int
    {
        $scheduler = $this->console->app()->container()->get(Scheduler::class);
        $once = $this->flag('once');
        $poll = max(1, (int) ($this->option('sleep', '1') ?? 1));
        $maxRuntime = $this->option('max-runtime');
        $runtime = $maxRuntime === null ? null : max(1, (int) $maxRuntime);
        $stop = false;

        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function (int $signal) use (&$stop): void { $stop = true; });
            pcntl_signal(SIGINT, static function (int $signal) use (&$stop): void { $stop = true; });
        }

        $processed = $once ? $scheduler->runDue() : $scheduler->runWorker($poll, $runtime, static function () use (&$stop): bool { return $stop; });
        $this->success(sprintf('Scheduler worker stopped; %d task execution(s) completed.', $processed));
        return $scheduler->failures() === [] ? 0 : 1;
    }
}
