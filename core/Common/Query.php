<?php

declare(strict_types=1);

namespace Kabuto\Common;

use Attribute;

/**
 * Injects a query string value into a handler argument. Without a name, the
 * whole query array is injected.
 *
 *   public function search(#[Query('q')] string $term = ''): array
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Query
{
    public function __construct(
        public readonly ?string $name = null,
    ) {
    }
}
