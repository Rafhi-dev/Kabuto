<?php

declare(strict_types=1);

namespace Kabuto\Tests\Runtime;

use Kabuto\Http\Response;
use Kabuto\Runtime\Drivers\OpenSwooleDriver;
use Kabuto\Runtime\WorkerServer;
use Kabuto\Tests\TestCase;
use RuntimeException;

final class OpenSwooleDriverTest extends TestCase
{
    public function testDriverProperties(): void
    {
        $driver = new OpenSwooleDriver();
        self::assertSame('OpenSwoole (C Coroutine Engine)', $driver->name());
        self::assertTrue(is_bool(OpenSwooleDriver::isSupported()));
    }

    public function testResolveExplicitOpenSwooleWithoutExtensionThrowsException(): void
    {
        if (!OpenSwooleDriver::isSupported()) {
            self::assertThrows(RuntimeException::class, function (): void {
                WorkerServer::resolveDriver('openswoole');
            });
        } else {
            $driver = WorkerServer::resolveDriver('openswoole');
            self::assertInstanceOf(OpenSwooleDriver::class, $driver);
        }
    }

    public function testAutoDriverDoesNotSelectOpenSwooleByDefault(): void
    {
        $driver = WorkerServer::resolveDriver('auto');
        self::assertFalse($driver instanceof OpenSwooleDriver);
    }

    public function testConvertRequestFromSwooleStructure(): void
    {
        $mockSwoole = new class {
            public array $server = [
                'request_method' => 'POST',
                'request_uri' => '/api/items?filter=active',
                'remote_addr' => '10.0.0.1',
            ];
            public array $header = [
                'host' => 'localhost:8000',
                'content-type' => 'application/json',
                'x-api-key' => 'secret123',
            ];
            public array $get = [
                'filter' => 'active',
            ];
            public array $post = [];
            public array $cookie = [
                'sess' => 'token999',
            ];
            public function rawContent(): string
            {
                return '{"name":"Item A"}';
            }
        };

        $req = OpenSwooleDriver::convertRequest($mockSwoole);

        self::assertSame('POST', $req->method());
        self::assertSame('/api/items', $req->path());
        self::assertSame('active', $req->query('filter'));
        self::assertSame('secret123', $req->header('x-api-key'));
        self::assertSame('token999', $req->cookie('sess'));
        self::assertSame('{"name":"Item A"}', $req->rawBody());
    }

    public function testEmitResponseToSwooleResponse(): void
    {
        $mockRes = new class {
            public int $statusCode = 0;
            public array $headers = [];
            public array $cookies = [];
            public string $body = '';

            public function status(int $code): void
            {
                $this->statusCode = $code;
            }

            public function header(string $key, string $val): void
            {
                $this->headers[$key] = $val;
            }

            public function cookie(string $name, string $val, int $expires, string $path, string $domain, bool $sec, bool $httpOnly, string $sameSite): void
            {
                $this->cookies[] = compact('name', 'val', 'expires', 'path');
            }

            public function end(string $content): void
            {
                $this->body = $content;
            }
        };

        $response = new Response();
        $response->status(201);
        $response->header('X-Custom', 'Value1');
        $response->cookie('theme', 'dark', ['expires' => 3600]);
        $response->json(['created' => true]);

        OpenSwooleDriver::emitResponse($response, $mockRes);

        self::assertSame(201, $mockRes->statusCode);
        self::assertSame('Value1', $mockRes->headers['X-Custom']);
        self::assertSame('application/json; charset=utf-8', $mockRes->headers['Content-Type']);
        self::assertSame('{"created":true}', $mockRes->body);
        self::assertCount(1, $mockRes->cookies);
        self::assertSame('theme', $mockRes->cookies[0]['name']);
        self::assertSame('dark', $mockRes->cookies[0]['val']);
    }
}
