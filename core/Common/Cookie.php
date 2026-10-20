<?php

declare(strict_types=1);

namespace Kabuto\Common;

use Attribute;

/**
 * Injects a cookie value from the request into a handler argument.
 *
 *   #[Get('/profile')]
 *   public function profile(#[Cookie('token', decrypt: true)] ?string $token): array
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Cookie
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly bool $decrypt = false,
        public readonly mixed $default = null,
    ) {
    }
}
