<?php

declare(strict_types=1);

namespace Kabuto\Database;

use PDO;

/**
 * Driver-aware schema introspection for SQLite (PRAGMA), MySQL
 * (information_schema), and PostgreSQL (pg_catalog / information_schema).
 *
 * All methods take the physical table name as stored by the engine plus the
 * optional namespace (MySQL database / PostgreSQL schema). SQLite stores the
 * schema as a table-name prefix, so `$schema` is ignored there.
 */
final class SchemaIntrospector
{
    private readonly DriverCapabilities $caps;

    public function __construct(private readonly PDO $pdo, private readonly DatasourceDefinition $ds)
    {
        $this->caps = DriverCapabilities::for($ds->driver);
    }

    /**
     * @return list<array{schema:string,name:string}>
     */
    public function tables(?string $schema = null): array
    {
        return match ($this->caps->driver) {
            'mysql' => $this->mysqlTables($schema),
            'pgsql' => $this->pgsqlTables($schema),
            default => $this->sqliteTables(),
        };
    }

    public function tableExists(string $table, ?string $schema = null): bool
    {
        if ($this->caps->driver === 'sqlite') {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
            $stmt->execute([$table]);
            return (bool) $stmt->fetchColumn();
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
        $stmt->execute([$this->namespace($schema), $table]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * @return array<string,true> lowercased column names
     */
    public function columns(string $table, ?string $schema = null): array
    {
        $columns = [];

        if ($this->caps->driver === 'sqlite') {
            $stmt = $this->pdo->query('PRAGMA table_info(' . $this->quoteIdent($table) . ')');
            foreach ($stmt->fetchAll() as $row) {
                $columns[strtolower((string) $row['name'])] = true;
            }
            return $columns;
        }

        $stmt = $this->pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ?');
        $stmt->execute([$this->namespace($schema), $table]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $col) {
            $columns[strtolower((string) $col)] = true;
        }
        return $columns;
    }

    /**
     * @return array<string,true> lowercased columns covered by any non-primary index
     */
    public function indexes(string $table, ?string $schema = null): array
    {
        $covered = [];

        if ($this->caps->driver === 'sqlite') {
            $quotedTable = $this->quoteIdent($table);
            foreach ($this->pdo->query('PRAGMA index_list(' . $quotedTable . ')')->fetchAll() as $index) {
                $indexName = (string) ($index['name'] ?? '');
                if ($indexName === '') {
                    continue;
                }
                foreach ($this->pdo->query('PRAGMA index_info(' . $this->quoteIdent($indexName) . ')')->fetchAll() as $info) {
                    $covered[strtolower((string) $info['name'])] = true;
                }
            }
            return $covered;
        }

        if ($this->caps->driver === 'mysql') {
            $stmt = $this->pdo->prepare('SELECT column_name FROM information_schema.statistics WHERE table_schema = ? AND table_name = ?');
            $stmt->execute([$this->namespace($schema), $table]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $col) {
                $covered[strtolower((string) $col)] = true;
            }
            return $covered;
        }

        $stmt = $this->pdo->prepare(
            'SELECT a.attname
             FROM pg_index i
             JOIN pg_class c ON c.oid = i.indrelid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum = ANY (i.indkey)
             WHERE n.nspname = ? AND c.relname = ? AND NOT i.indisprimary',
        );
        $stmt->execute([$this->namespace($schema), $table]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $col) {
            $covered[strtolower((string) $col)] = true;
        }
        return $covered;
    }

    /**
     * Column => Prisma-style spec (e.g. `String(100) @unique`).
     *
     * @return array<string,string>
     */
    public function fields(string $table, ?string $schema = null): array
    {
        return match ($this->caps->driver) {
            'mysql' => $this->mysqlFields($table, $schema),
            'pgsql' => $this->pgsqlFields($table, $schema),
            default => $this->sqliteFields($table),
        };
    }

    /**
     * Foreign keys: local column => referenced table/column.
     *
     * @return array<string,array{table:string,column:string}>
     */
    public function foreignKeys(string $table, ?string $schema = null): array
    {
        if ($this->caps->driver === 'sqlite') {
            $fks = [];
            $stmt = $this->pdo->query('PRAGMA foreign_key_list(' . $this->quoteIdent($table) . ')');
            foreach ($stmt->fetchAll() as $row) {
                $from = (string) ($row['from'] ?? '');
                if ($from === '') {
                    continue;
                }
                $fks[$from] = [
                    'table' => (string) ($row['table'] ?? ''),
                    'column' => (string) ($row['to'] ?? '') ?: 'id',
                ];
            }
            return $fks;
        }

        if ($this->caps->driver === 'pgsql') {
            // MySQL exposes referenced_* on key_column_usage; PostgreSQL does not,
            // so pair conkey/confkey ordinals through pg_catalog instead.
            $stmt = $this->pdo->prepare(
                'SELECT la.attname AS column_name,
                        rc.relname AS referenced_table_name,
                        ra.attname AS referenced_column_name
                 FROM pg_constraint c
                 JOIN pg_class lc ON lc.oid = c.conrelid
                 JOIN pg_namespace ln ON ln.oid = lc.relnamespace
                 JOIN LATERAL unnest(c.conkey) WITH ORDINALITY AS lk(attnum, ord) ON true
                 JOIN LATERAL unnest(c.confkey) WITH ORDINALITY AS rk(attnum, ord) ON rk.ord = lk.ord
                 JOIN pg_attribute la ON la.attrelid = c.conrelid AND la.attnum = lk.attnum
                 JOIN pg_class rc ON rc.oid = c.confrelid
                 JOIN pg_attribute ra ON ra.attrelid = c.confrelid AND ra.attnum = rk.attnum
                 WHERE c.contype = ? AND ln.nspname = ? AND lc.relname = ?',
            );
            $stmt->execute(['f', $this->namespace($schema), $table]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT column_name, referenced_table_name, referenced_column_name
                 FROM information_schema.key_column_usage
                 WHERE table_schema = ? AND table_name = ? AND referenced_table_name IS NOT NULL',
            );
            $stmt->execute([$this->namespace($schema), $table]);
        }

        $fks = [];
        foreach ($stmt->fetchAll() as $row) {
            $from = (string) $row['column_name'];
            $fks[$from] = [
                'table' => (string) $row['referenced_table_name'],
                'column' => (string) ($row['referenced_column_name'] ?? 'id'),
            ];
        }
        return $fks;
    }

    /**
     * Split a possibly qualified, possibly quoted table reference
     * (`db`.`tbl`, "sch"."tbl", sch.tbl, tbl) into namespace + name.
     *
     * @return array{0:?string,1:string}
     */
    public static function splitRef(string $ref): array
    {
        $parts = preg_split('/\s*\.\s*/', trim($ref)) ?: [];
        $parts = array_map(static fn (string $part): string => trim($part, '`"[]'), $parts);

        if (count($parts) >= 2) {
            return [$parts[0], $parts[count($parts) - 1]];
        }

        return [null, $parts[0] ?? ''];
    }

    /**
     * The connection's default namespace (MySQL DATABASE() / PostgreSQL
     * current_schema()). SQLite has no namespaces and returns ''.
     */
    public function defaultNamespace(): string
    {
        return $this->namespace(null);
    }

    private function namespace(?string $schema): string
    {
        if ($schema !== null && $schema !== '') {
            return $schema;
        }

        if ($this->caps->driver === 'mysql') {
            return (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        }

        if ($this->caps->driver === 'pgsql') {
            return (string) $this->pdo->query('SELECT current_schema()')->fetchColumn();
        }

        return '';
    }

    private function quoteIdent(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /** @return list<array{schema:string,name:string}> */
    private function sqliteTables(): array
    {
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $rows[] = ['schema' => '', 'name' => (string) $name];
        }
        return $rows;
    }

    /** @return list<array{schema:string,name:string}> */
    private function mysqlTables(?string $schema): array
    {
        if ($schema !== null && $schema !== '') {
            $stmt = $this->pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = ? ORDER BY table_name');
            $stmt->execute([$schema, 'BASE TABLE']);
            $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
            return array_map(static fn (mixed $n): array => ['schema' => $schema, 'name' => (string) $n], $names);
        }

        $stmt = $this->pdo->prepare('SELECT table_schema, table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = ? ORDER BY table_name');
        $stmt->execute(['BASE TABLE']);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = ['schema' => (string) $row['table_schema'], 'name' => (string) $row['table_name']];
        }
        return $rows;
    }

    /** @return list<array{schema:string,name:string}> */
    private function pgsqlTables(?string $schema): array
    {
        $sql = 'SELECT table_schema, table_name FROM information_schema.tables WHERE table_type = ?';
        $args = ['BASE TABLE'];
        if ($schema !== null && $schema !== '') {
            $sql .= ' AND table_schema = ?';
            $args[] = $schema;
        } else {
            $sql .= " AND table_schema NOT IN ('pg_catalog', 'information_schema')";
        }
        $sql .= ' ORDER BY table_schema, table_name';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = ['schema' => (string) $row['table_schema'], 'name' => (string) $row['table_name']];
        }
        return $rows;
    }

    /**
     * @return array<string,string>
     */
    private function sqliteFields(string $table): array
    {
        $fields = [];
        $quoted = $this->quoteIdent($table);

        foreach ($this->pdo->query('PRAGMA table_info(' . $quoted . ')')->fetchAll() as $col) {
            $spec = $this->mapSqliteType((string) $col['type'], (bool) $col['pk']);
            if ($spec === 'Int @id @default(autoincrement())') {
                $fields[$col['name']] = $spec;
                continue;
            }
            if (!(bool) $col['notnull'] && !(bool) $col['pk']) {
                $spec .= '?';
            }
            if ($col['dflt_value'] !== null) {
                $dflt = trim((string) $col['dflt_value'], "'\"");
                if (strtoupper($dflt) === 'CURRENT_TIMESTAMP') {
                    $spec .= ' @default(now())';
                } else {
                    $spec .= ' @default(' . $dflt . ')';
                }
            }
            $fields[$col['name']] = $spec;
        }

        foreach ($this->pdo->query('PRAGMA index_list(' . $quoted . ')')->fetchAll() as $index) {
            if (($index['origin'] ?? '') === 'pk') {
                continue;
            }
            $indexName = (string) ($index['name'] ?? '');
            if ($indexName === '') {
                continue;
            }
            $columns = [];
            foreach ($this->pdo->query('PRAGMA index_info(' . $this->quoteIdent($indexName) . ')')->fetchAll() as $info) {
                $columns[] = $info['name'];
            }
            if (count($columns) !== 1) {
                continue;
            }
            $column = (string) $columns[0];
            if (!isset($fields[$column])) {
                continue;
            }
            $attr = (bool) ($index['unique'] ?? false) ? '@unique' : '@index';
            if (!str_contains($fields[$column], $attr)) {
                $fields[$column] .= ' ' . $attr;
            }
        }

        return $fields;
    }

    /**
     * @return array<string,string>
     */
    private function mysqlFields(string $table, ?string $schema): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT column_name, data_type, character_maximum_length, is_nullable, column_default, column_key, extra
             FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position',
        );
        $stmt->execute([$this->namespace($schema), $table]);

        $fields = [];
        foreach ($stmt->fetchAll() as $col) {
            $spec = $this->mapMysqlType($col);
            if ($spec === 'Int @id @default(autoincrement())') {
                $fields[$col['column_name']] = $spec;
                continue;
            }
            if ($col['is_nullable'] === 'YES') {
                $spec .= '?';
            }
            if (in_array($col['column_key'], ['UNI', 'PRI'], true)) {
                $spec .= ' @unique';
            }
            if ($col['column_default'] !== null) {
                $def = (string) $col['column_default'];
                if (strtoupper($def) === 'CURRENT_TIMESTAMP') {
                    $spec .= ' @default(now())';
                } else {
                    $spec .= ' @default(' . trim($def, "'\"") . ')';
                }
            }
            $fields[$col['column_name']] = $spec;
        }

        return $fields;
    }

