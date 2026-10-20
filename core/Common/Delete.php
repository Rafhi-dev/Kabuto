<?php

declare(strict_types=1);

namespace Kabuto\Common;

use Attribute;

/**
 * Declares a DELETE route on a controller method.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Delete extends RouteMethod
{
    /**
     * @param list<class-string> $middleware
     */
    public function __construct(string $path = '', array $middleware = [])
    {
        parent::__construct('DELETE', $path, $middleware);
    }
}
