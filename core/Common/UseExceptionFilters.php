<?php

declare(strict_types=1);

namespace Kabuto\Common;

use Attribute;
use Kabuto\Exceptions\ContextualExceptionFilter;
use Kabuto\Exceptions\ExceptionFilter;

/** Assigns exception filters to a controller class or an individual route method. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class UseExceptionFilters
{
    /** @param list<class-string<ExceptionFilter>|class-string<ContextualExceptionFilter>> $filters */
    public function __construct(public readonly array $filters)
    {
        foreach ($filters as $filter) {
            if (!is_string($filter) || (!is_a($filter, ExceptionFilter::class, true) && !is_a($filter, ContextualExceptionFilter::class, true))) {
                throw new \InvalidArgumentException('Every exception filter must implement an exception filter interface.');
            }
        }
    }
}
