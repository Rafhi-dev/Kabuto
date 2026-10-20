<?php

declare(strict_types=1);

namespace Kabuto\Tests\Http;

use Kabuto\Common\Controller;
use Kabuto\Common\Get;
use Kabuto\Common\UseExceptionFilters;
use Kabuto\Container\Container;
use Kabuto\Exceptions\ExceptionFilter;
use Kabuto\Exceptions\ExceptionContext;
use Kabuto\Exceptions\ExceptionTypeFilter;
use Kabuto\Exceptions\ContextualExceptionFilter;
use Kabuto\Exceptions\NotFoundException;
use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Kernel;
use Kabuto\Routing\RouteScanner;
use Kabuto\Routing\Router;
use Kabuto\Tests\TestCase;
use Throwable;

final class ControllerNotFoundFilter implements ExceptionFilter
{
    public function catch(Throwable $exception, Request $request): ?Response
    {
        return $exception instanceof NotFoundException
            ? (new Response())->status(404)->send('controller-filter')
            : null;
    }
}

final class MethodRuntimeFilter implements ExceptionFilter
{
    public function catch(Throwable $exception, Request $request): ?Response
    {
        return $exception instanceof \RuntimeException
            ? (new Response())->status(409)->send('method-filter')
            : null;
    }
}

final class TypedExceptionFilter implements ExceptionFilter, ExceptionTypeFilter
{
    public function caughtExceptions(): array { return [\InvalidArgumentException::class]; }

    public function catch(Throwable $exception, Request $request): ?Response
    {
        return (new Response())->status(422)->send('typed-filter');
    }
}

final class ContextExceptionFilter implements ContextualExceptionFilter
{
    public function catchWithContext(Throwable $exception, ExceptionContext $context): ?Response
    {
        if (!$exception instanceof \DomainException) return null;
        return (new Response())->status(409)->header('X-Route', $context->route?->pattern() ?? 'none')->send($context->request->path());
    }
}

#[Controller('exception-filter-test')]
#[UseExceptionFilters([ControllerNotFoundFilter::class])]
final class ExceptionFilterTestController
{
    #[Get('not-found')]
    public function notFound(): never
    {
        throw new NotFoundException('missing');
    }

    #[Get('method')]
    #[UseExceptionFilters([MethodRuntimeFilter::class])]
    public function method(): never
    {
        throw new \RuntimeException('conflict');
    }

    #[Get('fallback')]
    public function fallback(): never
    {
        throw new \InvalidArgumentException('fall through');
    }

    #[Get('typed')]
    public function typed(): never { throw new \InvalidArgumentException('invalid'); }

    #[Get('context')]
    public function context(): never { throw new \DomainException('domain'); }
}

final class ExceptionFilterTest extends TestCase
{
    private function kernel(): Kernel
    {
        $router = new Router();
        (new RouteScanner($router))->register(ExceptionFilterTestController::class);
        return new Kernel($router, new Container());
    }

    public function testControllerFilterHandlesException(): void
    {
        $response = $this->kernel()->handle(new Request('GET', '/exception-filter-test/not-found'));
        self::assertSame(404, $response->getStatus());
        self::assertSame('controller-filter', $response->getBody());
    }

    public function testMethodFilterHandlesExceptionAndOverridesClassFilter(): void
    {
        $response = $this->kernel()->handle(new Request('GET', '/exception-filter-test/method'));
        self::assertSame(409, $response->getStatus());
        self::assertSame('method-filter', $response->getBody());
    }

    public function testUnhandledExceptionFallsThroughToGlobalHandler(): void
    {
        $kernel = $this->kernel()->onError(static fn (Throwable $exception): Response => (new Response())->status(418)->send('global-filter'));
        $response = $kernel->handle(new Request('GET', '/exception-filter-test/fallback'));
        self::assertSame(418, $response->getStatus());
        self::assertSame('global-filter', $response->getBody());
    }

    public function testGlobalFilterHandlesMatchingExceptionBeforeOnError(): void
    {
        $kernel = $this->kernel()
            ->useGlobalFilters(TypedExceptionFilter::class)
            ->onError(static fn (Throwable $exception): Response => (new Response())->status(500)->send('global'));
        $response = $kernel->handle(new Request('GET', '/exception-filter-test/typed'));
        self::assertSame(422, $response->getStatus());
        self::assertSame('typed-filter', $response->getBody());
    }

    public function testGlobalTypeFilterSkipsOtherExceptionsAndContextFilterGetsRoute(): void
    {
        $kernel = $this->kernel()->useGlobalFilters(TypedExceptionFilter::class, ContextExceptionFilter::class);
        $response = $kernel->handle(new Request('GET', '/exception-filter-test/context'));
        self::assertSame(409, $response->getStatus());
        self::assertSame('/exception-filter-test/context', $response->getBody());
        self::assertSame('/exception-filter-test/context', $response->headers()['X-Route']);
    }
}
