<?php

declare(strict_types=1);

namespace Kabuto\Tests\Auth;

use Kabuto\Auth\Gate;
use Kabuto\Common\Controller;
use Kabuto\Common\Can;
use Kabuto\Common\Auth as AuthAttribute;
use Kabuto\Common\Get;
use Kabuto\Common\Permissions;
use Kabuto\Common\Post;
use Kabuto\Common\Roles;
use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Middleware\Authorize;
use Kabuto\Middleware\JwtAuth;
use Kabuto\Routing\Router;
use Kabuto\Routing\RouteScanner;
use Kabuto\Tests\TestCase;

#[Roles('admin')]
#[Controller('/admin')]
class AdminRoleController
{
    #[Get('/users')]
    public function users(): string
    {
        return 'user-list';
    }
}

#[Controller('/articles')]
class ArticleController
{
    #[Get('')]
    public function index(): string
    {
        return 'all-articles';
    }

    #[Permissions('articles:create')]
    #[Post('')]
    public function store(): string
    {
        return 'created';
    }

    #[Roles('editor', 'admin')]
    #[Get('/drafts')]
    public function drafts(): string
    {
        return 'drafts';
    }
}

final class IdorDocument
{
    public function __construct(public int $ownerId, public int $id) {}
}

final class IdorTestGuard
{
    public function __invoke(Request $request, Response $response, callable $next): Response
    {
        $id = (int) $request->param('id');
        $request->set('user', ['id' => 5]);
        $request->set(IdorDocument::class, new IdorDocument($id === 8 ? 6 : 5, $id));
        return $next($request, $response);
    }
}

#[Controller('/idor-documents')]
#[AuthAttribute(guard: IdorTestGuard::class)]
final class IdorDocumentController
{
    #[Can('view', IdorDocument::class, 'id')]
    #[Get('/:id')]
    public function show(): array { return ['ok' => true]; }
}

final class AuthorizationTest extends TestCase
{
    public function testAuthorizeMiddlewareAllowsMatchingRole(): void
    {
        $middleware = new Authorize(roles: ['admin', 'manager']);
        $req = new Request('GET', '/admin');
        $req->set('user', ['id' => 1, 'role' => 'admin']);
        $res = new Response();

        $called = false;
        $response = $middleware($req, $res, function (Request $req, Response $res) use (&$called): Response {
            $called = true;
            return $res;
        });

        self::assertTrue($called);
        self::assertSame(200, $response->getStatus());
    }

    public function testAuthorizeMiddlewareRejectsMismatchedRoleWith403(): void
    {
        $middleware = new Authorize(roles: ['admin']);
        $req = new Request('GET', '/admin');
        $req->set('user', ['id' => 2, 'role' => 'user']);
        $res = new Response();

        $called = false;
        $response = $middleware($req, $res, function (Request $req, Response $res) use (&$called): Response {
            $called = true;
            return $res;
        });

        self::assertFalse($called);
        self::assertSame(403, $response->getStatus());
        self::assertStringContains('Forbidden resource', (string) $response->getBody());
    }

    public function testAuthorizeMiddlewareRejectsUnauthenticatedWith401(): void
    {
        $middleware = new Authorize(roles: ['admin']);
        $req = new Request('GET', '/admin');
        // No user set on request
        $res = new Response();

        $called = false;
        $response = $middleware($req, $res, function (Request $req, Response $res) use (&$called): Response {
            $called = true;
            return $res;
        });

        self::assertFalse($called);
        self::assertSame(401, $response->getStatus());
    }

    public function testAuthorizeMiddlewareAllowsMatchingPermission(): void
    {
        $middleware = new Authorize(permissions: ['posts:delete']);
        $req = new Request('DELETE', '/posts/1');
        $req->set('user', ['id' => 3, 'permissions' => ['posts:delete', 'posts:view']]);
        $res = new Response();

        $called = false;
        $response = $middleware($req, $res, function (Request $req, Response $res) use (&$called): Response {
            $called = true;
            return $res;
        });

        self::assertTrue($called);
        self::assertSame(200, $response->getStatus());
    }

    public function testAuthorizeMiddlewareWildcardPermissionBypass(): void
    {
        $middleware = new Authorize(permissions: ['billing:refund']);
        $req = new Request('POST', '/refund');
        $req->set('user', ['id' => 4, 'permissions' => ['*']]);
        $res = new Response();

        $called = false;
        $response = $middleware($req, $res, function (Request $req, Response $res) use (&$called): Response {
            $called = true;
            return $res;
        });

        self::assertTrue($called);
        self::assertSame(200, $response->getStatus());
    }

    public function testRouteScannerWiresAuthAndAuthorizeForRolesAttribute(): void
    {
        $router = new Router();
        $scanner = new RouteScanner($router);
        $scanner->register(AdminRoleController::class);

        [$matched] = $router->resolve('GET', '/admin/users');
        self::assertTrue($matched !== null);

        $middlewares = $matched->middleware();
        self::assertContains(JwtAuth::class, $middlewares);

        $hasAuthorizeInstance = false;
        foreach ($middlewares as $mw) {
            if ($mw instanceof Authorize) {
                $hasAuthorizeInstance = true;
                break;
            }
        }
        self::assertTrue($hasAuthorizeInstance);
    }

    public function testRouteScannerWiresPermissionsAttribute(): void
    {
        $router = new Router();
        $scanner = new RouteScanner($router);
        $scanner->register(ArticleController::class);

        // Public route has neither
        [$matchedPublic] = $router->resolve('GET', '/articles');
        self::assertTrue($matchedPublic !== null);
        self::assertFalse(in_array(JwtAuth::class, $matchedPublic->middleware(), true));

        // Create route has JwtAuth + Authorize
        [$matchedCreate] = $router->resolve('POST', '/articles');
        self::assertTrue($matchedCreate !== null);
        self::assertContains(JwtAuth::class, $matchedCreate->middleware());
    }

    public function testRequestRoleAndPermissionHelpers(): void
    {
        $req = new Request('GET', '/');
        $req->set('user', [
            'id' => 5,
            'roles' => ['editor', 'moderator'],
            'permissions' => ['articles:edit', 'articles:publish'],
        ]);

        self::assertTrue($req->hasRole('editor'));
        self::assertTrue($req->hasRole(['admin', 'moderator']));
        self::assertFalse($req->hasRole('superadmin'));

        self::assertTrue($req->can('articles:edit'));
        self::assertFalse($req->can('articles:delete'));
    }

    public function testRoutePolicyAllowsOwnerAndRejectsOtherUser(): void
    {
        Gate::policy(IdorDocument::class, new class {
            public function view(mixed $user, mixed $model): bool
            {
                return is_array($user) && ($user['id'] ?? null) === $model->ownerId;
            }
        });
        $router = new Router();
        (new RouteScanner($router))->register(IdorDocumentController::class);
        $container = new \Kabuto\Container\Container();
        $kernel = new \Kabuto\Kernel($router, $container);

        $owner = $kernel->handle(new Request('GET', '/idor-documents/7'));
        $other = $kernel->handle(new Request('GET', '/idor-documents/8'));
        self::assertSame(200, $owner->getStatus(), $owner->getBody());
        self::assertSame(403, $other->getStatus());
    }
}
