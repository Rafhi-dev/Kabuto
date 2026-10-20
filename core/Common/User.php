<?php

declare(strict_types=1);

namespace Kabuto\Common;

use Attribute;

/**
 * Injects the authenticated user payload (or a specific property of it)
 * into a controller method parameter.
 *
 *   public function me(#[User] array $user)
 *   public function profile(#[User('id')] int $userId, #[User('email')] string $email)
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class User
{
    public function __construct(
        public readonly ?string $property = null,
        public readonly mixed $default = null,
    ) {
    }
}
