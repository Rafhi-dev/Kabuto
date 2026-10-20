<?php

declare(strict_types=1);

namespace Kabuto\Tests\Runtime;

use Kabuto\Http\Response;
use Kabuto\Runtime\Drivers\RoadRunnerDriver;
use Kabuto\Runtime\WorkerServer;
use Kabuto\Tests\TestCase;

final class RoadRunnerDriverTest extends TestCase
{
    public function testDriverProperties(): void
    {
        $driver = new RoadRunnerDriver();
        self::assertSame('RoadRunner (High-Performance Process Manager)', $driver->name());
        self::assertTrue(RoadRunnerDriver::isSupported());
        self::assertTrue(is_bool(RoadRunnerDriver::isInsideRoadRunner()));
    }

    public function testResolveRoadRunnerDriver(): void
    {
        $driver = WorkerServer::resolveDriver('roadrunner');
        self::assertInstanceOf(RoadRunnerDriver::class, $driver);
    }

    public function testConvertContextToRequest(): void
    {
        $context = [
            'method'     => 'POST',
            'uri'        => '/api/orders?source=app',
            'protocol'   => 'HTTP/1.1',
            'remoteAddr' => '192.168.1.100',
            'headers'    => [
                'Host'         => ['localhost:8000'],
                'Content-Type' => ['application/json'],
                'X-Api-Token'  => ['secret-abc'],
            ],
            'cookies'    => [
                'auth' => 'session_123',
            ],
            'rawQuery'   => 'source=app',
        ];

        $rawBody = '{"item":"Keyboard","qty":2}';

        $req = RoadRunnerDriver::convertContext($context, $rawBody);

        self::assertSame('POST', $req->method());
        self::assertSame('/api/orders', $req->path());
        self::assertSame('app', $req->query('source'));
        self::assertSame('localhost:8000', $req->header('host'));
        self::assertSame('secret-abc', $req->header('x-api-token'));
        self::assertSame('session_123', $req->cookie('auth'));
        self::assertSame($rawBody, $req->rawBody());
    }

    public function testBuildResponsePayload(): void
    {
        $response = new Response();
        $response->status(201);
        $response->header('X-Powered-By', 'Kabuto');
        $response->cookie('user_id', '42', ['expires' => 3600]);
        $response->json(['order_id' => 99]);

        $payload = RoadRunnerDriver::buildResponsePayload($response);

        // First 4 bytes are unsigned long length of context JSON
        self::assertTrue(strlen($payload) > 4);
        $meta = unpack('Vlen', substr($payload, 0, 4));
        self::assertTrue(is_array($meta));
        $ctxLen = $meta['len'];

        $contextJson = substr($payload, 4, $ctxLen);
        $decoded = json_decode($contextJson, true);
        self::assertSame(201, $decoded['status']);
        self::assertSame('Kabuto', $decoded['headers']['X-Powered-By']);
        self::assertArrayHasKey('Set-Cookie', $decoded['headers']);

        $body = substr($payload, 4 + $ctxLen);
        self::assertSame('{"order_id":99}', $body);
    }

    public function testGoridgeFrameRoundtrip(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertTrue(is_resource($stream));

        $testPayload = 'Hello RoadRunner Goridge Frame!';
        $written = RoadRunnerDriver::writeFrame($stream, 0, $testPayload);
        self::assertTrue($written);

        rewind($stream);

        $frame = RoadRunnerDriver::readFrame($stream);
        self::assertTrue($frame !== null);
        self::assertSame(0, $frame[0]);
        self::assertSame($testPayload, $frame[1]);

        fclose($stream);
    }

    public function testParsePayload(): void
    {
        $ctxData = ['method' => 'GET', 'uri' => '/health/live'];
        $ctxJson = json_encode($ctxData);
        $body = 'test-body';

        $rawPayload = pack('V', strlen($ctxJson)) . $ctxJson . $body;

        [$parsedCtx, $parsedBody] = RoadRunnerDriver::parsePayload($rawPayload);

        self::assertSame('GET', $parsedCtx['method']);
        self::assertSame('/health/live', $parsedCtx['uri']);
        self::assertSame('test-body', $parsedBody);
    }
}
