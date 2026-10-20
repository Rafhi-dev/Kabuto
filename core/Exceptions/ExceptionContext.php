<?php

declare(strict_types=1);

namespace Kabuto\Exceptions;

use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Routing\Route;

final readonly class ExceptionContext
{
    public function __construct(
        public Request $request,
        public Response $response,
        public ?Route $route = null,
    ) {
    }
}
