<?php

declare(strict_types=1);

namespace Kabuto\Database;

use PDO;
use Kabuto\Observability\MetricsRegistry;

/**
 * Lazily creates and caches one PDO connection per datasource name.
 */
final class ConnectionManager
{
    /** @var array<string,PDO> */
    private array $connections = [];
    private ?MetricsRegistry $metrics = null;

    public function __construct(private readonly SchemaDefinition $schema)
    {
    }

    public function connection(?string $datasource = null): PDO
    {
        $ds = $this->schema->datasource($datasource);

        return $this->connections[$ds->name] ??= $this->connect($ds);
    }

    public function disconnect(?string $datasource = null): void
    {
        if ($datasource === null) {
            $this->connections = [];
        } else {
            unset($this->connections[$datasource]);
        }
    }

    public function reconnect(?string $datasource = null): PDO
    {
        $this->disconnect($datasource);

        return $this->connection($datasource);
    }

    /**
     * @return array<string,PDO>
     */
    public function activeConnections(): array
    {
        return $this->connections;
    }

    public function isConnected(?string $datasource = null): bool
    {
        $ds = $this->schema->datasource($datasource);
        if (!isset($this->connections[$ds->name])) {
            return false;
        }

        try {
            $this->connections[$ds->name]->query('SELECT 1');
            return true;
        } catch (\Throwable) {
            unset($this->connections[$ds->name]);
            return false;
        }
    }

    /**
     * Inject an already-open PDO (testing, or externally managed connections).
     */
    public function instance(string $datasource, \PDO $pdo): void
    {
        $this->schema->datasource($datasource);
        $this->connections[$datasource] = $pdo;
    }

    public function schema(): SchemaDefinition
    {
        return $this->schema;
    }

    public function withMetrics(?MetricsRegistry $metrics): self
    {
        $this->metrics = $metrics;
        return $this;
    }

    private function connect(DatasourceDefinition $ds): PDO
    {
        $started = microtime(true);
        try {
            $pdo = match ($ds->driver) {
                'sqlite' => $this->connectSqlite($ds),
                'mysql' => $this->connectMysql($ds),
                'pgsql', 'postgres', 'postgresql' => $this->connectPostgres($ds),
                default => throw new DatabaseException(sprintf('Unsupported driver [%s].', $ds->driver)),
            };
        } catch (DatabaseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw DatabaseException::wrap($e, $ds->driver, sprintf('connect datasource [%s]', $ds->name));
        }

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        $this->metrics?->observe('kabuto_database_connection_duration_ms', (microtime(true) - $started) * 1000, ['driver' => $ds->driver]);
        return $pdo;
    }

    private function connectSqlite(DatasourceDefinition $ds): PDO
    {
        $path = (string) ($ds->options['path'] ?? ':memory:');

        if ($path !== ':memory:' && !str_contains($path, '://')) {
            $directory = dirname($path);
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
        }

        return new PDO('sqlite:' . $path);
    }

    private function connectMysql(DatasourceDefinition $ds): PDO
    {
        $o = $ds->options;

        foreach (['host', 'database', 'username'] as $key) {
            if (!isset($o[$key]) || trim((string) $o[$key]) === '') {
                throw new DatabaseException(sprintf(
                    'Datasource [%s] is missing [%s]. Set it via .env (DB_HOST / DB_DATABASE / DB_USERNAME).',
                    $ds->name,
                    $key,
                ));
            }
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $o['host'],
            $o['port'] ?? 3306,
            $o['database'],
            $o['charset'] ?? 'utf8mb4',
        );

        return new PDO($dsn, (string) $o['username'], (string) ($o['password'] ?? ''));
    }

    private function connectPostgres(DatasourceDefinition $ds): PDO
    {
        $o = $ds->options;
        foreach (['host', 'database', 'username'] as $key) {
            if (!isset($o[$key]) || trim((string) $o[$key]) === '') throw new DatabaseException("Datasource [{$ds->name}] is missing [{$key}].");
        }
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $o['host'], $o['port'] ?? 5432, $o['database']);
        return new PDO($dsn, (string) $o['username'], (string) ($o['password'] ?? ''));
    }
}
