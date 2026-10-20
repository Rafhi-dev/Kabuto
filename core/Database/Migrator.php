<?php

declare(strict_types=1);

namespace Kabuto\Database;

use PDO;

/**
 * Declarative, idempotent schema synchronization (Prisma `db push` style):
 * creates missing tables and adds missing columns. Never drops or renames
 * unless explicitly forced.
 *
 * DDL compilation lives in SchemaCompiler and introspection in
 * SchemaIntrospector so SQLite, MySQL, and PostgreSQL share one code path.
 */
final class Migrator
{
    public function __construct(
        private readonly SchemaDefinition $schema,
        private readonly ConnectionManager $connections,
    ) {
    }

    /**
     * @return list<string> executed statements (for reporting / dry-run)
     */
    public function push(?string $datasource = null, bool $dryRun = false): array
    {
        $executed = [];
        $targets = $datasource !== null
            ? [$this->schema->datasource($datasource)]
            : array_values($this->schema->datasources);

        foreach ($targets as $ds) {
            foreach ($this->schema->modelsForDatasource($ds->name) as $model) {
                foreach ($this->pushModel($ds, $model, $dryRun) as $action) {
                    $executed[] = $ds->name . ': ' . $model->name . ' — ' . $action;
                }
            }
        }

        return $executed;
    }

    /**
     * @return list<string> human-readable actions taken
     */
    private function pushModel(DatasourceDefinition $ds, ModelDefinition $model, bool $dryRun): array
    {
        $pdo = $this->connections->connection($ds->name);
        $compiler = new SchemaCompiler($ds);
        $introspector = new SchemaIntrospector($pdo, $ds);
        $physical = $compiler->physicalTable($model->schema, $model->table);
        $namespace = $compiler->schemaOf($model->schema);
        $ref = $compiler->tableRef($model->schema, $model->table);
        $actions = [];

        if (!$introspector->tableExists($physical, $namespace !== '' ? $namespace : null)) {
            $sql = $compiler->createTable($model);
            if (!$dryRun) {
                $this->exec($pdo, $sql, $ds);
            }
            $actions[] = $sql;
        } else {
            $existing = $introspector->columns($physical, $namespace !== '' ? $namespace : null);
            foreach ($model->fields as $field) {
                if (isset($existing[strtolower($field->name)])) {
                    continue;
                }
                if (strtolower($field->type) === 'increments') {
                    throw new DatabaseException(sprintf(
                        'Cannot add auto-increment primary key column [%s] to existing table [%s]: no driver supports ALTER TABLE ADD COLUMN for identity/auto-increment keys. Recreate the table, or add the column as a plain integer and backfill it.',
                        $field->name,
                        $physical,
                    ));
                }
                $sql = sprintf('ALTER TABLE %s ADD COLUMN %s', $ref, $compiler->column($field));
                if (!$dryRun) {
                    $this->exec($pdo, $sql, $ds);
                }
                $actions[] = $sql;
            }
        }

        foreach ($this->missingIndexes($compiler, $introspector, $ds, $model, $physical, $namespace, $dryRun, $pdo) as $sql) {
            $actions[] = $sql;
        }

        return $actions;
    }

    /**
     * @return list<string> CREATE INDEX statements for `index`-flagged columns
     */
    private function missingIndexes(
        SchemaCompiler $compiler,
        SchemaIntrospector $introspector,
        DatasourceDefinition $ds,
        ModelDefinition $model,
        string $physical,
        string $namespace,
        bool $dryRun,
        PDO $pdo,
    ): array {
        $indexed = $introspector->indexes($physical, $namespace !== '' ? $namespace : null);
        $missing = [];

        foreach ($model->fields as $field) {
            if (!$field->has('index') || $field->has('unique')) {
                continue;
            }
            if (isset($indexed[strtolower($field->name)])) {
                continue;
            }
            $name = sprintf('%s_%s_idx', $physical, $field->name);
            $sql = $compiler->createIndex($name, $model->schema, $model->table, $field->name);
            if (!$dryRun) {
                $this->exec($pdo, $sql, $ds);
            }
            $missing[] = $sql;
        }

        return $missing;
    }

    private function exec(PDO $pdo, string $sql, DatasourceDefinition $ds): void
    {
        try {
            $pdo->exec($sql);
        } catch (\Throwable $e) {
            throw DatabaseException::wrap($e, $ds->driver, 'migrator push');
        }
    }

