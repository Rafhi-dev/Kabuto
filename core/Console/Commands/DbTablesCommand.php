<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

final class DbTablesCommand extends DatabaseCommand
{
    public function name(): string
    {
        return 'db:tables';
    }

    public function description(): string
    {
        return 'List tables and columns of a datasource';
    }

    public function handle(): int
    {
        $ds = $this->schema->datasource($this->datasource);
        $pdo = $this->connections->connection($this->datasource);
        $tables = $this->migrator->listTables($pdo, $ds);

        if ($tables === []) {
            $this->info(sprintf('Datasource [%s] has no tables.', $this->datasource));
            return 0;
        }

        foreach ($tables as $table) {
            $this->line($table);
            foreach ($this->migrator->describeTable($pdo, $ds, $table) as $column => $type) {
                $this->line(sprintf('  %-24s %s', $column, $type));
            }
        }

        return 0;
    }
}
