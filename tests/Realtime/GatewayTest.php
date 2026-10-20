<?php

declare(strict_types=1);

namespace Kabuto\Tests\Realtime;

use Kabuto\Realtime\ArrayRealtimeChannel;
use Kabuto\Realtime\GatewayRegistry;
use Kabuto\Realtime\GatewayScanner;
use Kabuto\Realtime\RealtimeManager;
use Kabuto\Realtime\WebSocketFrame;
use Kabuto\Realtime\WebSocketServer;
use Kabuto\Tests\Realtime\Fixtures\AdminGateway;
use Kabuto\Tests\Realtime\Fixtures\ChatGateway;
use Kabuto\Tests\TestCase;

final class GatewayTest extends TestCase
{
    public function testRegistryRegistersHandlersAndDefinitions(): void
    {
        $registry = (new GatewayRegistry())->register(ChatGateway::class);

        self::assertTrue($registry->has('echo'));
        self::assertTrue($registry->has('chat.join'));
        self::assertFalse($registry->has('missing'));
        self::assertContains(ChatGateway::class, $registry->gateways());
        self::assertSame(8080, $registry->definition(ChatGateway::class)?->port);
        self::assertSame('chat', $registry->definition(ChatGateway::class)?->path);
    }

    public function testDispatchInvokesHandlerWithPayloadAndClient(): void
    {
        $registry = (new GatewayRegistry())->register(ChatGateway::class);

        self::assertSame(['op' => 'echo', 'data' => ['message' => 'hi']], $registry->dispatch('echo', ['message' => 'hi'], ['id' => 1]));
        self::assertSame(['op' => 'joined', 'room' => 'general', 'client' => 7], $registry->dispatch('chat.join', ['room' => 'general'], ['id' => 7]));
    }

    public function testDuplicateHandlerEventThrows(): void
    {
        self::assertThrows(\LogicException::class, static function (): void {
            (new GatewayRegistry())->register(ChatGateway::class)->register(ChatGateway::class);
        });
    }

    public function testNonGatewayClassThrows(): void
    {
        self::assertThrows(\LogicException::class, static fn () => (new GatewayRegistry())->register(\stdClass::class));
    }

    public function testScannerDiscoversGatewayClassesInDirectory(): void
    {
        $registry = new GatewayRegistry();
        $found = (new GatewayScanner($registry))->discover(__DIR__ . '/Fixtures', 'Kabuto\\Tests\\Realtime\\Fixtures');

        self::assertContains(ChatGateway::class, $found);
        self::assertContains(AdminGateway::class, $found);
        self::assertTrue($registry->has('echo'));
        self::assertTrue($registry->has('admin.stats'));
    }

    public function testWebSocketServerDispatchesGatewayOperationToClient(): void
    {
        $registry = (new GatewayRegistry())->register(ChatGateway::class);
        $server = new WebSocketServer(
            new RealtimeManager(['chat' => new ArrayRealtimeChannel()]),
            pingIntervalSeconds: 0,
            idleTimeoutSeconds: 0,
            gateways: $registry,
        );

        self::assertSame($server, $registry->server(), 'The running server should be attached to the registry.');

        [$client, $listener, $stream] = $this->pair();
        fwrite($client, "GET /ws HTTP/1.1\r\nHost: 127.0.0.1\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");
        fwrite($client, WebSocketFrame::encode(WebSocketFrame::OP_TEXT, '{"op":"echo","data":{"message":"pong"}}', mask: true, maskKey: "\x01\x02\x03\x04"));
        fwrite($client, WebSocketFrame::close());

        $server->runConnection($stream);
        $raw = $this->drain($client);
        fclose($client);
        fclose($listener);
        if (is_resource($stream)) fclose($stream);

        $head = substr($raw, 0, (int) strpos($raw, "\r\n\r\n") + 4);
        $reply = WebSocketFrame::decode(substr($raw, strlen($head)));
        self::assertNotFalse($reply);
        $payload = json_decode($reply['payload'], true);
        self::assertSame('echo', $payload['op']);
        self::assertSame(['message' => 'pong'], $payload['data']);
    }

    public function testUnknownOperationStillClosesWithProtocolError(): void
    {
        $registry = new GatewayRegistry();
        $server = new WebSocketServer(
            new RealtimeManager(['chat' => new ArrayRealtimeChannel()]),
            pingIntervalSeconds: 0,
            idleTimeoutSeconds: 0,
            gateways: $registry,
        );

        [$client, $listener, $stream] = $this->pair();
        fwrite($client, "GET /ws HTTP/1.1\r\nHost: 127.0.0.1\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");
        fwrite($client, WebSocketFrame::encode(WebSocketFrame::OP_TEXT, '{"op":"nope"}', mask: true, maskKey: "\x01\x02\x03\x04"));

        $server->runConnection($stream);
        $raw = $this->drain($client);
        fclose($client);
        fclose($listener);
        if (is_resource($stream)) fclose($stream);

        self::assertStringContains('Unsupported op: nope', $raw);
    }

    /** @return array{0:resource,1:resource,2:resource} */
    private function pair(): array
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($listener, (string) $errstr);
        $address = (string) stream_socket_get_name($listener, false);
        $client = fsockopen($address, -1, $errno, $errstr, 2.0);
        self::assertTrue(is_resource($client), (string) $errstr);
        $stream = stream_socket_accept($listener, 2.0);
        self::assertNotFalse($stream, 'Accepted server-side stream must be available.');
        return [$client, $listener, $stream];
    }

    private function drain($client): string
    {
        stream_set_blocking($client, false);
        $raw = '';
        for ($i = 0; $i < 2000; $i++) {
            $chunk = @fread($client, 65536);
            if ($chunk !== false && $chunk !== '') {
                $raw .= $chunk;
                continue;
            }
            if (feof($client)) break;
            usleep(1000);
        }
        return $raw;
    }
}
