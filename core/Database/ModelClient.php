<?php

declare(strict_types=1);

namespace Kabuto\Database;

use Kabuto\Exceptions\NotFoundException;

/**
 * Prisma-style model client delegate. Obtained via `$db->user` (`__get` on DB).
 *
 * Operations mirror Prisma Client:
 * - findMany, findFirst, findFirstOrThrow, findUnique, findUniqueOrThrow
 * - create (with nested writes), createMany
 * - update, updateMany, upsert
 * - delete, deleteMany
 * - count, aggregate, groupBy
 */
final class ModelClient
{
    public function __construct(
        private readonly DB $db,
        private readonly ModelDefinition $definition,
    ) {
    }

    public function definition(): ModelDefinition
    {
        return $this->definition;
    }

    /**
     * Find zero or more records.
     *
     * @param array<string,mixed> $args
     * @return list<array<string,mixed>>
     */
    public function findMany(array $args = []): array
    {
        [$queryArgs, $include, $selectMap] = $this->prepareQueryArgs($args);

        $rows = $this->db->query($this->definition)->select($queryArgs);

        if ($include !== []) {
            $rows = $this->db->relations()->load($this->definition, $rows, $include);
        }

        $rows = array_map($this->castOutput(...), $rows);

        if ($selectMap !== null) {
            $rows = array_map(fn (array $row): array => $this->filterSelect($row, $selectMap), $rows);
        }

        return $rows;
    }

    /**
     * Find the first record matching criteria.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>|null
     */
    public function findFirst(array $args = []): ?array
    {
        $args['take'] = 1;
        return $this->findMany($args)[0] ?? null;
    }

    /**
     * Find the first record matching criteria or throw NotFoundException.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     * @throws NotFoundException
     */
    public function findFirstOrThrow(array $args = []): array
    {
        $row = $this->findFirst($args);
        if ($row === null) {
            throw new NotFoundException(sprintf('No %s record found matching the criteria.', $this->definition->name));
        }
        return $row;
    }

    /**
     * Find a unique record by primary key or unique constraint.
     *
     * @param array{where:array<string,mixed>} $args
     * @return array<string,mixed>|null
     */
    public function findUnique(array $args): ?array
    {
        if (!isset($args['where']) || !is_array($args['where'])) {
            throw new DatabaseException('findUnique() requires a [where] argument.');
        }

        return $this->findFirst($args);
    }

    /**
     * Find a unique record or throw NotFoundException.
     *
     * @param array{where:array<string,mixed>} $args
     * @return array<string,mixed>
     * @throws NotFoundException
     */
    public function findUniqueOrThrow(array $args): array
    {
        if (!isset($args['where']) || !is_array($args['where'])) {
            throw new DatabaseException('findUniqueOrThrow() requires a [where] argument.');
        }

        $row = $this->findFirst($args);
        if ($row === null) {
            throw new NotFoundException(sprintf('No %s record found matching the unique condition.', $this->definition->name));
        }

        return $row;
    }

    /**
     * Create a new record with optional nested relation writes.
     *
     * @param array{data:array<string,mixed>} $args
     * @return array<string,mixed>
     */
    public function create(array $args): array
    {
        if (!isset($args['data']) || !is_array($args['data'])) {
            throw new DatabaseException('create() requires a [data] argument.');
        }

        $data = $args['data'];
        $nestedRelations = [];

        // Extract nested writes for relations
        foreach ($this->definition->relations as $relName => $relDef) {
            if (isset($data[$relName]) && is_array($data[$relName])) {
                $nestedRelations[$relName] = [
                    'definition' => $relDef,
                    'operation' => $data[$relName],
                ];
                unset($data[$relName]);
            }
        }

        // Handle belongsTo nested create first (so foreign key is populated)
        foreach ($nestedRelations as $relName => $item) {
            /** @var RelationDefinition $relDef */
            $relDef = $item['definition'];
            $op = $item['operation'];

            if ($relDef->isBelongsTo() && isset($op['create']) && is_array($op['create'])) {
                $targetClient = $this->db->__get(lcfirst($relDef->model));
                $createdParent = $targetClient->create(['data' => $op['create']]);
                $targetPk = $targetClient->definition()->primaryKey();
                if (isset($createdParent[$targetPk])) {
                    $data[$relDef->foreignKey] = $createdParent[$targetPk];
                }
                unset($nestedRelations[$relName]);
            }
        }

        $writableData = $this->filterWritable($data);
        $filteredData = array_filter($writableData, static fn (mixed $value): bool => $value !== null);

        $row = $this->db->query($this->definition)->insert($filteredData);
        $pk = $this->definition->primaryKey();

        // Handle hasMany nested create
        foreach ($nestedRelations as $relName => $item) {
            /** @var RelationDefinition $relDef */
            $relDef = $item['definition'];
            $op = $item['operation'];

            if ($relDef->isHasMany() && isset($op['create']) && is_array($op['create'])) {
                $children = array_is_list($op['create']) ? $op['create'] : [$op['create']];
                $targetClient = $this->db->__get(lcfirst($relDef->model));
                foreach ($children as $child) {
                    if (is_array($child)) {
                        $child[$relDef->foreignKey] = $row[$pk] ?? null;
                        $targetClient->create(['data' => $child]);
                    }
                }
            }
        }

        // Re-read with requested select/include or fresh data
        if (isset($row[$pk])) {
            $readArgs = ['where' => [$pk => $row[$pk]]];
            if (isset($args['include'])) {
                $readArgs['include'] = $args['include'];
            }
            if (isset($args['select'])) {
                $readArgs['select'] = $args['select'];
            }
            return $this->findFirst($readArgs) ?? $row;
        }

        return $row;
    }

