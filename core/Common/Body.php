<?php

declare(strict_types=1);

namespace Kabuto\Common;

use Attribute;

/**
 * Injects the request body into a handler argument. When the argument is
 * typed as a DTO subclass, the body is validated and hydrated automatically
 * (a 422 response is produced on validation failure).
 *
 *   public function create(#[Body()] CreateUserDto $dto): array
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Body
{
    public function __construct(
        public readonly ?string $name = null,
    ) {
    }
}
