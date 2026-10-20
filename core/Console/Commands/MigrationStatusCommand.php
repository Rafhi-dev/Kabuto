<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

final class MigrationStatusCommand extends MigrationCommand
{
    public function name(): string { return 'migrate:status'; }
    public function description(): string { return 'Show database migration status'; }
    public function handle(): int
    {
        foreach ($this->runner->status($this->datasource) as $item) {
            $this->line(sprintf('%-10s %s', $item['status'], $item['name']));
        }
        return 0;
    }
}
