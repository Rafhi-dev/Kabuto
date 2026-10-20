<?php

declare(strict_types=1);

namespace Kabuto\Database;

/**
 * Loads the schema definition for CLI commands and providers:
 * prefers `database/schema.php`, otherwise builds an empty-models schema
 * from `config/database.php` connections (enough for db:tables / db:pull).
 */
final class SchemaLoader
{
    /**
     * @param array<string,mixed> $dbConfig  config('database')
     */
    public static function load(string $basePath, array $dbConfig = []): SchemaDefinition
    {
        $file = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema.php';

        if (is_file($file)) {
            $definition = require $file;

            if (!$definition instanceof SchemaDefinition) {
                throw new DatabaseException(sprintf(
                    'database/schema.php must return a SchemaDefinition (use Schema::define()). Got [%s].',
                    get_debug_type($definition),
                ));
            }

            return $definition;
        }

        return self::fromConnections($dbConfig);
    }

    /**
     * @param array<string,mixed> $dbConfig
     */
    public static function fromConnections(array $dbConfig): SchemaDefinition
    {
        $connections = $dbConfig['connections'] ?? [];
        $default = (string) ($dbConfig['default'] ?? array_key_first($connections) ?? 'db');

        if ($connections === []) {
            throw new DatabaseException('No database connections configured. Add config/database.php or database/schema.php.');
        }

        $datasources = [];
        foreach ($connections as $name => $conn) {
            $driver = DriverCapabilities::normalize((string) ($conn['driver'] ?? 'sqlite'));
            $options = is_array($conn) ? $conn : [];
            unset($options['driver'], $options['schemas']);

            $datasources[$name] = new DatasourceDefinition(
                (string) $name,
                $driver,
                array_values((array) ($conn['schemas'] ?? [])),
                $options,
            );
        }

        if (!isset($datasources[$default])) {
            $default = (string) array_key_first($datasources);
        }

        return new SchemaDefinition($datasources, [], $default);
    }
}
