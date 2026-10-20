<?php

declare(strict_types=1);

namespace Kabuto\Common;

use Attribute;

/**
 * Specifies the allowed roles for a controller class or route handler method.
 *
 *   #[Roles('admin', 'editor')]
 *   #[Get('/manage')]
 *   public function manage(): array { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Roles
{
    /** @var list<string> */
    public readonly array $roles;

    public function __construct(string ...$roles)
    {
        $this->roles = array_values($roles);
    }

    /** @return list<string> */
    public function roles(): array
    {
        return $this->roles;
    }
}
