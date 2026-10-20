<?php

declare(strict_types=1);

namespace Kabuto\Common;

use Attribute;

/**
 * Specifies the required permissions for a controller class or route handler method.
 *
 *   #[Permissions('posts:delete', 'posts:create')]
 *   #[Post('/posts')]
 *   public function store(): array { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Permissions
{
    /** @var list<string> */
    public readonly array $permissions;

    public function __construct(string ...$permissions)
    {
        $this->permissions = array_values($permissions);
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return $this->permissions;
    }
}
