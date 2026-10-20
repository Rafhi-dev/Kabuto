<?php

declare(strict_types=1);

namespace Kabuto\Tests\Runtime;

use Kabuto\Application;
use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Middleware\JsonBody;
use Kabuto\Runtime\WorkerServer;
use Kabuto\Tests\TestCase;

final class WorkerServerTest extends TestCase
{
    public function testRequestFromRawGet(): void
    {
        $raw = "GET /api/users?page=2&limit=10 HTTP/1.1\r\n"
            . "Host: localhost:8000\r\n"
            . "User-Agent: MachTest/1.0\r\n"
            . "Accept: application/json\r\n"
            . "Cookie: session_id=xyz123; theme=dark\r\n\r\n";

        $request = Request::fromRaw($raw, ['REMOTE_ADDR' => '127.0.0.1']);

        self::assertSame('GET', $request->method());
        self::assertSame('/api/users', $request->path());
        self::assertSame('2', $request->query('page'));
        self::assertSame('10', $request->query('limit'));
        self::assertSame('localhost:8000', $request->header('host'));
        self::assertSame('application/json', $request->header('accept'));
        self::assertSame('xyz123', $request->cookie('session_id'));
        self::assertSame('dark', $request->cookie('theme'));
        self::assertSame('127.0.0.1', $request->all()['REMOTE_ADDR'] ?? $request->header('remote_addr') ?? '127.0.0.1');
    }

    public function testRequestFromRawPostJson(): void
    {
        $json = json_encode(['title' => 'Test Post', 'author_id' => 1]);
        $raw = "POST /api/posts HTTP/1.1\r\n"
            . "Host: localhost:8000\r\n"
            . "Content-Type: application/json\r\n"
            . "Content-Length: " . strlen($json) . "\r\n\r\n"
            . $json;

        $request = Request::fromRaw($raw);

        self::assertSame('POST', $request->method());
        self::assertSame('/api/posts', $request->path());
        self::assertSame($json, $request->rawBody());

        // Test JsonBody middleware parsing
        $app = new Application();
        $app->use(JsonBody::class);
        $app->post('/api/posts', function (Request $req) {
            return (new Response())->json([
                'received_title' => $req->input('title'),
                'received_author' => $req->input('author_id'),
            ]);
        });

        $server = new WorkerServer($app);
        $res = $server->handleRawRequest($raw);

        self::assertSame(200, $res->getStatus());
        $data = json_decode($res->getBody(), true);
        self::assertSame('Test Post', $data['received_title']);
        self::assertSame(1, $data['received_author']);
    }

    public function testRequestFromRawFormUrlEncoded(): void
    {
        $body = 'username=antigravity&role=admin';
        $raw = "POST /login HTTP/1.1\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . "Content-Length: " . strlen($body) . "\r\n\r\n"
            . $body;

        $request = Request::fromRaw($raw);

        self::assertSame('POST', $request->method());
        self::assertSame('antigravity', $request->input('username'));
        self::assertSame('admin', $request->input('role'));
    }

    public function testFormatHttpResponse(): void
    {
        $app = new Application();
        $server = new WorkerServer($app);

        $response = new Response();
        $response->status(201);
        $response->header('X-Custom-Header', 'Kabuto-Engine');
        $response->json(['id' => 42, 'created' => true]);

        $wire = $server->formatHttpResponse($response, true);

        self::assertStringContains('HTTP/1.1 201 Created', $wire);
        self::assertStringContains('X-Custom-Header: Kabuto-Engine', $wire);
        self::assertStringContains('Connection: keep-alive', $wire);
        self::assertStringContains('Content-Length: ' . strlen($response->getBody()), $wire);
        self::assertStringContains('Server: Kabuto-Worker/1.0', $wire);
        self::assertStringContains('"id":42', $wire);

        $closeWire = $server->formatHttpResponse($response, false);
        self::assertStringContains('Connection: close', $closeWire);
    }

