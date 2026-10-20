<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\Command;
use Kabuto\Console\Console;
use Kabuto\Database\ConnectionManager;
use Kabuto\Database\MigrationRunner;
use Kabuto\Database\SchemaLoader;

abstract class MigrationCommand extends Command
{
    protected MigrationRunner $runner;
    protected string $datasource;

    public function __construct(Console $console, array $arguments = [])
    {
        parent::__construct($console, $arguments);
        $schema = SchemaLoader::load($console->basePath(), (array) config('database', []));
        $this->datasource = $this->option('datasource') ?? $schema->defaultDatasource;
        $this->runner = new MigrationRunner(new ConnectionManager($schema), $console->basePath() . '/database/migrations');
    }
}
