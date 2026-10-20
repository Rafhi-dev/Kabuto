<?php

declare(strict_types=1);

namespace Kabuto\Common;

use Attribute;

/**
 * Marks a class as a controller and sets the route prefix for all of its
 * handler methods.
 *
 *   #[Controller('users')]
 *   final class UserController { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Controller
{
    /**
     * @param string               $path       Route prefix, e.g. 'users' or 'api/users'.
     * @param list<class-string>   $middleware Middleware applied to every method.
     */
    public function __construct(
        public readonly string $path = '',
        public readonly array $middleware = [],
    ) {
    }
}
