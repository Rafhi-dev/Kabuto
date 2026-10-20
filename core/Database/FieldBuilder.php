<?php

declare(strict_types=1);

namespace Kabuto\Database;

/**
 * Fluent field builder mirroring Prisma field attributes.
 * Delegates unknown calls back to the parent ModelBuilder for fluent chaining.
 */
final class FieldBuilder
{
    /** @var list<string> */
    private array $flags = [];
    private mixed $default = null;
    private bool $hasDefault = false;
    private ?ModelBuilder $model = null;

    /**
     * @param list<string> $flags
     * @param list<string> $params
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly ?int $length = null,
        array $flags = [],
        public readonly array $params = [],
        ?ModelBuilder $model = null,
    ) {
        $this->flags = $flags;
        $this->model = $model;
    }

    public function model(): ?ModelBuilder
    {
        return $this->model;
    }

    public function setModel(ModelBuilder $model): self
    {
        $this->model = $model;
        return $this;
    }

    public function nullable(bool $val = true): self
    {
        if ($val && !in_array('nullable', $this->flags, true)) {
            $this->flags[] = 'nullable';
        }
        return $this;
    }

    public function unique(bool $val = true): self
    {
        if ($val && !in_array('unique', $this->flags, true)) {
            $this->flags[] = 'unique';
        }
        return $this;
    }

    public function index(bool $val = true): self
    {
        if ($val && !in_array('index', $this->flags, true)) {
            $this->flags[] = 'index';
        }
        return $this;
    }

    public function default(mixed $val): self
    {
        $this->default = $val;
        $this->hasDefault = true;
        return $this;
    }

    public function defaultNow(): self
    {
        if (!in_array('useCurrent', $this->flags, true)) {
            $this->flags[] = 'useCurrent';
        }
        return $this;
    }

    public function updatedAt(): self
    {
        if (!in_array('useCurrent', $this->flags, true)) {
            $this->flags[] = 'useCurrent';
        }
        return $this;
    }

    public function toDefinition(): FieldDefinition
    {
        return new FieldDefinition(
            $this->name,
            $this->type,
            $this->length,
            $this->flags,
            $this->params,
            $this->default,
            $this->hasDefault,
        );
    }

    /**
     * Delegates chained model calls (e.g. ->string(), ->int(), ->hasMany()) back to ModelBuilder.
     *
     * @param list<mixed> $args
     */
    public function __call(string $name, array $args): mixed
    {
        if ($this->model !== null && method_exists($this->model, $name)) {
            return $this->model->$name(...$args);
        }

        throw new \BadMethodCallException(sprintf('Method [%s] does not exist on FieldBuilder or ModelBuilder.', $name));
    }
}
