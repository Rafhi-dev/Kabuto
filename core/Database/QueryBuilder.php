<?php

declare(strict_types=1);

namespace Kabuto\Database;

use PDO;

/**
 * Compiles Prisma-style query arguments into parameterized SQL and executes
 * them via PDO. Supports SQLite, MySQL, and PostgreSQL dialects.
 *
 * Where operators: equals, not, in, notIn, lt, lte, gt, gte,
 * contains, startsWith, endsWith, isNull, isNotNull, mode: 'insensitive'.
 * Logical operators: AND, OR, NOT.
 * Aggregations: _count, _avg, _sum, _min, _max.
 */
final class QueryBuilder
{
    private int $counter = 0;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $driver,
        private readonly string $table,
    ) {
    }

    /**
     * @param array<string,mixed> $args
     * @return list<array<string,mixed>>
     */
    public function select(array $args = []): array
    {
        [$sql, $bindings] = $this->compileSelect($args);
        return $this->fetchAll($sql, $bindings);
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|null
     */
    public function selectOne(array $args = []): ?array
    {
        $args['take'] = 1;
        $rows = $this->select($args);
        return $rows[0] ?? null;
    }

    public function count(array $where = []): int
    {
        $bindings = [];
        $sql = sprintf('SELECT COUNT(*) AS aggregate FROM %s', $this->table);

        if ($where !== []) {
            $sql .= ' WHERE ' . $this->compileWhere($where, $bindings);
        }

        $rows = $this->fetchAll($sql, $bindings);
        return (int) ($rows[0]['aggregate'] ?? 0);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed> the inserted row
     */
    public function insert(array $data): array
    {
        if ($data === []) {
            throw new DatabaseException('Cannot insert an empty row.');
        }

        $columns = array_keys($data);
        $placeholders = [];
        $bindings = [];

        foreach ($data as $value) {
            $placeholders[] = $this->param($value, $bindings);
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(', ', array_map($this->wrap(...), $columns)),
            implode(', ', $placeholders),
        );

        $this->execute($sql, $bindings);
        $id = $this->lastInsertId();

        if ($id !== false && $id !== '' && $id !== '0') {
            $data['id'] = ctype_digit((string) $id) ? (int) $id : $id;
        }

        return $data;
    }

    /**
     * Batch insert multiple records (Prisma createMany).
     *
     * @param list<array<string,mixed>> $rows
     */
    public function insertMany(array $rows, bool $skipDuplicates = false): int
    {
        if ($rows === []) {
            return 0;
        }

        $columns = array_keys($rows[0]);
        $wrappedCols = implode(', ', array_map($this->wrap(...), $columns));
        $bindings = [];
        $valuesClauses = [];

        foreach ($rows as $row) {
            $placeholders = [];
            foreach ($columns as $col) {
                $placeholders[] = $this->param($row[$col] ?? null, $bindings);
            }
            $valuesClauses[] = '(' . implode(', ', $placeholders) . ')';
        }

        $ignore = '';
        if ($skipDuplicates) {
            $ignore = match ($this->driver) {
                'mysql' => 'IGNORE ',
                'sqlite' => 'OR IGNORE ',
                default => '',
            };
        }

        $sql = sprintf(
            'INSERT %sINTO %s (%s) VALUES %s',
            $ignore,
            $this->table,
            $wrappedCols,
            implode(', ', $valuesClauses),
        );

        return $this->execute($sql, $bindings);
    }

    /**
     * @param array<string,mixed> $data
     * @return int affected rows
     */
    public function update(array $data, array $where): int
    {
        if ($data === []) {
            throw new DatabaseException('Cannot update with empty data.');
        }
        if ($where === []) {
            throw new DatabaseException('Refusing to update without a where clause.');
        }

        return $this->updateMany($data, $where);
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where
     * @return int affected rows
     */
    public function updateMany(array $data, array $where = []): int
    {
        if ($data === []) {
            throw new DatabaseException('Cannot update with empty data.');
        }

        $bindings = [];
        $sets = [];
        foreach ($data as $column => $value) {
            $sets[] = sprintf('%s = %s', $this->wrap($column), $this->param($value, $bindings));
        }

        $sql = sprintf('UPDATE %s SET %s', $this->table, implode(', ', $sets));

        if ($where !== []) {
            $sql .= ' WHERE ' . $this->compileWhere($where, $bindings);
        }

        return $this->execute($sql, $bindings);
    }

    /**
     * @return int affected rows
     */
    public function delete(array $where): int
    {
        if ($where === []) {
            throw new DatabaseException('Refusing to delete without a where clause.');
        }

        return $this->deleteMany($where);
    }

    /**
     * @param array<string,mixed> $where
     * @return int affected rows
     */
    public function deleteMany(array $where = []): int
    {
        $bindings = [];
        $sql = sprintf('DELETE FROM %s', $this->table);

        if ($where !== []) {
            $sql .= ' WHERE ' . $this->compileWhere($where, $bindings);
        }

        return $this->execute($sql, $bindings);
    }

    /**
     * Prisma-style aggregation (_count, _avg, _sum, _min, _max).
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function aggregate(array $args): array
    {
        $bindings = [];
        $selects = [];
        $map = [];

        if (!empty($args['_count'])) {
            if ($args['_count'] === true) {
                $selects[] = 'COUNT(*) AS _count_all';
                $map['_count_all'] = ['_count', '_all'];
            } elseif (is_array($args['_count'])) {
                foreach ($args['_count'] as $field => $enabled) {
                    if ($enabled) {
                        $alias = '_count_' . str_replace('.', '_', (string) $field);
                        $selects[] = $field === '_all' || $field === '*'
                            ? 'COUNT(*) AS ' . $alias
                            : sprintf('COUNT(%s) AS %s', $this->wrap((string) $field), $alias);
                        $map[$alias] = ['_count', (string) $field];
                    }
                }
            }
        }

        foreach (['_avg' => 'AVG', '_sum' => 'SUM', '_min' => 'MIN', '_max' => 'MAX'] as $key => $func) {
            if (!empty($args[$key]) && is_array($args[$key])) {
                foreach ($args[$key] as $field => $enabled) {
                    if ($enabled) {
                        $alias = $key . '_' . str_replace('.', '_', (string) $field);
                        $selects[] = sprintf('%s(%s) AS %s', $func, $this->wrap((string) $field), $alias);
                        $map[$alias] = [$key, (string) $field];
                    }
                }
            }
        }

        if ($selects === []) {
            $selects[] = 'COUNT(*) AS _count_all';
            $map['_count_all'] = ['_count', '_all'];
        }

        $sql = sprintf('SELECT %s FROM %s', implode(', ', $selects), $this->table);

        if (isset($args['where']) && is_array($args['where']) && $args['where'] !== []) {
            $sql .= ' WHERE ' . $this->compileWhere($args['where'], $bindings);
        }

        $row = $this->fetchAll($sql, $bindings)[0] ?? [];

        $result = [];
        foreach ($map as $alias => [$groupKey, $subKey]) {
            $val = $row[$alias] ?? null;
            if ($groupKey === '_count' && ($args['_count'] ?? null) === true) {
                $result['_count'] = (int) $val;
            } else {
                if ($groupKey === '_count') {
                    $result[$groupKey][$subKey] = (int) $val;
                } elseif ($val !== null && is_numeric($val)) {
                    $result[$groupKey][$subKey] = str_contains((string) $val, '.') ? (float) $val : (int) $val;
                } else {
                    $result[$groupKey][$subKey] = $val;
                }
            }
        }

        return $result;
    }

    /**
     * Prisma-style groupBy operation.
     *
     * @param array<string,mixed> $args
     * @return list<array<string,mixed>>
     */
    public function groupBy(array $args): array
    {
        if (empty($args['by']) || !is_array($args['by'])) {
            throw new DatabaseException('groupBy() requires a non-empty [by] argument.');
        }

        $bindings = [];
        $byCols = array_map($this->wrap(...), $args['by']);
        $selects = $byCols;
        $map = [];

        if (!empty($args['_count'])) {
            if ($args['_count'] === true) {
                $selects[] = 'COUNT(*) AS _count_all';
                $map['_count_all'] = ['_count', '_all'];
            } elseif (is_array($args['_count'])) {
                foreach ($args['_count'] as $field => $enabled) {
                    if ($enabled) {
                        $alias = '_count_' . str_replace('.', '_', (string) $field);
                        $selects[] = $field === '_all' || $field === '*'
                            ? 'COUNT(*) AS ' . $alias
                            : sprintf('COUNT(%s) AS %s', $this->wrap((string) $field), $alias);
                        $map[$alias] = ['_count', (string) $field];
                    }
                }
            }
        }

        foreach (['_avg' => 'AVG', '_sum' => 'SUM', '_min' => 'MIN', '_max' => 'MAX'] as $key => $func) {
            if (!empty($args[$key]) && is_array($args[$key])) {
                foreach ($args[$key] as $field => $enabled) {
                    if ($enabled) {
                        $alias = $key . '_' . str_replace('.', '_', (string) $field);
                        $selects[] = sprintf('%s(%s) AS %s', $func, $this->wrap((string) $field), $alias);
                        $map[$alias] = [$key, (string) $field];
                    }
                }
            }
        }

        $sql = sprintf('SELECT %s FROM %s', implode(', ', $selects), $this->table);

        if (isset($args['where']) && is_array($args['where']) && $args['where'] !== []) {
            $sql .= ' WHERE ' . $this->compileWhere($args['where'], $bindings);
        }

        $sql .= ' GROUP BY ' . implode(', ', $byCols);

        if (isset($args['having']) && is_array($args['having']) && $args['having'] !== []) {
            $sql .= ' HAVING ' . $this->compileWhere($args['having'], $bindings);
        }

        if (isset($args['orderBy']) && is_array($args['orderBy'])) {
            $order = $this->compileOrderBy($args['orderBy']);
            if ($order !== '') {
                $sql .= ' ORDER BY ' . $order;
            }
        }

        if (isset($args['take'])) {
            $sql .= sprintf(' LIMIT %d', max(0, (int) $args['take']));
        }

        if (isset($args['skip'])) {
            $skip = max(0, (int) $args['skip']);
            if (!isset($args['take'])) {
                $sql .= $this->driver === 'mysql' ? ' LIMIT 18446744073709551615' : ' LIMIT -1';
            }
            $sql .= sprintf(' OFFSET %d', $skip);
        }

        $rows = $this->fetchAll($sql, $bindings);

        return array_map(function (array $row) use ($args, $map): array {
            $item = [];
            foreach ($args['by'] as $col) {
                $item[$col] = $row[$col] ?? null;
            }
            foreach ($map as $alias => [$groupKey, $subKey]) {
                $val = $row[$alias] ?? null;
                if ($groupKey === '_count' && ($args['_count'] ?? null) === true) {
                    $item['_count'] = (int) $val;
                } else {
                    if ($groupKey === '_count') {
                        $item[$groupKey][$subKey] = (int) $val;
                    } elseif ($val !== null && is_numeric($val)) {
                        $item[$groupKey][$subKey] = str_contains((string) $val, '.') ? (float) $val : (int) $val;
                    } else {
                        $item[$groupKey][$subKey] = $val;
                    }
                }
            }
            return $item;
        }, $rows);
    }

    /**
     * @param array<string,mixed> $args
     * @return array{0:string,1:array<string,mixed>}
     */
    public function compileSelect(array $args = []): array
    {
        $bindings = [];

        $columns = '*';
        if (isset($args['select']) && is_array($args['select']) && $args['select'] !== []) {
            $cols = [];
            $isAssoc = array_keys($args['select']) !== range(0, count($args['select']) - 1);
            if ($isAssoc) {
                foreach ($args['select'] as $field => $enabled) {
                    if ($enabled === true || $enabled === 1 || $enabled === 'true') {
                        $cols[] = (string) $field;
                    }
                }
            } else {
                $cols = array_map('strval', array_values($args['select']));
            }

            if ($cols !== []) {
                $columns = implode(', ', array_map($this->wrap(...), $cols));
            }
        }

        $distinct = !empty($args['distinct']) ? 'DISTINCT ' : '';
        $sql = sprintf('SELECT %s%s FROM %s', $distinct, $columns, $this->table);

        if (isset($args['where']) && is_array($args['where']) && $args['where'] !== []) {
            $sql .= ' WHERE ' . $this->compileWhere($args['where'], $bindings);
        }

        if (isset($args['orderBy']) && is_array($args['orderBy'])) {
            $order = $this->compileOrderBy($args['orderBy']);
            if ($order !== '') {
                $sql .= ' ORDER BY ' . $order;
            }
        }

        if (isset($args['take'])) {
            $sql .= sprintf(' LIMIT %d', max(0, (int) $args['take']));
        }

        if (isset($args['skip'])) {
            $skip = max(0, (int) $args['skip']);
            if (!isset($args['take'])) {
                $sql .= $this->driver === 'mysql' ? ' LIMIT 18446744073709551615' : ' LIMIT -1';
            }
            $sql .= sprintf(' OFFSET %d', $skip);
        }

        return [$sql, $bindings];
    }

    /**
     * @param array<string,mixed> $orderBy
     */
    public function compileOrderBy(array $orderBy): string
    {
        $parts = [];

        // Support array of order objects: [['name' => 'asc'], ['id' => 'desc']]
        if (array_is_list($orderBy)) {
            foreach ($orderBy as $clause) {
                if (is_array($clause)) {
                    foreach ($clause as $column => $direction) {
                        $dir = strtolower((string) $direction) === 'desc' ? 'DESC' : 'ASC';
                        $parts[] = sprintf('%s %s', $this->wrap((string) $column), $dir);
                    }
                }
            }
            return implode(', ', $parts);
        }

        foreach ($orderBy as $column => $direction) {
            $dir = strtolower((string) $direction) === 'desc' ? 'DESC' : 'ASC';
            $parts[] = sprintf('%s %s', $this->wrap((string) $column), $dir);
        }

        return implode(', ', $parts);
    }

    /**
     * @param array<string,mixed> $where
     * @param array<string,mixed> $bindings
     */
    public function compileWhere(array $where, array &$bindings): string
    {
        $parts = [];

        foreach ($where as $column => $condition) {
            if ($column === 'OR' && is_array($condition)) {
                $or = [];
                foreach ($condition as $branch) {
                    if (is_array($branch) && $branch !== []) {
                        $or[] = '(' . $this->compileWhere($branch, $bindings) . ')';
                    }
                }
                if ($or !== []) {
                    $parts[] = '(' . implode(' OR ', $or) . ')';
                }
                continue;
            }

            if ($column === 'AND' && is_array($condition)) {
                $and = [];
                foreach ($condition as $branch) {
                    if (is_array($branch) && $branch !== []) {
                        $and[] = '(' . $this->compileWhere($branch, $bindings) . ')';
                    }
                }
                if ($and !== []) {
                    $parts[] = '(' . implode(' AND ', $and) . ')';
                }
                continue;
            }

            if ($column === 'NOT') {
                if (is_array($condition) && $condition !== []) {
                    if (array_is_list($condition)) {
                        $notList = [];
                        foreach ($condition as $branch) {
                            if (is_array($branch) && $branch !== []) {
                                $notList[] = 'NOT (' . $this->compileWhere($branch, $bindings) . ')';
                            }
                        }
                        if ($notList !== []) {
                            $parts[] = '(' . implode(' AND ', $notList) . ')';
                        }
                    } else {
                        $parts[] = 'NOT (' . $this->compileWhere($condition, $bindings) . ')';
                    }
                    continue;
                }
            }

            $parts[] = $this->compileCondition((string) $column, $condition, $bindings);
        }

        return implode(' AND ', $parts);
    }

    /**
     * @param array<string,mixed> $bindings
     */
    private function compileCondition(string $column, mixed $condition, array &$bindings): string
    {
        $col = $this->wrap($column);

        if ($condition === null) {
            return sprintf('%s IS NULL', $col);
        }

        if (!is_array($condition)) {
            return sprintf('%s = %s', $col, $this->param($condition, $bindings));
        }

        $insensitive = isset($condition['mode']) && strtolower((string) $condition['mode']) === 'insensitive';

        $parts = [];
        foreach ($condition as $operator => $value) {
            $op = strtolower((string) $operator);
            if ($op === 'mode') {
                continue;
            }

            $parts[] = match ($op) {
                'equals' => $value === null
                    ? sprintf('%s IS NULL', $col)
                    : sprintf('%s = %s', $col, $this->param($value, $bindings)),
                'not' => $value === null
                    ? sprintf('%s IS NOT NULL', $col)
                    : (is_array($value)
                        ? sprintf('NOT (%s)', $this->compileCondition($column, $value, $bindings))
                        : sprintf('%s <> %s', $col, $this->param($value, $bindings))),
                'in' => $this->compileIn($col, $value, $bindings, false),
                'notin' => $this->compileIn($col, $value, $bindings, true),
                'lt' => sprintf('%s < %s', $col, $this->param($value, $bindings)),
                'lte' => sprintf('%s <= %s', $col, $this->param($value, $bindings)),
                'gt' => sprintf('%s > %s', $col, $this->param($value, $bindings)),
                'gte' => sprintf('%s >= %s', $col, $this->param($value, $bindings)),
                'contains' => $insensitive
                    ? sprintf('LOWER(%s) LIKE LOWER(%s)', $col, $this->param('%' . $value . '%', $bindings))
                    : sprintf('%s LIKE %s', $col, $this->param('%' . $value . '%', $bindings)),
                'startswith' => $insensitive
                    ? sprintf('LOWER(%s) LIKE LOWER(%s)', $col, $this->param($value . '%', $bindings))
                    : sprintf('%s LIKE %s', $col, $this->param($value . '%', $bindings)),
                'endswith' => $insensitive
                    ? sprintf('LOWER(%s) LIKE LOWER(%s)', $col, $this->param('%' . $value, $bindings))
                    : sprintf('%s LIKE %s', $col, $this->param('%' . $value, $bindings)),
                'isnull' => $value ? sprintf('%s IS NULL', $col) : sprintf('%s IS NOT NULL', $col),
                'isnotnull' => $value ? sprintf('%s IS NOT NULL', $col) : sprintf('%s IS NULL', $col),
                default => throw new DatabaseException(sprintf('Unknown where operator [%s].', $operator)),
            };
        }

        return implode(' AND ', $parts);
    }

    /**
     * @param array<string,mixed> $bindings
     */
    private function compileIn(string $col, mixed $value, array &$bindings, bool $negate): string
    {
        $values = is_array($value) ? array_values($value) : [$value];

        if ($values === []) {
            return $negate ? '1 = 1' : '1 = 0';
        }

        $placeholders = [];
        foreach ($values as $item) {
            $placeholders[] = $this->param($item, $bindings);
        }

        return sprintf('%s %sIN (%s)', $col, $negate ? 'NOT ' : '', implode(', ', $placeholders));
    }

    /**
     * @param array<string,mixed> $bindings
     */
    private function param(mixed $value, array &$bindings): string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        } elseif ($value instanceof \UnitEnum) {
            $value = $value->name;
        } elseif (is_bool($value)) {
            $value = $this->driver === 'mysql' ? (int) $value : $value;
        } elseif (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new DatabaseException('Unable to encode value as JSON: ' . json_last_error_msg());
            }
            $value = $encoded;
        }

        $name = ':p' . (++$this->counter);
        $bindings[$name] = $value;
        return $name;
    }

    private function wrap(string $identifier): string
    {
        if ($identifier === '*') {
            return '*';
        }

        return match ($this->driver) {
            'mysql' => '`' . str_replace('`', '``', $identifier) . '`',
            default => '"' . str_replace('"', '""', $identifier) . '"',
        };
    }

    /**
     * @param array<string,mixed> $bindings
     * @return list<array<string,mixed>>
     */
    public function fetchAll(string $sql, array $bindings = []): array
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw DatabaseException::wrap($e, $this->driver, 'query');
        }
    }

    /**
     * @param array<string,mixed> $bindings
     */
    public function execute(string $sql, array $bindings = []): int
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            throw DatabaseException::wrap($e, $this->driver, 'query');
        }
    }

    /**
     * PostgreSQL identity/sequence inserts raise when no sequence was touched
     * in this session; treat that as "no generated id" instead of failing.
     */
    private function lastInsertId(): string|false
    {
        try {
            return $this->pdo->lastInsertId();
        } catch (\Throwable) {
            return false;
        }
    }
}
