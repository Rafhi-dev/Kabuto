<?php

declare(strict_types=1);

namespace Kabuto\Tests\Routing;

use Kabuto\Common\Controller;
use Kabuto\Common\Get;
use Kabuto\Common\Injectable;
use Kabuto\Common\Module;
use Kabuto\Container\Container;
use Kabuto\Routing\ModuleScanner;
use Kabuto\Routing\Router;
use Kabuto\Tests\TestCase;

final class ModuleScannerTest extends TestCase
{
    public function testRegistersControllersAndProviders(): void
    {
        $router = new Router();
        $scanner = new ModuleScanner($router, new Container());
        $scanner->register(CatalogModule::class);

        [$route] = $router->resolve('GET', '/catalog');

        self::assertTrue($route !== null);
        self::assertSame([CatalogController::class, 'list'], $route->handler());
        self::assertSame([CatalogModule::class], $scanner->registered());
    }

    public function testImportsAreRegisteredRecursively(): void
    {
        $router = new Router();
        $scanner = new ModuleScanner($router, new Container());
        $scanner->register(ShopModule::class);

        [$catalog] = $router->resolve('GET', '/catalog');
        [$cart] = $router->resolve('GET', '/cart');

        self::assertTrue($catalog !== null);
        self::assertTrue($cart !== null);
        self::assertSame([CatalogModule::class, ShopModule::class], $scanner->registered());
    }

    public function testProvidersResolveFromContainer(): void
    {
        $container = new Container();
        $scanner = new ModuleScanner(new Router(), $container);
        $scanner->register(CatalogModule::class);

        $controller = $container->get(CatalogController::class);

        self::assertSame('catalog!', $controller->list());
    }

    public function testCircularImportFailsFast(): void
    {
        $scanner = new ModuleScanner(new Router(), new Container());

        self::assertThrows(
            \LogicException::class,
            static fn () => $scanner->register(CycleAModule::class),
        );
    }

    public function testNonModuleFailsFast(): void
    {
        $scanner = new ModuleScanner(new Router(), new Container());

        self::assertThrows(
            \LogicException::class,
            static fn () => $scanner->register(CatalogController::class),
        );
    }

    public function testControllerWithoutAttributeFailsFast(): void
    {
        $scanner = new ModuleScanner(new Router(), new Container());

        self::assertThrows(
            \LogicException::class,
            static fn () => $scanner->register(BrokenModule::class),
        );
    }
}

#[Injectable()]
final class CatalogService
{
    public function name(): string
    {
        return 'catalog!';
    }
}

#[Controller('catalog')]
final class CatalogController
{
    public function __construct(private readonly CatalogService $service)
    {
    }

    #[Get()]
    public function list(): string
    {
        return $this->service->name();
    }
}

#[Module(controllers: [CatalogController::class], providers: [CatalogService::class])]
final class CatalogModule
{
}

#[Controller('cart')]
final class CartController
{
    #[Get()]
    public function list(): array
    {
        return [];
    }
}

#[Module(imports: [CatalogModule::class], controllers: [CartController::class])]
final class ShopModule
{
}

#[Module(imports: [CycleBModule::class])]
final class CycleAModule
{
}

#[Module(imports: [CycleAModule::class])]
final class CycleBModule
{
}

#[Module(controllers: [CatalogService::class])]
final class BrokenModule
{
}