    /**
     * Introspect a datasource and rebuild model definitions from live tables.
     *
     * On SQLite the schema prefix is stripped back off (`base_users` in
     * schema `base` becomes table `users`), mirroring how push qualifies it.
     * MySQL and PostgreSQL return the real schema/database per table.
     * Foreign keys are inferred into belongsTo relations for all drivers.
     *
     * @return array<string,array<string,mixed>> models keyed by StudlyName
     */
    public function pull(string $datasource): array
    {
        $ds = $this->schema->datasource($datasource);
        $pdo = $this->connections->connection($datasource);
        $introspector = new SchemaIntrospector($pdo, $ds);

        $models = [];
        foreach ($introspector->tables() as $table) {
            $name = $table['name'];
            $schema = '';
            $bare = $name;

            if ($ds->driver === 'sqlite') {
                foreach ($ds->schemas as $candidate) {
                    if (str_starts_with($name, $candidate . '_')) {
                        $schema = $candidate;
                        $bare = substr($name, strlen($candidate) + 1);
                        break;
                    }
                }
            } else {
                $schema = $table['schema'];
                if ($schema === $introspector->defaultNamespace()) {
                    // Redundant qualification (the connection's own database /
                    // schema): leave the schema attribute empty so the
                    // regenerated schema.php passes Schema::define validation.
                    $schema = '';
                } elseif ($ds->schemas !== [] && !$ds->serves($schema)) {
                    // Namespace outside the schemas this datasource serves.
                    continue;
                }
            }

            $namespace = $table['schema'] !== '' ? $table['schema'] : null;
            $key = $this->classify($bare);
            $models[$key] = [
                'table' => $bare,
                'schema' => $schema,
                'fields' => $introspector->fields($name, $namespace),
                'relations' => $this->inferredRelations($this->stripFkPrefixes($introspector->foreignKeys($name, $namespace), $ds)),
            ];
        }

        return $models;
    }

    /**
     * SQLite stores the schema as a table-name prefix, so FK targets come back
     * as `base_authors` — strip known prefixes to recover the bare table name.
     *
     * @param array<string,array{table:string,column:string}> $foreignKeys
     * @return array<string,array{table:string,column:string}>
     */
    private function stripFkPrefixes(array $foreignKeys, DatasourceDefinition $ds): array
    {
        if ($ds->driver !== 'sqlite') {
            return $foreignKeys;
        }

        foreach ($foreignKeys as $column => $ref) {
            foreach ($ds->schemas as $candidate) {
                if (str_starts_with($ref['table'], $candidate . '_')) {
                    $foreignKeys[$column]['table'] = substr($ref['table'], strlen($candidate) + 1);
                    break;
                }
            }
        }

        return $foreignKeys;
    }

    /**
     * Map foreign keys to belongsTo relation specs: column `author_id`
     * referencing `authors.id` becomes relation `author` => model `Author`.
     *
     * @param array<string,array{table:string,column:string}> $foreignKeys
     * @return array<string,array{model:string,foreignKey:string,references:string}>
     */
    private function inferredRelations(array $foreignKeys): array
    {
        $relations = [];
        foreach ($foreignKeys as $column => $ref) {
            $name = str_ends_with($column, '_id') ? substr($column, 0, -3) : $column;
            if ($name === '' || isset($relations[$name])) {
                continue;
            }
            $relations[$name] = [
                'model' => $this->classify($ref['table']),
                'foreignKey' => $column,
                'references' => $ref['column'],
            ];
        }

        return $relations;
    }

    /**
     * Physical table names as stored by the engine (unqualified).
     *
     * @return list<string>
     */
    public function listTables(PDO $pdo, DatasourceDefinition $ds): array
    {
        $introspector = new SchemaIntrospector($pdo, $ds);

        return array_map(
            static fn (array $table): string => $table['name'],
            $introspector->tables(),
        );
    }

    /**
     * @return array<string,array<string,mixed>|string> column => info
     */
    public function describeTable(PDO $pdo, DatasourceDefinition $ds, string $table): array
    {
        [$schema, $name] = SchemaIntrospector::splitRef($table);
        $introspector = new SchemaIntrospector($pdo, $ds);

        $info = [];
        foreach ($introspector->fields($name, $schema) as $column => $spec) {
            $info[$column] = is_array($spec) ? ($spec['type'] ?? 'text') : $spec;
        }
        return $info;
    }

    private function classify(string $table): string
    {
        $singular = preg_match('/ies$/i', $table)
            ? substr($table, 0, -3) . 'y'
            : (string) preg_replace('/s$/i', '', $table);

        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $singular)));
    }
}
