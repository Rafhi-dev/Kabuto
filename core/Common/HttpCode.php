<?php

declare(strict_types=1);

namespace Kabuto\Common;

use Attribute;

/**
 * Sets the success status code of a handler without touching the response
 * object.
 *
 *   #[Post()]
 *   #[HttpCode(HttpStatus::CREATED)]
 *   public function create(#[Body()] CreateUserDto $dto): array
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class HttpCode
{
    public function __construct(
        public readonly int $status,
    ) {
    }
}
