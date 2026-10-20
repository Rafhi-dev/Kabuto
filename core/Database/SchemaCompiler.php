<?php

declare(strict_types=1);

namespace Kabuto\Database;

/**
 * Compiles schema definitions into driver-correct DDL for SQLite, MySQL,
 * and PostgreSQL from the shared capability map.
 */
final class SchemaCompiler
{
    private readonly DriverCapabilities $caps;

    public function __construct(private readonly DatasourceDefinition $ds)
    {
        $this->caps = DriverCapabilities::for($ds->driver);
    }

    public function capabilities(): DriverCapabilities
    {
        return $this->caps;
    }

    public function quote(string $identifier): string
    {
        return $this->caps->driver === 'mysql'
            ? '`' . str_replace('`', '``', $identifier) . '`'
            : '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * Physical table reference: `db`.`table` / "schema"."table" on qualified
     * drivers, "schema_table" on SQLite.
     */
    public function tableRef(string $schema, string $table): string
    {
        if ($schema === '' || $this->caps->schemaTablePrefix) {
            return $this->quote($schema === '' ? $table : $schema . '_' . $table);
        }

        return $this->quote($schema) . '.' . $this->quote($table);
    }

    /**
     * Physical table name as stored by the engine (SQLite stores the prefix
     * inside the name; MySQL/PostgreSQL store it as a separate namespace).
     */
    public function physicalTable(string $schema, string $table): string
    {
        if ($schema === '') {
            return $table;
        }

        return $this->caps->schemaTablePrefix ? $schema . '_' . $table : $table;
    }

    public function schemaOf(string $schema): string
    {
        return $this->caps->schemaTablePrefix ? '' : $schema;
    }

    public function createTable(ModelDefinition $model): string
    {
        $columns = [];
        foreach ($model->fields as $field) {
            $columns[] = $this->column($field);
        }

        $pk = $model->primaryKey();
        $field = $model->field($pk);
        if ($field !== null && strtolower($field->type) !== 'increments') {
            $columns[] = sprintf('PRIMARY KEY (%s)', $this->quote($pk));
        }

        $uniques = [];
        foreach ($model->fields as $field) {
            if ($field->has('unique') && strtolower($field->type) !== 'increments') {
                $uniques[] = sprintf('UNIQUE (%s)', $this->quote($field->name));
            }
        }

        $constraints = [];
        foreach ($model->relations as $relation) {
            if ($relation->isBelongsTo()) {
                $constraints[] = $this->foreignKey($relation, $model);
            }
        }

        return sprintf(
            "CREATE TABLE %s (\n  %s%s%s\n)",
            $this->tableRef($model->schema, $model->table),
            implode(",\n  ", $columns),
            $uniques !== [] ? ",\n  " . implode(",\n  ", $uniques) : '',
            $constraints !== [] ? ",\n  " . implode(",\n  ", $constraints) : '',
        );
    }

    public function column(FieldDefinition $field): string
    {
        if (strtolower($field->type) === 'increments') {
            return $this->autoIncrementPk($field->name);
        }

        $sql = $this->quote($field->name) . ' ' . $this->type($field);

        if (!$field->nullable()) {
            $sql .= ' NOT NULL';
        }

        if ($field->hasDefault) {
            $sql .= ' DEFAULT ' . $this->defaultValue($field);
        } elseif ($field->has('useCurrent')) {
            $sql .= ' DEFAULT CURRENT_TIMESTAMP';
        }

        if ($field->has('unique')) {
            $sql .= ' UNIQUE';
        }

        return $sql;
    }

    public function type(FieldDefinition $field): string
    {
        $length = $field->length;
        $driver = $this->caps->driver;

        return match (strtolower($field->type)) {
            'string' => sprintf('VARCHAR(%d)', $length ?? 255),
            'text' => 'TEXT',
            'integer', 'int' => $driver === 'mysql' ? 'INT' : 'INTEGER',
            'biginteger' => 'BIGINT',
            'float' => match ($driver) {
                'mysql' => 'FLOAT',
                'pgsql' => 'DOUBLE PRECISION',
                default => 'REAL',
            },
            'decimal' => 'DECIMAL(10,2)',
            'boolean', 'bool' => $this->caps->booleanType,
            'date' => 'DATE',
            'datetime' => match ($driver) {
                'mysql' => 'DATETIME',
                'pgsql' => 'TIMESTAMP',
                default => 'TEXT',
            },
            'timestamp' => match ($driver) {
                'mysql' => 'TIMESTAMP',
                'pgsql' => 'TIMESTAMP',
                default => 'TEXT',
            },
            'time' => 'TIME',
            'json' => $this->caps->jsonType,
            'enum' => $driver === 'mysql' && $field->params !== []
                ? sprintf('ENUM(%s)', implode(',', array_map(static fn (string $v): string => "'" . addslashes($v) . "'", $field->params)))
                : sprintf('VARCHAR(%d)', $length ?? 50),
            'uuid' => sprintf('VARCHAR(%d)', $length ?? 36),
            default => 'TEXT',
        };
    }

    public function defaultValue(FieldDefinition $field): string
    {
        $default = $field->default;

        if ($default === null) {
            return 'NULL';
        }
        if (is_bool($default)) {
            if ($this->caps->driver === 'pgsql') {
                return $default ? 'TRUE' : 'FALSE';
            }
            return $default ? '1' : '0';
        }
        if (is_numeric($default) && !in_array(strtolower($field->type), ['string', 'text', 'enum', 'uuid'], true)) {
            return (string) $default;
        }

        return $this->caps->driver === 'mysql'
            ? "'" . addslashes((string) $default) . "'"
            : "'" . str_replace("'", "''", (string) $default) . "'";
    }

    public function autoIncrementPk(string $name = 'id', bool $big = false): string
    {
        return self::autoIncrementPkFor($this->caps->driver, $name, $big);
    }

    /**
     * Auto-incrementing primary key column for any supported driver, used by
     * framework-managed tables (migrations, queue) and model `increments` fields.
     */
    public static function autoIncrementPkFor(string $driver, string $name = 'id', bool $big = false): string
    {
        $driver = DriverCapabilities::normalize($driver);

        return match ($driver) {
            'mysql' => $big
                ? sprintf('%s BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY', '`' . str_replace('`', '``', $name) . '`')
                : sprintf('%s INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', '`' . str_replace('`', '``', $name) . '`'),
            'pgsql' => sprintf(
                '%s %s GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY',
                '"' . str_replace('"', '""', $name) . '"',
                $big ? 'BIGINT' : 'INTEGER',
            ),
            default => sprintf('%s INTEGER PRIMARY KEY AUTOINCREMENT', '"' . str_replace('"', '""', $name) . '"'),
        };
    }

    public function createIndex(string $name, string $schema, string $table, string $column, bool $ifNotExists = false): string
    {
        $guard = ($ifNotExists && $this->caps->ifNotExistsIndex) ? 'IF NOT EXISTS ' : '';

        return sprintf(
            'CREATE INDEX %s ON %s (%s)',
            $guard . $this->quote($name),
            $this->tableRef($schema, $table),
            $this->quote($column),
        );
    }

    /**
     * Foreign key constraint for belongsTo relations.
     */
    public function foreignKey(RelationDefinition $relation, ModelDefinition $localModel): string
    {
        $targetModel = $this->ds->resolveModel($relation->model);
        $targetRef = $this->tableRef($targetModel->schema, $targetModel->table);

        return sprintf(
            'FOREIGN KEY (%s) REFERENCES %s(%s)',
            $this->quote($relation->foreignKey),
            $targetRef,
            $this->quote($relation->references),
        );
    }
}
