<?php

declare(strict_types=1);

namespace Kabuto\Tests\Middleware;

use Kabuto\Exceptions\BadRequestException;
use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Middleware\JsonBody;
use Kabuto\Tests\TestCase;

final class JsonBodyTest extends TestCase
{
    private function run(JsonBody $middleware, Request $request): Request
    {
        $captured = null;
        $middleware($request, new Response(), static function (Request $req, Response $res) use (&$captured): Response {
            $captured = $req;
            return $res;
        });

        return $captured;
    }

    private function jsonRequest(string $raw): Request
    {
        return new Request('POST', '/x', [], [], ['content-type' => 'application/json']);
    }

    public function testParsesValidJson(): void
    {
        $middleware = new JsonBody(static fn () => '{"name":"Ada","n":1}');

        $request = $this->run($middleware, $this->jsonRequest(''));

        self::assertSame(['name' => 'Ada', 'n' => 1], $request->body());
    }

    public function testInvalidJsonThrowsBadRequest(): void
    {
        $middleware = new JsonBody(static fn () => '{broken');

        self::assertThrows(
            BadRequestException::class,
            fn () => $this->run($middleware, $this->jsonRequest('')),
        );
    }

    public function testScalarJsonThrowsBadRequest(): void
    {
        $middleware = new JsonBody(static fn () => '"just a string"');

        self::assertThrows(
            BadRequestException::class,
            fn () => $this->run($middleware, $this->jsonRequest('')),
        );
    }

    public function testEmptyBodyIsSkipped(): void
    {
        $middleware = new JsonBody(static fn () => '');

        $request = $this->run($middleware, $this->jsonRequest(''));

        self::assertSame([], $request->body());
    }

    public function testNonJsonContentTypeIsSkipped(): void
    {
        $called = false;
        $middleware = new JsonBody(static function () use (&$called): string {
            $called = true;
            return '{"a":1}';
        });

        $request = new Request('POST', '/x', [], ['a' => 'b'], ['content-type' => 'application/x-www-form-urlencoded']);
        $result = $this->run($middleware, $request);

        self::assertFalse($called);
        self::assertSame(['a' => 'b'], $result->body());
    }

    public function testBadRequestStatusIs400(): void
    {
        $exception = new BadRequestException();

        self::assertSame(400, $exception->getStatus());
    }
}
