<?php

declare(strict_types=1);

namespace Kabuto\Database;

/**
 * Driver capability map for SQLite, MySQL, and PostgreSQL.
 *
 * Single source of truth consumed by DDL compilation, schema introspection,
 * exception normalization, and the compatibility matrix shown in docs/tests.
 */
final class DriverCapabilities
{
    public const DRIVERS = ['sqlite', 'mysql', 'pgsql'];

    private function __construct(
        public readonly string $driver,
        public readonly string $introspection,
        public readonly string $autoIncrement,
        public readonly bool $schemaQualification,
        public readonly bool $schemaTablePrefix,
        public readonly bool $transactionalDdl,
        public readonly bool $nativeJson,
        public readonly string $jsonType,
        public readonly string $booleanType,
        public readonly bool $supportsReturning,
        public readonly bool $supportsSavepoints,
        public readonly bool $ifNotExistsIndex,
        public readonly int $maxIdentifierLength,
    ) {
    }

    public static function normalize(string $driver): string
    {
        return match (strtolower(trim($driver))) {
            'postgres', 'postgresql', 'pgsql' => 'pgsql',
            'mariadb', 'mysql' => 'mysql',
            'sqlite' => 'sqlite',
            default => strtolower(trim($driver)),
        };
    }

    public static function for(string $driver): self
    {
        $driver = self::normalize($driver);

        return match ($driver) {
            'mysql' => new self(
                driver: 'mysql',
                introspection: 'mysql_information_schema',
                autoIncrement: 'mysql_auto_increment',
                schemaQualification: true,
                schemaTablePrefix: false,
                transactionalDdl: false,
                nativeJson: true,
                jsonType: 'JSON',
                booleanType: 'TINYINT(1)',
                supportsReturning: false,
                supportsSavepoints: true,
                ifNotExistsIndex: false,
                maxIdentifierLength: 64,
            ),
            'pgsql' => new self(
                driver: 'pgsql',
                introspection: 'pgsql_catalog',
                autoIncrement: 'pgsql_identity',
                schemaQualification: true,
                schemaTablePrefix: false,
                transactionalDdl: true,
                nativeJson: true,
                jsonType: 'JSONB',
                booleanType: 'BOOLEAN',
                supportsReturning: true,
                supportsSavepoints: true,
                ifNotExistsIndex: true,
                maxIdentifierLength: 63,
            ),
            'sqlite' => new self(
                driver: 'sqlite',
                introspection: 'sqlite_pragma',
                autoIncrement: 'sqlite_autoincrement',
                schemaQualification: false,
                schemaTablePrefix: true,
                transactionalDdl: true,
                nativeJson: false,
                jsonType: 'TEXT',
                booleanType: 'BOOLEAN',
                supportsReturning: false,
                supportsSavepoints: true,
                ifNotExistsIndex: true,
                maxIdentifierLength: 128,
            ),
            default => throw new DatabaseException(sprintf(
                'Unsupported driver [%s]. Supported drivers: %s.',
                $driver,
                implode(', ', self::DRIVERS),
            )),
        };
    }

    /**
     * Compatibility matrix keyed by canonical driver name. Used by docs and
     * tests to guarantee the three drivers stay aligned.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function matrix(): array
    {
        $matrix = [];
        foreach (self::DRIVERS as $driver) {
            $caps = self::for($driver);
            $matrix[$driver] = [
                'introspection' => $caps->introspection,
                'auto_increment' => $caps->autoIncrement,
                'schema_qualification' => $caps->schemaQualification ? 'schema.table' : 'schema_table',
                'transactional_ddl' => $caps->transactionalDdl,
                'native_json' => $caps->nativeJson,
                'json_type' => $caps->jsonType,
                'boolean_type' => $caps->booleanType,
                'returning' => $caps->supportsReturning,
                'savepoints' => $caps->supportsSavepoints,
                'if_not_exists_index' => $caps->ifNotExistsIndex,
                'max_identifier_length' => $caps->maxIdentifierLength,
            ];
        }

        return $matrix;
    }
}
