<?php

declare(strict_types=1);

namespace Kabuto\Common;

/**
 * Base class for HTTP method attributes.
 */
abstract class RouteMethod
{
    /**
     * @param string             $path       Route path, e.g. '' or ':id'.
     * @param list<class-string> $middleware Middleware applied to this route only.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path = '',
        public readonly array $middleware = [],
    ) {
    }
}
