<?php

declare(strict_types=1);

namespace Kabuto\Common;

use Attribute;

/**
 * Declares a GET route on a controller method.
 *
 *   #[Get()]
 *   #[Get(':id')]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Get extends RouteMethod
{
    /**
     * @param list<class-string> $middleware
     */
    public function __construct(string $path = '', array $middleware = [])
    {
        parent::__construct('GET', $path, $middleware);
    }
}
