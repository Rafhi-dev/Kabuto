<?php

declare(strict_types=1);

namespace Kabuto\Middleware;

use Kabuto\Container\Container;
use Kabuto\Exceptions\ContextualExceptionFilter;
use Kabuto\Exceptions\ExceptionFilter;
use Kabuto\Exceptions\ExceptionContext;
use Kabuto\Exceptions\ExceptionTypeFilter;
use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Routing\Route;
use Throwable;

final class ExceptionFilterMiddleware
{
    /** @param list<class-string<ExceptionFilter>|class-string<ContextualExceptionFilter>> $filters */
    public function __construct(
        private readonly array $filters,
        private readonly ?Container $container = null,
        private readonly ?Route $route = null,
    ) {}

    public function __invoke(Request $request, Response $response, callable $next): Response
    {
        try {
            return $next($request, $response);
        } catch (Throwable $exception) {
            $handled = $this->handle($exception, new ExceptionContext($request, $response, $this->route));
            if ($handled instanceof Response) return $handled;
            throw $exception;
        }
    }

    public function handle(Throwable $exception, ExceptionContext $context): ?Response
    {
        foreach ($this->filters as $filterClass) {
            $filter = $this->container?->get($filterClass) ?? new $filterClass();
            if (!$filter instanceof ExceptionFilter && !$filter instanceof ContextualExceptionFilter) {
                throw new \LogicException(sprintf('Exception filter [%s] must implement an exception filter interface.', $filterClass));
            }
            if ($filter instanceof ExceptionTypeFilter && !$this->matches($filter->caughtExceptions(), $exception)) continue;
            $handled = $filter instanceof ContextualExceptionFilter
                ? $filter->catchWithContext($exception, $context)
                : $filter->catch($exception, $context->request);
            if ($handled instanceof Response) return $handled;
        }
        return null;
    }

    private function matches(array $exceptions, Throwable $exception): bool
    {
        if ($exceptions === []) return true;
        foreach ($exceptions as $caught) if ($exception instanceof $caught) return true;
        return false;
    }
}
