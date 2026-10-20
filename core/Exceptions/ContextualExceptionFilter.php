<?php

declare(strict_types=1);

namespace Kabuto\Exceptions;

use Kabuto\Http\Response;
use Throwable;

/** Optional richer filter API; legacy ExceptionFilter implementations remain supported. */
interface ContextualExceptionFilter
{
    public function catchWithContext(Throwable $exception, ExceptionContext $context): ?Response;
}
