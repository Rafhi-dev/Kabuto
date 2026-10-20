<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

final class DbPushCommand extends DatabaseCommand
{
    public function name(): string
    {
        return 'db:push';
    }

    public function description(): string
    {
        return 'Sync database tables from database/schema.php (create + add columns)';
    }

    public function handle(): int
    {
        $dryRun = $this->flag('dry-run');

        $actions = $this->migrator->push($this->datasource, $dryRun);

        if ($actions === []) {
            $this->info(sprintf('Datasource [%s] is already in sync.', $this->datasource));
            return 0;
        }

        foreach ($actions as $sql) {
            $this->line(($dryRun ? '[dry-run] ' : '') . $sql);
        }

        $this->success(sprintf(
            '%s%d statement(s) on datasource [%s].',
            $dryRun ? 'Would execute ' : 'Executed ',
            count($actions),
            $this->datasource,
        ));

        return 0;
    }
}
