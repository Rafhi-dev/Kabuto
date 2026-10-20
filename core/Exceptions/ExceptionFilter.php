<?php

declare(strict_types=1);

namespace Kabuto\Exceptions;

use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Throwable;

interface ExceptionFilter
{
    public function catch(Throwable $exception, Request $request): ?Response;
}
