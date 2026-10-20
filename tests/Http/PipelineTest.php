<?php

declare(strict_types=1);

namespace Kabuto\Tests\Http;

use Kabuto\Http\Pipeline;
use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Tests\TestCase;

final class PipelineTest extends TestCase
{
    private function request(): Request
    {
        return new Request('GET', '/');
    }

    public function testHandlerResponseIsUsedAsIs(): void
    {
        $response = Pipeline::run([], static fn () => (new Response())->status(201), $this->request(), new Response());

        self::assertSame(201, $response->getStatus());
    }

    public function testArrayIsCoercedToJson(): void
    {
        $response = Pipeline::run([], static fn () => ['ok' => true], $this->request(), new Response());

        self::assertSame('{"ok":true}', $response->getBody());
        self::assertSame('application/json; charset=utf-8', $response->headers()['Content-Type']);
    }

    public function testObjectWithToArrayIsCoercedToJson(): void
    {
        $response = Pipeline::run([], static fn () => new Arrayable(['a' => 1]), $this->request(), new Response());

        self::assertSame('{"a":1}', $response->getBody());
    }

    public function testNullKeepsExistingResponse(): void
    {
        $response = Pipeline::run([], static fn () => null, $this->request(), (new Response())->status(204));

        self::assertSame(204, $response->getStatus());
        self::assertSame('', $response->getBody());
    }

    public function testStringIsSentAsBody(): void
    {
        $response = Pipeline::run([], static fn () => 'hello', $this->request(), new Response());

        self::assertSame('hello', $response->getBody());
    }

    public function testMiddlewareRunsInOnionOrder(): void
    {
        $order = [];

        $first = static function (Request $req, Response $res, callable $next) use (&$order): Response {
            $order[] = 'first:in';
            $response = $next($req, $res);
            $order[] = 'first:out';
            return $response;
        };
        $second = static function (Request $req, Response $res, callable $next) use (&$order): Response {
            $order[] = 'second:in';
            $response = $next($req, $res);
            $order[] = 'second:out';
            return $response;
        };

        Pipeline::run([$first, $second], static fn () => new Response(), $this->request(), new Response());

        self::assertSame(['first:in', 'second:in', 'second:out', 'first:out'], $order);
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $reached = false;
        $guard = static fn (Request $req, Response $res, callable $next): Response => $res->status(403);

        $response = Pipeline::run(
            [$guard],
            static function () use (&$reached): Response {
                $reached = true;
                return new Response();
            },
            $this->request(),
            new Response(),
        );

        self::assertFalse($reached);
        self::assertSame(403, $response->getStatus());
    }
}

final class Arrayable
{
    public function __construct(private readonly array $data)
    {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
