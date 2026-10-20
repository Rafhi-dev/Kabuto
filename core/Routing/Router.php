<?php

declare(strict_types=1);

namespace Kabuto\Routing;

final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    private string $prefix = '';

    /** @var list<callable|string> */
    private array $middleware = [];

    public function get(string $pattern, mixed $handler, array $middleware = [], array $metadata = []): Route
    {
        return $this->add('GET', $pattern, $handler, $middleware, $metadata);
    }

    public function post(string $pattern, mixed $handler, array $middleware = []): Route
    {
        return $this->add('POST', $pattern, $handler, $middleware);
    }

    public function put(string $pattern, mixed $handler, array $middleware = []): Route
    {
        return $this->add('PUT', $pattern, $handler, $middleware);
    }

    public function patch(string $pattern, mixed $handler, array $middleware = []): Route
    {
        return $this->add('PATCH', $pattern, $handler, $middleware);
    }

    public function delete(string $pattern, mixed $handler, array $middleware = []): Route
    {
        return $this->add('DELETE', $pattern, $handler, $middleware);
    }

    public function any(string $pattern, mixed $handler, array $middleware = []): Route
    {
        return $this->add('*', $pattern, $handler, $middleware);
    }

    /**
     * @param list<callable|string> $middleware
     */
    public function add(string $method, string $pattern, mixed $handler, array $middleware = [], array $metadata = []): Route
    {
        $route = new Route(
            strtoupper($method),
            $this->prefix . $pattern,
            $handler,
            array_merge($this->middleware, $middleware),
            $metadata,
        );
        $this->routes[] = $route;
        return $route;
    }

    /**
     * Register a group of routes sharing a prefix and middleware.
     *
     * @param list<callable|string> $middleware
     */
    public function group(string $prefix, callable $callback, array $middleware = []): void
    {
        $previousPrefix = $this->prefix;
        $previousMiddleware = $this->middleware;

        $this->prefix = $previousPrefix . $prefix;
        $this->middleware = array_merge($previousMiddleware, $middleware);

        $callback($this);

        $this->prefix = $previousPrefix;
        $this->middleware = $previousMiddleware;
    }

    /**
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * Check whether an identical method + pattern is already registered.
     * Used to keep automatic discovery idempotent.
     */
    public function has(string $method, string $pattern): bool
    {
        $method = strtoupper($method);

        foreach ($this->routes as $route) {
            if ($route->method() === $method && $route->pattern() === $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0:Route|null,1:array<string,string>,2:bool} [route, params, pathMatched]
     */
    public function resolve(string $method, string $path): array
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            $params = $route->matches($path);
            if ($params === null) {
                continue;
            }
            $pathMatched = true;
            if ($route->method() === '*' || $route->method() === $method) {
                return [$route, $params, true];
            }
        }

        return [null, [], $pathMatched];
    }
}
