<?php

declare(strict_types=1);

namespace Kabuto\Common;

use Attribute;

/**
 * Enforces JWT authentication on a controller class or individual route handler method.
 *
 *   #[Auth]
 *   #[Get('/profile')]
 *   public function profile(): array { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Auth
{
    /**
     * @param string|null $guard Optional custom guard / middleware name. Defaults to JwtAuth::class.
     */
    public function __construct(
        public readonly ?string $guard = null,
    ) {
    }
}
