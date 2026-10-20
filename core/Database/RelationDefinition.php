<?php

declare(strict_types=1);

namespace Kabuto\Database;

/**
 * Parsed relation definition (hasMany / belongsTo).
 */
final class RelationDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly string $model,
        public readonly string $foreignKey,
        public readonly string $references = 'id',
    ) {
    }

    public function isHasMany(): bool
    {
        return $this->kind === 'hasMany';
    }

    public function isBelongsTo(): bool
    {
        return $this->kind === 'belongsTo';
    }
}
