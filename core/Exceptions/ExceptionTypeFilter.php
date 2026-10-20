<?php

declare(strict_types=1);

namespace Kabuto\Exceptions;

use Throwable;

/** Optional type constraint for exception filters. */
interface ExceptionTypeFilter
{
    /** @return list<class-string<Throwable>> Empty means the filter accepts every throwable. */
    public function caughtExceptions(): array;
}