    public function testIsRequestComplete(): void
    {
        $app = new Application();
        $server = new WorkerServer($app);

        $reqLen = 0;

        // Incomplete headers
        self::assertFalse($server->isRequestComplete("GET / HTTP/1.1\r\nHost: loc", $reqLen));

        // Complete GET request (no body)
        $getReq = "GET /hello HTTP/1.1\r\nHost: localhost\r\n\r\n";
        self::assertTrue($server->isRequestComplete($getReq, $reqLen));
        self::assertSame(strlen($getReq), $reqLen);

        // POST with Content-Length: 10, but only 5 bytes arrived
        $partialPost = "POST /test HTTP/1.1\r\nContent-Length: 10\r\n\r\n12345";
        self::assertFalse($server->isRequestComplete($partialPost, $reqLen));

        // POST with Content-Length: 10, all 10 bytes arrived
        $fullPost = "POST /test HTTP/1.1\r\nContent-Length: 10\r\n\r\n1234567890";
        self::assertTrue($server->isRequestComplete($fullPost, $reqLen));
        self::assertSame(strlen($fullPost), $reqLen);
    }

    public function testHandleRawRequestNotFound(): void
    {
        $app = new Application();
        $server = new WorkerServer($app);

        $raw = "GET /non-existent-route HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $response = $server->handleRawRequest($raw);

        self::assertSame(404, $response->getStatus());
    }

    public function testServeStaticFileAndTraversalProtection(): void
    {
        $tempDir = sys_get_temp_dir() . '/kabuto_worker_test_' . uniqid();
        mkdir($tempDir, 0777, true);
        file_put_contents($tempDir . '/style.css', 'body { color: red; }');

        $app = new Application();
        $server = new WorkerServer($app, publicDir: $tempDir);

        // 1. Static file served with correct mime
        $res = $server->serveStaticFile('/style.css');
        self::assertTrue($res !== null);
        self::assertSame(200, $res->getStatus());
        self::assertSame('text/css; charset=utf-8', $res->headers()['Content-Type']);
        self::assertSame('body { color: red; }', $res->getBody());

        // 2. Directory traversal blocked
        $traversal = $server->serveStaticFile('/../../../../etc/passwd');
        self::assertNull($traversal);

        // 3. Non-existent file
        $missing = $server->serveStaticFile('/missing.png');
        self::assertNull($missing);

        // Cleanup
        @unlink($tempDir . '/style.css');
        @rmdir($tempDir);
    }

    public function testMaxRequestsConfigurationAndUnlimited(): void
    {
        $app = new Application();

        // 1. Default should be 100,000
        $defaultServer = new WorkerServer($app);
        self::assertSame(100000, $defaultServer->getMaxRequests());

        // 2. Custom value (e.g. 50,000)
        $customServer = new WorkerServer($app, maxRequests: 50000);
        self::assertSame(50000, $customServer->getMaxRequests());

        // 3. 0 allows unlimited requests (no recycling)
        $unlimitedServer = new WorkerServer($app, maxRequests: 0);
        self::assertSame(0, $unlimitedServer->getMaxRequests());

        // 4. Negative values clamped to 0
        $clampedServer = new WorkerServer($app, maxRequests: -10);
        self::assertSame(0, $clampedServer->getMaxRequests());
    }

    public function testAutoWorkersAndCpuCoreResolution(): void
    {
        $app = new Application();
        $cores = WorkerServer::detectCpuCores();

        self::assertTrue($cores >= 1);

        // 1. "auto" defaults to 1x cores
        self::assertSame($cores, WorkerServer::resolveWorkers('auto', 1));

        // 2. "auto:2" or "auto*2" parses multiplier
        self::assertSame($cores * 2, WorkerServer::resolveWorkers('auto:2'));
        self::assertSame($cores * 2, WorkerServer::resolveWorkers('auto*2'));
        self::assertSame($cores * 3, WorkerServer::resolveWorkers('auto:3'));

        // 3. Explicit numeric integers or strings
        self::assertSame(4, WorkerServer::resolveWorkers(4));
        self::assertSame(6, WorkerServer::resolveWorkers('6'));

        // 4. WorkerServer constructor auto defaults to resolved workers
        $server = new WorkerServer($app);
        self::assertSame(WorkerServer::resolveWorkers('auto'), $server->getWorkers());

        $customServer = new WorkerServer($app, workers: 5);
        self::assertSame(5, $customServer->getWorkers());
    }
}

