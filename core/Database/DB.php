<?php

declare(strict_types=1);

namespace Kabuto\Database;

use PDO;

/**
 * Prisma-style database client bound to one schema group.
 *
 * Models are accessed as properties (`$db->user`), resolved from the schema
 * definition. Obtain instances via the container or the `db()` helper:
 *
 *   db()->user->findMany(['where' => ['status' => 'active']]);
 *   db('reporting')->event->count();
 *   db()->$transaction([ ... ]);
 *   db()->$queryRaw('SELECT * FROM users');
 */
final class DB
{
    private ?RelationLoader $relations = null;

    /** @var array<string,ModelClient> */
    private array $clients = [];

    /** Shared across DB instances so savepoint names never collide. */
    private static int $savepointSequence = 0;

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly ?string $schema = null,
        private readonly ?string $datasource = null,
    ) {
    }

    /**
     * A client scoped to a schema group or datasource name (shares connections).
     * Passing null (or the same scope) returns this client.
     */
    public function schema(?string $name): self
    {
        if ($name === null || $name === $this->schema || $name === $this->datasource) {
            return $this;
        }

        $definition = $this->definition();

        if (isset($definition->datasources[$name])) {
            return new self($this->connections, null, $name);
        }

        return new self($this->connections, $name, $this->datasource);
    }

    public function definition(): SchemaDefinition
    {
        return $this->connections->schema();
    }

    public function relations(): RelationLoader
    {
        return $this->relations ??= new RelationLoader($this);
    }

    /**
     * Prisma-style model access: `AuditLog` → `$db->auditLog`.
     */
    public function __get(string $name): ModelClient
    {
        if (isset($this->clients[$name])) {
            return $this->clients[$name];
        }

        $model = $this->resolveModel($name);

        return $this->clients[$name] = new ModelClient($this, $model);
    }

    public function __isset(string $name): bool
    {
        try {
            $this->resolveModel($name);
            return true;
        } catch (DatabaseException) {
            return false;
        }
    }

    /**
     * Magic call supporting Prisma-style `$` prefix methods:
     * - `$db->$transaction(...)`
     * - `$db->$queryRaw(...)`
     * - `$db->$executeRaw(...)`
     * - `$db->$connect()`
     * - `$db->$disconnect()`
     *
     * @param list<mixed> $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        $normalized = ltrim($name, '$');

        return match ($normalized) {
            'transaction' => $this->transaction(...$arguments),
            'queryRaw' => $this->queryRaw(...$arguments),
            'executeRaw' => $this->executeRaw(...$arguments),
            'connect' => $this->connect(),
            'disconnect' => $this->disconnect(),
            default => throw new DatabaseException(sprintf('Method [%s] does not exist on DB client.', $name)),
        };
    }

    /**
     * Executes an interactive transaction or a sequential batch of operations.
     *
     * Nested calls use SAVEPOINTs so an inner rollback only reverts the inner
     * work — identical semantics on SQLite, MySQL, and PostgreSQL.
     *
     * @param callable(self):mixed|list<callable(self):mixed> $callback
     */
    public function transaction(callable|array $callback): mixed
    {
        $pdo = $this->connections->connection($this->definition()->defaultDatasource);

        $nested = $pdo->inTransaction();
        $savepoint = null;
        if (!$nested) {
            $pdo->beginTransaction();
        } else {
            // DB::schema() creates sibling instances sharing one PDO connection;
            // a process-wide sequence keeps savepoint names unique across them.
            $savepoint = 'kabuto_sp_' . ++self::$savepointSequence;
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            if (is_array($callback)) {
                $results = [];
                foreach ($callback as $task) {
                    if (is_callable($task)) {
                        $results[] = $task($this);
                    }
                }
                $result = $results;
            } else {
                $result = $callback($this);
            }

            if ($savepoint !== null) {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            } else {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($savepoint !== null) {
                try {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                } catch (\Throwable) {
                    // Savepoint already gone — outer transaction decides.
                }
            } elseif ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Prisma $queryRaw: Execute a raw SQL SELECT query and return rows.
     *
     * @param array<int|string,mixed> $bindings
     * @return list<array<string,mixed>>
     */
    public function queryRaw(string $sql, array $bindings = [], ?string $datasource = null): array
    {
        $pdo = $this->pdo($datasource);
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bindings);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw DatabaseException::wrap($e, $this->driverName($pdo), 'raw query');
        }
    }

    /**
     * Prisma $executeRaw: Execute a raw SQL statement and return affected rows.
     *
     * @param array<int|string,mixed> $bindings
     */
    public function executeRaw(string $sql, array $bindings = [], ?string $datasource = null): int
    {
        $pdo = $this->pdo($datasource);
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bindings);
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            throw DatabaseException::wrap($e, $this->driverName($pdo), 'raw execute');
        }
    }

    /**
     * Prisma $connect: Verifies connection to database, reconnecting if disconnected.
     */
    public function connect(?string $datasource = null): void
    {
        $dsName = $datasource ?? $this->datasource ?? $this->definition()->defaultDatasource;
        if (!$this->connections->isConnected($dsName)) {
            $this->connections->reconnect($dsName);
        } else {
            $this->pdo($datasource);
        }
    }

    /**
     * Prisma $disconnect: Clears open connection pool and closes open connection.
     */
    public function disconnect(): void
    {
        $this->clients = [];
        $this->connections->disconnect($this->datasource ?? $this->definition()->defaultDatasource);
    }

    public function pdo(?string $datasource = null): PDO
    {
        return $this->connections->connection($datasource ?? $this->definition()->defaultDatasource);
    }

    /**
     * Build a query builder for a model, qualifying the table with its
     * schema/database when the driver supports it.
     */
    public function query(ModelDefinition $model): QueryBuilder
    {
        $ds = $this->definition()->datasource($model->datasource);
        $pdo = $this->connections->connection($model->datasource);

        return new QueryBuilder($pdo, $ds->driver, $this->qualify($ds, $model));
    }

    private function resolveModel(string $name): ModelDefinition
    {
        $definition = $this->definition();

        if ($definition->hasModel($name)) {
            return $definition->model($name);
        }

        $studly = ucfirst($name);
        if ($definition->hasModel($studly)) {
            return $definition->model($studly);
        }

        foreach ($definition->models as $modelName => $model) {
            if (strtolower($modelName) === strtolower($name)) {
                return $model;
            }
        }

        throw new DatabaseException(sprintf('Unknown model [%s].', $name));
    }

    /**
     * Qualify a table with its schema.
     */
    private function qualify(DatasourceDefinition $ds, ModelDefinition $model): string
    {
        if ($this->datasource !== null && $model->datasource !== $this->datasource) {
            throw new DatabaseException(sprintf(
                'Model [%s] belongs to datasource [%s], not the selected datasource [%s].',
                $model->name,
                $model->datasource,
                $this->datasource,
            ));
        }

        if ($this->schema !== null && $model->schema !== '' && $model->schema !== $this->schema) {
            throw new DatabaseException(sprintf(
                'Model [%s] belongs to schema [%s], not the selected schema [%s].',
                $model->name,
                $model->schema,
                $this->schema,
            ));
        }

        if ($model->schema === '') {
            return $model->table;
        }

        return match ($ds->driver) {
            'mysql' => sprintf('`%s`.`%s`', $model->schema, $model->table),
            'pgsql' => sprintf('"%s"."%s"', $model->schema, $model->table),
            default => $model->schema . '_' . $model->table,
        };
    }

    private function driverName(PDO $pdo): string
    {
        try {
            return (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (\Throwable) {
            return '';
        }
    }
}
