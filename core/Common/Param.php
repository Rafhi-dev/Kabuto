<?php

declare(strict_types=1);

namespace Kabuto\Common;

use Attribute;

/**
 * Injects a route parameter into a handler argument.
 *
 *   #[Get(':id')]
 *   public function findOne(#[Param('id')] string $id): array
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Param
{
    public function __construct(
        public readonly ?string $name = null,
    ) {
    }
}
