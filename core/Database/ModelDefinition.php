<?php

declare(strict_types=1);

namespace Kabuto\Database;

/**
 * Parsed model definition: table, schema, fields and relations.
 */
final class ModelDefinition
{
    /**
     * @param array<string,FieldDefinition>    $fields
     * @param array<string,RelationDefinition> $relations
     */
    public function __construct(
        public readonly string $name,
        public readonly string $table,
        public readonly string $schema,
        public readonly string $datasource,
        public readonly array $fields = [],
        public readonly array $relations = [],
    ) {
    }

    public function field(string $name): ?FieldDefinition
    {
        return $this->fields[$name] ?? null;
    }

    public function hasRelation(string $name): bool
    {
        return isset($this->relations[$name]);
    }

    public function relation(string $name): ?RelationDefinition
    {
        return $this->relations[$name] ?? null;
    }

    /**
     * Conventional primary key: `id` field, else the first field.
     */
    public function primaryKey(): string
    {
        if (isset($this->fields['id'])) {
            return 'id';
        }

        $first = array_key_first($this->fields);

        if ($first === null) {
            throw new DatabaseException(sprintf('Model [%s] has no fields.', $this->name));
        }

        return $first;
    }
}
