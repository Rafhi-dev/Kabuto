<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

final class MigrationRollbackCommand extends MigrationCommand
{
    public function name(): string { return 'migrate:rollback'; }
    public function description(): string { return 'Rollback the latest database migration'; }
    public function handle(): int
    {
        $items = $this->runner->rollback($this->datasource);
        $this->success($items === [] ? 'No migrations to rollback.' : implode(PHP_EOL, $items));
        return 0;
    }
}
