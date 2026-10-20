<?php

declare(strict_types=1);

namespace Kabuto\Database;

/**
 * Parsed field definition.
 */
final class FieldDefinition
{
    /**
     * @param list<string> $flags  e.g. ['nullable', 'unique', 'index']
     * @param list<string> $params e.g. enum values
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly ?int $length = null,
        public readonly array $flags = [],
        public readonly array $params = [],
        public readonly mixed $default = null,
        public readonly bool $hasDefault = false,
    ) {
    }

    public function has(string $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }

    public function nullable(): bool
    {
        return $this->has('nullable') || $this->has('useCurrent');
    }
}
