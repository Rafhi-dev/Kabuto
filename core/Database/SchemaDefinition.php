<?php

declare(strict_types=1);

namespace Kabuto\Database;

/**
 * Immutable, fully-resolved schema: datasources + models with every model
 * bound to exactly one datasource and one SQL schema (Prisma @@schema).
 */
final class SchemaDefinition
{
    /**
     * @param array<string,DatasourceDefinition> $datasources
     * @param array<string,ModelDefinition>      $models
     */
    public function __construct(
        public readonly array $datasources = [],
        public readonly array $models = [],
        public readonly ?string $defaultDatasource = null,
    ) {
        foreach ($this->datasources as $ds) {
            $ds->attachSchemaDefinition($this);
        }
    }

    public function datasource(?string $name = null): DatasourceDefinition
    {
        $name ??= $this->defaultDatasource;

        if ($name === null || !isset($this->datasources[$name])) {
            throw new DatabaseException(sprintf('Unknown datasource [%s].', $name ?? '(null)'));
        }

        return $this->datasources[$name];
    }

    public function model(string $name): ModelDefinition
    {
        if (!isset($this->models[$name])) {
            throw new DatabaseException(sprintf('Unknown model [%s].', $name));
        }

        return $this->models[$name];
    }

    public function hasModel(string $name): bool
    {
        return isset($this->models[$name]);
    }

    /**
     * @return list<ModelDefinition>
     */
    public function modelsForDatasource(string $datasource): array
    {
        return array_values(array_filter(
            $this->models,
            static fn (ModelDefinition $m): bool => $m->datasource === $datasource,
        ));
    }
}