    /**
     * @return array<string,string>
     */
    private function pgsqlFields(string $table, ?string $schema): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT column_name, data_type, udt_name, character_maximum_length, is_nullable, column_default, is_identity
             FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position',
        );
        $stmt->execute([$this->namespace($schema), $table]);

        $fields = [];
        foreach ($stmt->fetchAll() as $col) {
            $spec = $this->mapPgsqlType($col);
            if ($spec === 'Int @id @default(autoincrement())') {
                $fields[$col['column_name']] = $spec;
                continue;
            }
            if ($col['is_nullable'] === 'YES') {
                $spec .= '?';
            }
            if ($col['column_default'] !== null) {
                $def = (string) $col['column_default'];
                if (str_starts_with($def, 'nextval(')) {
                    $spec .= ' @id @default(autoincrement())';
                    $fields[$col['column_name']] = $spec;
                    continue;
                }
                if (strtoupper($def) === 'CURRENT_TIMESTAMP') {
                    $spec .= ' @default(now())';
                } else {
                    $spec .= ' @default(' . trim($def, "'\"") . ')';
                }
            }
            $fields[$col['column_name']] = $spec;
        }

        $fields = $this->pgsqlUniqueFields($fields, $table, $schema);

        return $fields;
    }

    /**
     * @param array<string,string> $fields
     * @return array<string,string>
     */
    private function pgsqlUniqueFields(array $fields, string $table, ?string $schema): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT kcu.column_name
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
               ON kcu.constraint_name = tc.constraint_name
              AND kcu.table_schema = tc.table_schema
              AND kcu.table_name = tc.table_name
             WHERE tc.constraint_type = 'UNIQUE' AND tc.table_schema = ? AND tc.table_name = ?",
        );
        $stmt->execute([$this->namespace($schema), $table]);

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
            $column = (string) $column;
            if (isset($fields[$column]) && !str_contains($fields[$column], '@unique')) {
                $fields[$column] .= ' @unique';
            }
        }

        return $fields;
    }

    /**
     * @param array<string,mixed> $col
     */
    private function mapMysqlType(array $col): string
    {
        $extra = strtolower((string) ($col['extra'] ?? ''));
        if (str_contains($extra, 'auto_increment')) {
            return 'Int @id @default(autoincrement())';
        }

        return match (strtolower((string) $col['data_type'])) {
            'varchar', 'char' => sprintf('String(%d)', $col['character_maximum_length'] ?? 255),
            'text', 'mediumtext', 'longtext' => 'Text',
            'int', 'integer', 'mediumint', 'smallint' => 'Int',
            'bigint' => 'BigInt',
            'float', 'double' => 'Float',
            'decimal', 'numeric' => 'Decimal',
            'tinyint' => ((int) ($col['character_maximum_length'] ?? 4)) === 1 ? 'Boolean' : 'Int',
            'date', 'datetime', 'timestamp', 'time' => 'DateTime',
            'json' => 'Json',
            'enum' => 'String(50)',
            default => 'Text',
        };
    }

    /**
     * @param array<string,mixed> $col
     */
    private function mapPgsqlType(array $col): string
    {
        if (($col['is_identity'] ?? 'NO') === 'YES') {
            return 'Int @id @default(autoincrement())';
        }

        $length = $col['character_maximum_length'] !== null ? (int) $col['character_maximum_length'] : null;

        return match (strtolower((string) $col['data_type'])) {
            'character varying', 'character' => $length !== null ? sprintf('String(%d)', $length) : 'String',
            'text' => 'Text',
            'integer', 'smallint' => 'Int',
            'bigint' => 'BigInt',
            'real', 'double precision' => 'Float',
            'numeric', 'decimal' => 'Decimal',
            'boolean' => 'Boolean',
            'date', 'timestamp', 'timestamp without time zone', 'timestamp with time zone',
            'time', 'time without time zone', 'time with time zone' => 'DateTime',
            'json', 'jsonb' => 'Json',
            'uuid' => 'String(36)',
            default => 'Text',
        };
    }

    private function mapSqliteType(string $type, bool $pk): string
    {
        $raw = strtoupper(trim($type));
        $length = null;
        if (preg_match('/\((\d+)\)/', $raw, $m)) {
            $length = (int) $m[1];
        }
        $base = strtoupper(trim((string) preg_replace('/\(.*/', '', $raw)));

        if ($pk && $base === 'INTEGER') {
            return 'Int @id @default(autoincrement())';
        }

        return match ($base) {
            'VARCHAR', 'CHAR' => $length !== null ? sprintf('String(%d)', $length) : 'String',
            'TEXT', 'CLOB' => 'Text',
            'INTEGER', 'INT' => 'Int',
            'BIGINT' => 'BigInt',
            'REAL', 'FLOA', 'DOUB' => 'Float',
            'NUMERIC', 'DECIMAL' => 'Decimal',
            'BOOLEAN' => 'Boolean',
            'DATE', 'DATETIME', 'TIMESTAMP', 'TIME' => 'DateTime',
            default => 'Text',
        };
    }
}
