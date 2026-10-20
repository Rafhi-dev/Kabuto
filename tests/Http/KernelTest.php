<?php

declare(strict_types=1);

namespace Kabuto\Tests\Http;

use Kabuto\Container\Container;
use Kabuto\Http\Request;
use Kabuto\Kernel;
use Kabuto\Routing\Router;
use Kabuto\Tests\TestCase;

final class KernelTest extends TestCase
{
    private function kernel(Router $router): Kernel
    {
        return new Kernel($router, new Container());
    }

    public function testDispatchesToHandler(): void
    {
        $router = new Router();
        $router->get('/hello/:name', static fn (Request $req) => ['hello' => $req->param('name')]);

        $response = $this->kernel($router)->handle(new Request('GET', '/hello/world'));

        self::assertSame(200, $response->getStatus());
        self::assertSame('{"hello":"world"}', $response->getBody());
    }

    public function testNotFoundReturns404(): void
    {
        $router = new Router();
        $router->get('/users', static fn () => []);

        $response = $this->kernel($router)->handle(new Request('GET', '/missing'));

        self::assertSame(404, $response->getStatus());
        $data = json_decode($response->getBody(), true);
        self::assertSame('NOT_FOUND', $data['error']['code']);
        self::assertArrayHasKey('request_id', $data['error']);
    }

    public function testMethodMismatchReturns405(): void
    {
        $router = new Router();
        $router->post('/users', static fn () => []);

        $response = $this->kernel($router)->handle(new Request('DELETE', '/users'));

        self::assertSame(405, $response->getStatus());
    }

    public function testExceptionBecomes500(): void
    {
        $router = new Router();
        $router->get('/boom', static function (): void {
            throw new \RuntimeException('kaput');
        });

        $response = $this->kernel($router)->handle(new Request('GET', '/boom'));

        self::assertSame(500, $response->getStatus());
        $data = json_decode($response->getBody(), true);
        self::assertTrue(is_array($data));
        self::assertFalse($data['success']);
        self::assertSame('INTERNAL_ERROR', $data['error']['code']);
        self::assertSame('An internal server error occurred.', $data['error']['message']);
        self::assertArrayHasKey('request_id', $data['error']);
    }

    public function testControllerIsResolvedFromContainer(): void
    {
        $router = new Router();
        $router->get('/who', KernelGreeter::class . '@greet');

        $response = $this->kernel($router)->handle(new Request('GET', '/who'));

        self::assertSame('{"greeting":"hi from service"}', $response->getBody());
    }

    public function testGlobalMiddlewareRuns(): void
    {
        $router = new Router();
        $router->get('/x', static fn () => ['ok' => true]);

        $kernel = new Kernel($router, new Container(), [
            static function (Request $req, \Kabuto\Http\Response $res, callable $next): \Kabuto\Http\Response {
                $res->header('X-Test', 'yes');
                return $next($req, $res);
            },
        ]);

        $response = $kernel->handle(new Request('GET', '/x'));

        self::assertSame('yes', $response->headers()['X-Test']);
    }

    public function testRequestLoggerWritesCompletionEvent(): void
    {
        $lines = [];
        $logger = new \Kabuto\Support\Logger(writer: static function (string $line) use (&$lines): void { $lines[] = $line; });
        $router = new Router();
        $router->get('/logged', static fn () => ['ok' => true]);
        $kernel = new Kernel($router, new Container(), [
            \Kabuto\Middleware\RequestId::class,
            new \Kabuto\Middleware\RequestLogger($logger),
        ]);
        $response = $kernel->handle(new Request('GET', '/logged'));
        $record = json_decode($lines[0], true);

        self::assertSame(200, $response->getStatus());
        self::assertSame('request.completed', $record['message']);
        self::assertSame('/logged', $record['context']['path']);
        self::assertArrayHasKey('duration_ms', $record['context']);
    }
}

final class KernelGreeterService
{
    public function greet(): string
    {
        return 'hi from service';
    }
}

final class KernelGreeter
{
    public function __construct(private readonly KernelGreeterService $service)
    {
    }

    public function greet(): array
    {
        return ['greeting' => $this->service->greet()];
    }
}
