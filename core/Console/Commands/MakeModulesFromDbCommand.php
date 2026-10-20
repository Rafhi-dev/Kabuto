<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\Command;
use RuntimeException;

/**
 * Scaffolds ORM-backed modules for every model in the schema
 * (or every model of one datasource via --datasource).
 *
 *   php bin/console make:modules-from-db
 *   php bin/console make:modules-from-db --datasource=mysql --force
 */
final class MakeModulesFromDbCommand extends Command
{
    public function name(): string
    {
        return 'make:modules-from-db';
    }

    public function description(): string
    {
        return 'Scaffold ORM-backed modules for all schema models';
    }

    public function handle(): int
    {
        $only = $this->argument(0);
        $datasource = $this->datasourceOption();

        $schema = DatabaseCommand::loadSchema($this->console);

        $models = array_keys($schema->models);
        if ($datasource !== null) {
            $models = array_map(
                static fn ($m) => $m->name,
                $schema->modelsForDatasource($datasource),
            );
        } elseif ($only !== null && !$this->isFlag($only)) {
            // make:modules-from-db User  -> single model shortcut
            $models = [$only];
        }

        if ($models === []) {
            throw new RuntimeException('No models found in database/schema.php.');
        }

        $status = 0;
        foreach ($models as $model) {
            $args = array_merge([$model], $this->flag('force') ? ['--force'] : []);
            $command = new MakeModuleFromDbCommand($this->console, $args);
            $status = max($status, $command->handle());
        }

        $this->success(sprintf('Generated %d module(s).', count($models)));

        return $status;
    }

    private function datasourceOption(): ?string
    {
        foreach ($this->arguments as $argument) {
            if (str_starts_with($argument, '--datasource=')) {
                return substr($argument, strlen('--datasource='));
            }
        }

        return null;
    }

    private function isFlag(string $value): bool
    {
        return str_starts_with($value, '--');
    }
}
