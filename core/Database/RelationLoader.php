<?php

declare(strict_types=1);

namespace Kabuto\Database;

/**
 * Loads `include` and relation `select` with batched queries (no N+1).
 * Supports recursive nested relations and filtered/paginated relation loading.
 */
final class RelationLoader
{
    public function __construct(private readonly DB $db)
    {
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed>       $include relation => true|args
     * @return list<array<string,mixed>>
     */
    public function load(ModelDefinition $model, array $rows, array $include): array
    {
        if ($rows === []) {
            return $rows;
        }

        foreach ($include as $name => $spec) {
            $relation = $model->relations[$name] ?? throw new DatabaseException(sprintf(
                'Model [%s] has no relation [%s].',
                $model->name,
                $name,
            ));

            $args = $spec === true ? [] : (is_array($spec) ? $spec : []);
            $rows = $relation->isHasMany()
                ? $this->loadHasMany($model, $relation, $rows, $args)
                : $this->loadBelongsTo($model, $relation, $rows, $args);
        }

        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed>       $args
     * @return list<array<string,mixed>>
     */
    private function loadHasMany(ModelDefinition $model, RelationDefinition $relation, array $rows, array $args): array
    {
        if ($rows === []) {
            return $rows;
        }

        $target = $this->db->definition()->model($relation->model);
        $localKey = $model->primaryKey();

        $ids = array_values(array_unique(array_filter(array_map(
            static fn (array $row): mixed => $row[$localKey] ?? null,
            $rows,
        ), static fn (mixed $id): bool => $id !== null)));

        if ($ids === []) {
            foreach ($rows as &$row) {
                $row[$relation->name] = [];
            }
            unset($row);
            return $rows;
        }

        $queryArgs = $args;
        $nestedInclude = $queryArgs['include'] ?? [];
        unset($queryArgs['include']);

        $queryArgs['where'] = array_merge($queryArgs['where'] ?? [], [
            $relation->foreignKey => ['in' => $ids],
        ]);

        $related = $this->db->query($target)->select($queryArgs);

        // Recursive nested relation loading
        if ($nestedInclude !== [] && is_array($nestedInclude)) {
            $related = $this->load($target, $related, $nestedInclude);
        }

        $grouped = [];
        foreach ($related as $row) {
            $key = $row[$relation->foreignKey] ?? null;
            $grouped[$key][] = $row;
        }

        foreach ($rows as &$row) {
            $row[$relation->name] = $grouped[$row[$localKey] ?? null] ?? [];
        }
        unset($row);

        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed>       $args
     * @return list<array<string,mixed>>
     */
    private function loadBelongsTo(ModelDefinition $model, RelationDefinition $relation, array $rows, array $args): array
    {
        if ($rows === []) {
            return $rows;
        }

        $target = $this->db->definition()->model($relation->model);
        $targetKey = $target->primaryKey();

        $ids = array_values(array_unique(array_filter(array_map(
            static fn (array $row): mixed => $row[$relation->foreignKey] ?? null,
            $rows,
        ), static fn (mixed $id): bool => $id !== null)));

        $map = [];
        if ($ids !== []) {
            $queryArgs = $args;
            $nestedInclude = $queryArgs['include'] ?? [];
            unset($queryArgs['include']);

            $queryArgs['where'] = array_merge($queryArgs['where'] ?? [], [
                $targetKey => ['in' => $ids],
            ]);

            $related = $this->db->query($target)->select($queryArgs);

            // Recursive nested relation loading
            if ($nestedInclude !== [] && is_array($nestedInclude)) {
                $related = $this->load($target, $related, $nestedInclude);
            }

            foreach ($related as $row) {
                $map[$row[$targetKey] ?? null] = $row;
            }
        }

        foreach ($rows as &$row) {
            $row[$relation->name] = $map[$row[$relation->foreignKey] ?? null] ?? null;
        }
        unset($row);

        return $rows;
    }
}
