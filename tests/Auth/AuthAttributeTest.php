<?php

declare(strict_types=1);

namespace Kabuto\Tests\Auth;

use Kabuto\Common\Auth;
use Kabuto\Common\Controller;
use Kabuto\Common\Get;
use Kabuto\Common\User;
use Kabuto\Container\Container;
use Kabuto\Http\HandlerResolver;
use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Middleware\JwtAuth;
use Kabuto\Routing\Router;
use Kabuto\Routing\RouteScanner;
use Kabuto\Tests\TestCase;

#[Auth]
#[Controller('/admin')]
class ProtectedAdminController
{
    #[Get('/dashboard')]
    public function dashboard(): string
    {
        return 'dashboard';
    }
}

#[Controller('/items')]
class MixedItemController
{
    #[Get('')]
    public function list(): string
    {
        return 'list';
    }

    #[Auth]
    #[Get('/secret')]
    public function secret(): string
    {
        return 'secret';
    }
}

class UserInjectionController
{
    public function getFullUser(#[User] array $user): array
    {
        return $user;
    }

    public function getUserProps(#[User('id')] int $id, #[User('email')] string $email): array
    {
        return ['id' => $id, 'email' => $email];
    }

    public function getUserDefault(#[User('nonexistent', 'guest')] string $role): string
    {
        return $role;
    }
}

final class AuthAttributeTest extends TestCase
{
    public function testRouteScannerAppliesJwtAuthWhenClassHasAuth(): void
    {
        $router = new Router();
        $scanner = new RouteScanner($router);

        $scanner->register(ProtectedAdminController::class);

        [$matched] = $router->resolve('GET', '/admin/dashboard');
        self::assertTrue($matched !== null);
        self::assertContains(JwtAuth::class, $matched->middleware());
    }

    public function testRouteScannerAppliesJwtAuthToSpecificMethod(): void
    {
        $router = new Router();
        $scanner = new RouteScanner($router);

        $scanner->register(MixedItemController::class);

        // Public route has no JwtAuth
        [$matchedPublic] = $router->resolve('GET', '/items');
        self::assertTrue($matchedPublic !== null);
        self::assertFalse(in_array(JwtAuth::class, $matchedPublic->middleware(), true));

        // Protected route has JwtAuth
        [$matchedSecret] = $router->resolve('GET', '/items/secret');
        self::assertTrue($matchedSecret !== null);
        self::assertContains(JwtAuth::class, $matchedSecret->middleware());
    }

    public function testHandlerResolverInjectsUserPayload(): void
    {
        $container = new Container();
        $container->instance(UserInjectionController::class, new UserInjectionController());
        $resolver = new HandlerResolver($container);

        $handler = $resolver->resolve(UserInjectionController::class . '@getFullUser');

        $req = new Request('GET', '/');
        $req->set('user', ['id' => 7, 'name' => 'Alice']);
        $res = new Response();

        $result = $handler($req, $res);
        self::assertSame(['id' => 7, 'name' => 'Alice'], $result);
    }

    public function testHandlerResolverInjectsUserPropertiesWithCast(): void
    {
        $container = new Container();
        $container->instance(UserInjectionController::class, new UserInjectionController());
        $resolver = new HandlerResolver($container);

        $handler = $resolver->resolve(UserInjectionController::class . '@getUserProps');

        $req = new Request('GET', '/');
        $req->set('user', ['id' => '99', 'email' => 'user@kabuto.dev']);
        $res = new Response();

        $result = $handler($req, $res);
        self::assertSame(99, $result['id']); // Notice string '99' auto-cast to int 99
        self::assertSame('user@kabuto.dev', $result['email']);
    }

    public function testHandlerResolverInjectsDefaultWhenPropertyMissing(): void
    {
        $container = new Container();
        $container->instance(UserInjectionController::class, new UserInjectionController());
        $resolver = new HandlerResolver($container);

        $handler = $resolver->resolve(UserInjectionController::class . '@getUserDefault');

        $req = new Request('GET', '/');
        $req->set('user', ['id' => 1]);
        $res = new Response();

        $result = $handler($req, $res);
        self::assertSame('guest', $result);
    }
}