    /**
     * Create multiple records in a batch (Prisma createMany).
     *
     * @param array{data:list<array<string,mixed>>,skipDuplicates?:bool} $args
     * @return array{count:int}
     */
    public function createMany(array $args): array
    {
        if (!isset($args['data']) || !is_array($args['data'])) {
            throw new DatabaseException('createMany() requires a [data] list argument.');
        }

        $rows = [];
        foreach ($args['data'] as $item) {
            if (is_array($item)) {
                $rows[] = $this->filterWritable($item);
            }
        }

        if ($rows === []) {
            return ['count' => 0];
        }

        $skipDuplicates = (bool) ($args['skipDuplicates'] ?? false);
        $count = $this->db->query($this->definition)->insertMany($rows, $skipDuplicates);

        return ['count' => $count];
    }

    /**
     * Update an existing record.
     *
     * @param array{where:array<string,mixed>,data:array<string,mixed>} $args
     * @return array<string,mixed>|null
     */
    public function update(array $args): ?array
    {
        if (!isset($args['where']) || !is_array($args['where'])) {
            throw new DatabaseException('update() requires a [where] argument.');
        }
        if (!isset($args['data']) || !is_array($args['data'])) {
            throw new DatabaseException('update() requires a [data] argument.');
        }

        $affected = $this->db->query($this->definition)->update(
            $this->filterWritable($args['data']),
            $args['where'],
        );

        if ($affected === 0) {
            return null;
        }

        $readArgs = ['where' => $args['where']];
        if (isset($args['include'])) {
            $readArgs['include'] = $args['include'];
        }
        if (isset($args['select'])) {
            $readArgs['select'] = $args['select'];
        }

        return $this->findFirst($readArgs);
    }

    /**
     * Update multiple records matching where criteria.
     *
     * @param array{where?:array<string,mixed>,data:array<string,mixed>} $args
     * @return array{count:int}
     */
    public function updateMany(array $args): array
    {
        if (!isset($args['data']) || !is_array($args['data'])) {
            throw new DatabaseException('updateMany() requires a [data] argument.');
        }

        $where = $args['where'] ?? [];
        $count = $this->db->query($this->definition)->updateMany(
            $this->filterWritable($args['data']),
            $where,
        );

        return ['count' => $count];
    }

    /**
     * Create a new record or update an existing one (Prisma upsert).
     *
     * @param array{where:array<string,mixed>,create:array<string,mixed>,update:array<string,mixed>} $args
     * @return array<string,mixed>
     */
    public function upsert(array $args): array
    {
        if (!isset($args['where']) || !is_array($args['where'])) {
            throw new DatabaseException('upsert() requires a [where] argument.');
        }
        if (!isset($args['create']) || !is_array($args['create'])) {
            throw new DatabaseException('upsert() requires a [create] argument.');
        }
        if (!isset($args['update']) || !is_array($args['update'])) {
            throw new DatabaseException('upsert() requires an [update] argument.');
        }

        $existing = $this->findFirst(['where' => $args['where']]);

        if ($existing !== null) {
            $updateArgs = [
                'where' => $args['where'],
                'data' => $args['update'],
            ];
            if (isset($args['include'])) {
                $updateArgs['include'] = $args['include'];
            }
            if (isset($args['select'])) {
                $updateArgs['select'] = $args['select'];
            }
            return $this->update($updateArgs) ?? $existing;
        }

        $createData = array_merge($args['where'], $args['create']);
        $createArgs = [
            'data' => $createData,
        ];
        if (isset($args['include'])) {
            $createArgs['include'] = $args['include'];
        }
        if (isset($args['select'])) {
            $createArgs['select'] = $args['select'];
        }

        return $this->create($createArgs);
    }

