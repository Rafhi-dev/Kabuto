<?php

declare(strict_types=1);

namespace Kabuto\Tests\Routing;

use Kabuto\Common\Controller;
use Kabuto\Common\Get;
use Kabuto\Common\HttpCode;
use Kabuto\Common\HttpStatus;
use Kabuto\Common\Param;
use Kabuto\Common\Post;
use Kabuto\Container\Container;
use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Routing\Router;
use Kabuto\Routing\RouteScanner;
use Kabuto\Tests\TestCase;

final class RouteScannerTest extends TestCase
{
    public function testRegistersAnnotatedMethods(): void
    {
        $router = new Router();
        $scanner = new RouteScanner($router);
        $scanner->register(AnnotatedController::class);

        [$index] = $router->resolve('GET', '/items');
        [$show] = $router->resolve('GET', '/items/9');
        [$store] = $router->resolve('POST', '/items');

        self::assertSame([AnnotatedController::class, 'index'], $index?->handler());
        self::assertSame([AnnotatedController::class, 'show'], $show?->handler());
        self::assertSame([AnnotatedController::class, 'store'], $store?->handler());
    }

    public function testEmptyMethodPathUsesPrefixWithoutTrailingSlash(): void
    {
        $router = new Router();
        $scanner = new RouteScanner($router);
        $scanner->register(AnnotatedController::class);

        [$route] = $router->resolve('GET', '/items');

        self::assertTrue($route !== null);
        self::assertSame('/items', $route->pattern());
    }

    public function testNonAnnotatedClassIsIgnored(): void
    {
        $router = new Router();
        $scanner = new RouteScanner($router);

        self::assertFalse($scanner->isController(PlainController::class));

        $scanner->register(PlainController::class);

        self::assertCount(0, $router->routes());
    }

    public function testIsController(): void
    {
        $scanner = new RouteScanner(new Router());

        self::assertTrue($scanner->isController(AnnotatedController::class));
    }

    public function testClassMiddlewareIsMerged(): void
    {
        $router = new Router();
        $scanner = new RouteScanner($router);
        $scanner->register(GuardedController::class);

        [$route] = $router->resolve('GET', '/admin');

        self::assertSame(['auth', 'throttle'], $route?->middleware());
    }

    public function testParamDecoratorInjectsRouteValue(): void
    {
        $resolver = new \Kabuto\Http\HandlerResolver(new Container());
        $handler = $resolver->resolve([new AnnotatedController(), 'show']);

        $request = new Request('GET', '/items/9');
        $request->setParams(['id' => '9']);

        self::assertSame([], $handler($request, new Response()));
    }

    public function testMissingRequiredParamFailsFast(): void
    {
        $resolver = new \Kabuto\Http\HandlerResolver(new Container());
        $handler = $resolver->resolve([new AnnotatedController(), 'show']);

        self::assertThrows(
            \Kabuto\Container\ContainerException::class,
            static fn () => $handler(new Request('GET', '/items'), new Response()),
        );
    }

    public function testHttpCodeSetsSuccessStatus(): void
    {
        $resolver = new \Kabuto\Http\HandlerResolver(new Container());
        $handler = $resolver->resolve([new AnnotatedController(), 'store']);

        $response = new Response();
        $handler(new Request('POST', '/items'), $response);

        self::assertSame(HttpStatus::CREATED, $response->getStatus());
    }
}

#[Controller('items')]
final class AnnotatedController
{
    #[Get()]
    public function index(): array
    {
        return [];
    }

    #[Get(':id')]
    public function show(#[Param('id')] string $id): array
    {
        return [];
    }

    #[Post()]
    #[HttpCode(HttpStatus::CREATED)]
    public function store(): array
    {
        return [];
    }

    public function helper(): void
    {
    }
}

final class PlainController
{
    public function index(Request $request, Response $response): Response
    {
        return $response;
    }
}

#[Controller('admin', ['auth'])]
final class GuardedController
{
    #[Get('', ['throttle'])]
    public function index(): array
    {
        return [];
    }
}
