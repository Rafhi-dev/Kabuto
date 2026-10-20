<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

final class MigrateCommand extends MigrationCommand
{
    public function name(): string { return 'migrate'; }
    public function description(): string { return 'Run pending database migrations'; }
    public function handle(): int
    {
        $items = $this->runner->migrate($this->datasource, $this->flag('dry-run'));
        $this->success(($items === [] ? 'No pending migrations.' : implode(PHP_EOL, $items)));
        return 0;
    }
}
