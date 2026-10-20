<?php

declare(strict_types=1);

namespace Kabuto\Database;

/**
 * Fluent model builder mirroring Prisma model definitions.
 */
final class ModelBuilder
{
    private ?string $table = null;
    private ?string $schema = null;
    private ?string $datasource = null;

    /** @var array<string,FieldBuilder|FieldDefinition|string> */
    private array $fields = [];

    /** @var array<string,RelationDefinition|string> */
    private array $relations = [];

    public function __construct(public readonly string $name)
    {
    }

    public function table(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    /**
     * Prisma @@map("table_name")
     */
    public function map(string $table): self
    {
        return $this->table($table);
    }

    public function schema(string $schema): self
    {
        $this->schema = $schema;
        return $this;
    }

    public function datasource(string $datasource): self
    {
        $this->datasource = $datasource;
        return $this;
    }

    /**
     * Prisma Int @id @default(autoincrement())
     */
    public function id(string $name = 'id'): FieldBuilder
    {
        $builder = new FieldBuilder($name, 'increments', model: $this);
        $this->fields[$name] = $builder;
        return $builder;
    }

    public function int(string $name): FieldBuilder
    {
        $builder = new FieldBuilder($name, 'integer', model: $this);
        $this->fields[$name] = $builder;
        return $builder;
    }

    public function string(string $name, ?int $length = 255): FieldBuilder
    {
        $builder = new FieldBuilder($name, 'string', $length, model: $this);
        $this->fields[$name] = $builder;
        return $builder;
    }

    public function text(string $name): FieldBuilder
    {
        $builder = new FieldBuilder($name, 'text', model: $this);
        $this->fields[$name] = $builder;
        return $builder;
    }

    public function boolean(string $name): FieldBuilder
    {
        $builder = new FieldBuilder($name, 'boolean', model: $this);
        $this->fields[$name] = $builder;
        return $builder;
    }

    public function float(string $name): FieldBuilder
    {
        $builder = new FieldBuilder($name, 'float', model: $this);
        $this->fields[$name] = $builder;
        return $builder;
    }

    public function datetime(string $name): FieldBuilder
    {
        $builder = new FieldBuilder($name, 'datetime', model: $this);
        $this->fields[$name] = $builder;
        return $builder;
    }

    public function timestamp(string $name): FieldBuilder
    {
        $builder = new FieldBuilder($name, 'timestamp', model: $this);
        $this->fields[$name] = $builder;
        return $builder;
    }

    public function json(string $name): FieldBuilder
    {
        $builder = new FieldBuilder($name, 'json', model: $this);
        $this->fields[$name] = $builder;
        return $builder;
    }

    /**
     * @param list<string> $values
     */
    public function enum(string $name, array $values): FieldBuilder
    {
        $builder = new FieldBuilder($name, 'enum', null, [], $values, model: $this);
        $this->fields[$name] = $builder;
        return $builder;
    }

    public function hasMany(string $relationName, string $targetModel, string $foreignKey): self
    {
        $this->relations[$relationName] = new RelationDefinition($relationName, 'hasMany', $targetModel, $foreignKey);
        return $this;
    }

    public function belongsTo(string $relationName, string $targetModel, string $foreignKey): self
    {
        $this->relations[$relationName] = new RelationDefinition($relationName, 'belongsTo', $targetModel, $foreignKey);
        return $this;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $fields = [];
        foreach ($this->fields as $name => $field) {
            $fields[$name] = $field instanceof FieldBuilder ? $field->toDefinition() : $field;
        }

        return [
            'name' => $this->name,
            'table' => $this->table,
            'schema' => $this->schema,
            'datasource' => $this->datasource,
            'fields' => $fields,
            'relations' => $this->relations,
        ];
    }
}
