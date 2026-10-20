<?php

declare(strict_types=1);

namespace Kabuto\Http;

use Closure;
use JsonSerializable;
use Kabuto\Container\Container;

final class Pipeline
{
    /**
     * Run a stack of middleware onion-style, ending in the given handler.
     *
     * A middleware receives (Request $request, Response $response, callable $next) and
     * must return a Response. Calling $next() continues the chain.
     *
     * @param list<callable|string> $middleware
     */
    public static function run(
        array $middleware,
        callable $handler,
        Request $request,
        Response $response,
        ?Container $container = null,
    ): Response {
        $next = function (Request $request, Response $response) use ($handler): Response {
            return self::normalize($handler($request, $response), $response);
        };

        foreach (array_reverse($middleware) as $layer) {
            $next = self::wrap($layer, $next, $container);
        }

        return $next($request, $response);
    }

    /**
     * Coerce a handler return value into a Response.
     *
     * - Response: used as-is
     * - array / JsonSerializable / object with toArray(): JSON
     * - null: the existing response
     * - scalar / Stringable: plain body
     */
    public static function normalize(mixed $result, Response $response): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result) || $result instanceof JsonSerializable) {
            return $response->json($result);
        }

        if (is_object($result) && method_exists($result, 'toArray')) {
            return $response->json($result->toArray());
        }

        if ($result === null) {
            return $response;
        }

        return $response->send((string) $result);
    }

    /**
     * @param callable|string $layer
     */
    private static function wrap(callable|string $layer, callable $next, ?Container $container): Closure
    {
        return function (Request $request, Response $response) use ($layer, $next, $container): Response {
            $callable = self::resolve($layer, $container);

            $result = $callable($request, $response, function () use ($request, $response, $next): Response {
                return $next($request, $response);
            });

            return self::normalize($result, $response);
        };
    }

    private static function resolve(callable|string $layer, ?Container $container): callable
    {
        if (is_string($layer) && class_exists($layer)) {
            $instance = $container !== null ? $container->get($layer) : new $layer();
            if (is_callable($instance)) {
                return $instance;
            }
            if (method_exists($instance, 'handle')) {
                return [$instance, 'handle'];
            }
        }
        return $layer;
    }
}