    /**
     * Delete a single record.
     *
     * @param array{where:array<string,mixed>} $args
     * @return int|array<string,mixed>
     */
    public function delete(array $args): int|array
    {
        if (!isset($args['where']) || !is_array($args['where'])) {
            throw new DatabaseException('delete() requires a [where] argument.');
        }

        if (isset($args['select'])) {
            $existing = $this->findFirst($args);
            $this->db->query($this->definition)->delete($args['where']);
            return $existing ?? [];
        }

        return $this->db->query($this->definition)->delete($args['where']);
    }

    /**
     * Delete multiple records matching where criteria (Prisma deleteMany).
     *
     * @param array{where?:array<string,mixed>} $args
     * @return array{count:int}
     */
    public function deleteMany(array $args = []): array
    {
        $where = $args['where'] ?? [];
        $count = $this->db->query($this->definition)->deleteMany($where);

        return ['count' => $count];
    }

    /**
     * Count records matching criteria.
     *
     * @param array<string,mixed> $args
     */
    public function count(array $args = []): int
    {
        return $this->db->query($this->definition)->count($args['where'] ?? []);
    }

    /**
     * Prisma-style aggregations (_count, _avg, _sum, _min, _max).
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function aggregate(array $args): array
    {
        return $this->db->query($this->definition)->aggregate($args);
    }

    /**
     * Prisma-style groupBy operation.
     *
     * @param array{by:list<string>} $args
     * @return list<array<string,mixed>>
     */
    public function groupBy(array $args): array
    {
        return $this->db->query($this->definition)->groupBy($args);
    }

    /**
     * Prepares query arguments, separating relation includes and column selects.
     *
     * @param array<string,mixed> $args
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,bool>|null}
     */
    private function prepareQueryArgs(array $args): array
    {
        $include = $args['include'] ?? [];
        unset($args['include']);

        if ($include === true) {
            $include = [];
        }
        $include = is_array($include) ? $include : [];

        $selectMap = null;

        if (isset($args['select']) && is_array($args['select'])) {
            $selectMap = [];
            $scalarSelect = [];

            $isAssoc = array_keys($args['select']) !== range(0, count($args['select']) - 1);
            if ($isAssoc) {
                foreach ($args['select'] as $key => $val) {
                    $keyStr = (string) $key;
                    // If key is a relation, move it to include
                    if (isset($this->definition->relations[$keyStr])) {
                        if ($val === true || is_array($val)) {
                            $include[$keyStr] = $val;
                            $selectMap[$keyStr] = true;
                        }
                    } elseif ($val === true || $val === 1 || $val === 'true') {
                        $scalarSelect[] = $keyStr;
                        $selectMap[$keyStr] = true;
                    }
                }
            } else {
                foreach ($args['select'] as $field) {
                    $fieldStr = (string) $field;
                    if (isset($this->definition->relations[$fieldStr])) {
                        $include[$fieldStr] = true;
                        $selectMap[$fieldStr] = true;
                    } else {
                        $scalarSelect[] = $fieldStr;
                        $selectMap[$fieldStr] = true;
                    }
                }
            }

            if ($scalarSelect !== []) {
                $args['select'] = $scalarSelect;
            } else {
                unset($args['select']);
            }
        }

        return [$args, $include, $selectMap];
    }

    /**
     * Filter returned row columns according to select specification.
     *
     * @param array<string,mixed> $row
     * @param array<string,bool> $selectMap
     * @return array<string,mixed>
     */
    private function filterSelect(array $row, array $selectMap): array
    {
        return array_intersect_key($row, $selectMap);
    }

    /**
     * Decode JSON-typed columns back to arrays.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function castOutput(array $row): array
    {
        foreach ($this->definition->fields as $name => $field) {
            if (strtolower($field->type) !== 'json' || !isset($row[$name]) || is_array($row[$name])) {
                continue;
            }
            $decoded = json_decode((string) $row[$name], true);
            $row[$name] = is_array($decoded) ? $decoded : $row[$name];
        }

        return $row;
    }

    /**
     * Drop unknown columns so hand-written payloads cannot break queries.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function filterWritable(array $data): array
    {
        $fields = $this->definition->fields;

        if ($fields === []) {
            return $data;
        }

        return array_intersect_key($data, $fields);
    }
}
