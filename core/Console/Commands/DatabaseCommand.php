<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\Command;
use Kabuto\Console\Console;
use Kabuto\Database\ConnectionManager;
use Kabuto\Database\Migrator;
use Kabuto\Database\SchemaDefinition;
use Kabuto\Database\SchemaLoader;

/**
 * Base class for db:* commands. Resolves the schema definition and the
 * target datasource (via --datasource, defaulting to the schema default).
 */
abstract class DatabaseCommand extends Command
{
    protected SchemaDefinition $schema;

    protected ConnectionManager $connections;

    protected Migrator $migrator;

    protected string $datasource;

    public function __construct(Console $console, array $arguments = [])
    {
        parent::__construct($console, $arguments);

        $this->schema = SchemaLoader::load($console->basePath(), (array) config('database', []));
        $this->connections = new ConnectionManager($this->schema);
        $this->migrator = new Migrator($this->schema, $this->connections);
        $this->datasource = $this->option('datasource') ?? $this->schema->defaultDatasource ?? 'db';
    }


    public static function loadSchema(Console $console): SchemaDefinition
    {
        return SchemaLoader::load($console->basePath(), (array) config('database', []));
    }
}
