<?php

declare(strict_types=1);

namespace Kabuto\Tests\Routing;

use Kabuto\Routing\Router;
use Kabuto\Tests\TestCase;

final class RouterTest extends TestCase
{
    public function testResolvesRegisteredRoute(): void
    {
        $router = new Router();
        $router->get('/users/:id', 'show');

        [$route, $params, $matched] = $router->resolve('GET', '/users/5');

        self::assertTrue($matched);
        self::assertSame('show', $route?->handler());
        self::assertSame(['id' => '5'], $params);
    }

    public function testUnmatchedPathReturnsNull(): void
    {
        $router = new Router();
        $router->get('/users', 'index');

        [$route, $params, $matched] = $router->resolve('GET', '/missing');

        self::assertNull($route);
        self::assertSame([], $params);
        self::assertFalse($matched);
    }

    public function testMethodMismatchSetsPathMatched(): void
    {
        $router = new Router();
        $router->post('/users', 'store');

        [$route, $params, $matched] = $router->resolve('DELETE', '/users');

        self::assertNull($route);
        self::assertTrue($matched);
    }

    public function testAnyMatchesAllMethods(): void
    {
        $router = new Router();
        $router->any('/hook', 'hook');

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            [$route] = $router->resolve($method, '/hook');
            self::assertSame('hook', $route?->handler());
        }
    }

    public function testFirstRegisteredRouteWins(): void
    {
        $router = new Router();
        $router->get('/users', 'first');
        $router->get('/users', 'second');

        [$route] = $router->resolve('GET', '/users');

        self::assertSame('first', $route?->handler());
    }

    public function testGroupAppliesPrefixAndMiddleware(): void
    {
        $router = new Router();
        $router->group('/api', static function (Router $r): void {
            $r->get('/users', 'index');
        }, ['auth']);

        [$route] = $router->resolve('GET', '/api/users');

        self::assertSame('index', $route?->handler());
        self::assertSame(['auth'], $route?->middleware());
    }

    public function testNestedGroupsAccumulate(): void
    {
        $router = new Router();
        $router->group('/api', static function (Router $r): void {
            $r->group('/v1', static function (Router $inner): void {
                $inner->get('/users', 'index', ['throttle']);
            }, ['auth']);
        });

        [$route] = $router->resolve('GET', '/api/v1/users');

        self::assertSame('index', $route?->handler());
        self::assertSame(['auth', 'throttle'], $route?->middleware());
    }

    public function testRoutesAccessor(): void
    {
        $router = new Router();
        $router->get('/a', 'a');
        $router->post('/b', 'b');

        self::assertCount(2, $router->routes());
    }
}
