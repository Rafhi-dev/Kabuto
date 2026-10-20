<?php

declare(strict_types=1);

namespace Kabuto;

use Kabuto\Container\Container;
use Kabuto\Exceptions\HttpException;
use Kabuto\Exceptions\MethodNotAllowedException;
use Kabuto\Exceptions\NotFoundException;
use Kabuto\Http\HandlerResolver;
use Kabuto\Http\Pipeline;
use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Middleware\ExceptionFilterMiddleware;
use Kabuto\Exceptions\ExceptionContext;
use Kabuto\Routing\Router;
use Throwable;

/**
 * Dispatches an incoming request to the matching route, running global and
 * per-route middleware, and converts exceptions into HTTP responses.
 */
final class Kernel
{
    /** @var callable|null */
    private $errorHandler = null;

    /** @var list<class-string<\Kabuto\Exceptions\ExceptionFilter>|class-string<\Kabuto\Exceptions\ContextualExceptionFilter>> */
    private array $globalExceptionFilters = [];

    private HandlerResolver $resolver;

    /**
     * @param list<callable|string> $middleware
     */
    public function __construct(
        private readonly Router $router,
        private readonly Container $container,
        private readonly array $middleware = [],
    ) {
        $this->resolver = new HandlerResolver($container);
    }

    public function onError(callable $handler): self
    {
        $this->errorHandler = $handler;
        return $this;
    }

    /** @param class-string<\Kabuto\Exceptions\ExceptionFilter>|class-string<\Kabuto\Exceptions\ContextualExceptionFilter> ...$filters */
    public function useGlobalFilters(string ...$filters): self
    {
        foreach ($filters as $filter) {
            if (!class_exists($filter)) throw new \InvalidArgumentException(sprintf('Exception filter [%s] does not exist.', $filter));
            $implementsBasic = is_a($filter, \Kabuto\Exceptions\ExceptionFilter::class, true);
            $implementsContextual = is_a($filter, \Kabuto\Exceptions\ContextualExceptionFilter::class, true);
            if (!$implementsBasic && !$implementsContextual) throw new \InvalidArgumentException(sprintf('Exception filter [%s] must implement an exception filter interface.', $filter));
            $this->globalExceptionFilters[] = $filter;
        }
        return $this;
    }

    public function handle(Request $request): Response
    {
        $this->container->instance(Request::class, $request);
        try {
            $route = null;
            $response = new Response();
            $incoming = trim((string) $request->header('x-request-id', ''));
            $requestId = preg_match('/^[A-Za-z0-9._-]{1,128}$/', $incoming) === 1
                ? $incoming
                : 'req_' . bin2hex(random_bytes(16));
            $request->set('request_id', $requestId);
            try {
                [$route, $params, $pathMatched] = $this->router->resolve($request->method(), $request->path());

                if ($route === null) {
                    throw $pathMatched ? new MethodNotAllowedException() : new NotFoundException();
                }

                $request->setParams($params);
                $request->set('_route_metadata', $route->metadata());
                $request->set('_route_authorization', $route->metadata('authorization'));
                $request->set('_route_public', (bool) $route->metadata('public', false));

                $routeHandler = function (Request $request, Response $response) use ($route): Response {
                    $dispatch = fn (Request $request, Response $response): Response => Pipeline::run(
                        $route->middleware(),
                        $this->resolver->resolve($route->handler()),
                        $request,
                        $response,
                        $this->container,
                    );
                    $filters = (array) $route->metadata('exception_filters', []);
                    if ($filters === []) return $dispatch($request, $response);
                    return (new ExceptionFilterMiddleware($filters, $this->container, $route))($request, $response, $dispatch);
                };

                return Pipeline::run($this->middleware, $routeHandler, $request, $response, $this->container);
            } catch (Throwable $e) {
                if ($this->globalExceptionFilters !== []) {
                    $handled = (new ExceptionFilterMiddleware($this->globalExceptionFilters, $this->container, $route))
                        ->handle($e, new ExceptionContext($request, $response, $route));
                    if ($handled instanceof Response) return $handled;
                }
                return $this->errorResponse($e, $requestId);
            }
        } finally {
            $this->container->forgetInstance(Request::class);
        }
    }

    private function errorResponse(Throwable $e, string $requestId): Response
    {
        if ($this->errorHandler !== null) {
            $result = ($this->errorHandler)($e);
            if ($result instanceof Response) {
                return $result;
            }
        }

        $isDebug = false;
        if (function_exists('config')) {
            $configured = config('app.debug');
            if ($configured !== null) {
                $isDebug = (bool) $configured;
            }
        } elseif (isset($_ENV['APP_DEBUG'])) {
            $isDebug = filter_var($_ENV['APP_DEBUG'], FILTER_VALIDATE_BOOLEAN);
        }

        // Never expose diagnostics when the application declares production,
        // even if a stale APP_DEBUG value is present in the environment.
        $environment = function_exists('env') ? (string) env('APP_ENV', 'local') : (string) ($_ENV['APP_ENV'] ?? 'local');
        if (strtolower($environment) === 'production') {
            $isDebug = false;
        }

        return \Kabuto\Http\ErrorResponse::fromThrowable($e, $requestId, $isDebug)
            ->header('X-Request-ID', $requestId);
    }
}
