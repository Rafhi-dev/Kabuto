<?php

declare(strict_types=1);

namespace Kabuto\Database;

/**
 * A datasource (named connection) with the SQL schemas it serves,
 * mirroring Prisma's `datasource ... { schemas = [...] }`.
 */
final class DatasourceDefinition
{
    /**
     * @param list<string>           $schemas
     * @param array<string,mixed>    $options driver-specific options
     */
    public function __construct(
        public readonly string $name,
        public readonly string $driver,
        public readonly array $schemas = [],
        public readonly array $options = [],
        private ?SchemaDefinition $schemaDefinition = null,
    ) {
    }

    public function serves(string $schema): bool
    {
        return $this->schemas === [] || in_array($schema, $this->schemas, true);
    }

    public function defaultSchema(): ?string
    {
        return $this->schemas[0] ?? null;
    }

    public function attachSchemaDefinition(SchemaDefinition $schema): void
    {
        $this->schemaDefinition = $schema;
    }

    public function resolveModel(string $name): ModelDefinition
    {
        if ($this->schemaDefinition === null) {
            throw new DatabaseException(sprintf(
                'Datasource [%s] has no SchemaDefinition attached; cannot resolve model [%s].',
                $this->name,
                $name,
            ));
        }

        return $this->schemaDefinition->model($name);
    }
}
