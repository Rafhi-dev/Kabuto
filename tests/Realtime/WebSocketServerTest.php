<?php

declare(strict_types=1);

namespace Kabuto\Tests\Realtime;

use Kabuto\Realtime\ArrayRealtimeChannel;
use Kabuto\Realtime\RealtimeManager;
use Kabuto\Realtime\RealtimeMessage;
use Kabuto\Realtime\WebSocketFrame;
use Kabuto\Realtime\WebSocketServer;
use Kabuto\Tests\TestCase;

final class WebSocketServerTest extends TestCase
{
    public function testDeliverWritesOnlyToSubscribedConnections(): void
    {
        $server = new WebSocketServer(new RealtimeManager(['updates' => new ArrayRealtimeChannel()]), pingIntervalSeconds: 0, idleTimeoutSeconds: 0);
        $a = fopen('php://memory', 'w+');
        $b = fopen('php://memory', 'w+');
        $server->attach($a, ['updates']);
        $server->attach($b, []);
        self::assertSame(2, $server->connectionCount());

        $sent = $server->deliver('updates', new RealtimeMessage('hello', ['a' => 1], '9'));
        self::assertSame(1, $sent);

        rewind($a);
        $frame = WebSocketFrame::decode((string) stream_get_contents($a));
        $payload = json_decode($frame['payload'], true);
        self::assertSame(['op' => 'event', 'channel' => 'updates', 'event' => 'hello', 'data' => ['a' => 1], 'id' => '9'], $payload);

        rewind($b);
        self::assertSame('', stream_get_contents($b));
    }

    public function testDeliverDetachesConnectionsWhoseSocketRefusesWrites(): void
    {
        $server = new WebSocketServer(new RealtimeManager(['ch' => new ArrayRealtimeChannel()]), pingIntervalSeconds: 0, idleTimeoutSeconds: 0);
        $dead = fopen('php://memory', 'r');
        $detached = [];
        $server->attach($dead, ['ch'], static function (int $code, string $reason) use (&$detached): void {
            $detached = [$code, $reason];
        });

        self::assertSame(0, $server->deliver('ch', new RealtimeMessage('e', null)));
        self::assertSame([1006, 'client is gone'], $detached);
        self::assertSame(0, $server->connectionCount());
    }

    public function testWiredManagerFansOutPublishesToSubscribers(): void
    {
        $channel = new ArrayRealtimeChannel();
        $manager = new RealtimeManager(['updates' => $channel]);
        $server = new WebSocketServer($manager, pingIntervalSeconds: 0, idleTimeoutSeconds: 0);
        $wired = $server->wired();
        $stream = fopen('php://memory', 'w+');
        $server->attach($stream, ['updates']);

        $wired->publish('updates', new RealtimeMessage('hello', ['n' => 1], '7-int'));

        rewind($stream);
        $frame = WebSocketFrame::decode((string) stream_get_contents($stream));
        $payload = json_decode($frame['payload'], true);
        self::assertSame('event', $payload['op']);
        self::assertSame('hello', $payload['event']);
        self::assertSame('7-int', $payload['id']);
        self::assertCount(1, $channel->messages(), 'Publish still reaches the local channel buffer.');
    }

    public function testRunConnectionPerformsHandshakeAndHandlesSubscriptions(): void
    {
        $server = new WebSocketServer(new RealtimeManager(['updates' => new ArrayRealtimeChannel()]), pingIntervalSeconds: 0, idleTimeoutSeconds: 0);
        [$client, $listener, $stream] = $this->pair();

        fwrite($client, "GET /ws HTTP/1.1\r\nHost: 127.0.0.1\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");
        fwrite($client, WebSocketFrame::encode(WebSocketFrame::OP_TEXT, '{"op":"subscribe","channel":"updates"}', mask: true, maskKey: "\x01\x02\x03\x04"));
        fwrite($client, WebSocketFrame::encode(WebSocketFrame::OP_TEXT, '{"op":"ping"}', mask: true, maskKey: "\x01\x02\x03\x04"));
        fwrite($client, WebSocketFrame::close());

        $server->runConnection($stream);
        $raw = $this->drain($client);
        fclose($client);
        fclose($listener);
        if (is_resource($stream)) fclose($stream);

        $head = substr($raw, 0, (int) strpos($raw, "\r\n\r\n") + 4);
        self::assertStringContains('HTTP/1.1 101 Switching Protocols', $head);
        self::assertStringContains('Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $head);

        $buffer = substr($raw, strlen($head));
        $subscribed = WebSocketFrame::decode($buffer);
        self::assertSame(WebSocketFrame::OP_TEXT, $subscribed['opcode']);
        $payload = json_decode($subscribed['payload'], true);
        self::assertSame(['op' => 'subscribed', 'channel' => 'updates'], $payload);

        $buffer = substr($buffer, $subscribed['consumed']);
        $pong = WebSocketFrame::decode($buffer);
        self::assertStringContains('"op":"pong"', $pong['payload']);

        $buffer = substr($buffer, $pong['consumed']);
        $close = WebSocketFrame::decode($buffer);
        self::assertSame(WebSocketFrame::OP_CLOSE, $close['opcode']);
        self::assertSame(1000, unpack('n', substr($close['payload'], 0, 2))[1] ?? 0);
    }

    public function testChannelAuthorizationRejectionClosesConnection(): void
    {
        $server = new WebSocketServer(
            new RealtimeManager(['public' => new ArrayRealtimeChannel()]),
            pingIntervalSeconds: 0,
            idleTimeoutSeconds: 0,
            authorize: static fn (string $channel, mixed $ctx): bool => $channel !== 'admin',
        );
        [$client, $listener, $stream] = $this->pair();

        fwrite($client, "GET /ws HTTP/1.1\r\nHost: 127.0.0.1\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");
        fwrite($client, WebSocketFrame::encode(WebSocketFrame::OP_TEXT, '{"op":"subscribe","channel":"admin"}', mask: true, maskKey: "\x01\x02\x03\x04"));

        $server->runConnection($stream);
        $raw = $this->drain($client);
        fclose($client);
        fclose($listener);
        if (is_resource($stream)) fclose($stream);

        $head = substr($raw, 0, (int) strpos($raw, "\r\n\r\n") + 4);
        self::assertStringContains('HTTP/1.1 101', $head);
        $buffer = substr($raw, strlen($head));
        $error = WebSocketFrame::decode($buffer);
        self::assertStringContains('Channel not authorized: admin', $error['payload']);
        $buffer = substr($buffer, $error['consumed']);
        $close = WebSocketFrame::decode($buffer);
        self::assertSame(1008, unpack('n', substr($close['payload'], 0, 2))[1] ?? 0);
        self::assertSame(0, $server->connectionCount());
    }

    public function testAuthenticationFailureDisconnectsBeforeSubscriptions(): void
    {
        $server = new WebSocketServer(
            new RealtimeManager(['updates' => new ArrayRealtimeChannel()]),
            pingIntervalSeconds: 0,
            idleTimeoutSeconds: 0,
            auth: static function (mixed $token): mixed {
                return $token === 'good' ? ['id' => 1] : false;
            },
        );
        [$client, $listener, $stream] = $this->pair();

        fwrite($client, "GET /ws HTTP/1.1\r\nHost: 127.0.0.1\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");
        fwrite($client, WebSocketFrame::encode(WebSocketFrame::OP_TEXT, '{"op":"auth","token":"bad"}', mask: true, maskKey: "\x01\x02\x03\x04"));
        fwrite($client, WebSocketFrame::encode(WebSocketFrame::OP_TEXT, '{"op":"subscribe","channel":"updates"}', mask: true, maskKey: "\x01\x02\x03\x04"));

        $server->runConnection($stream);
        $raw = $this->drain($client);
        fclose($client);
        fclose($listener);
        if (is_resource($stream)) fclose($stream);

        $head = substr($raw, 0, (int) strpos($raw, "\r\n\r\n") + 4);
        $buffer = substr($raw, strlen($head));
        $error = WebSocketFrame::decode($buffer);
        self::assertStringContains('Authentication failed.', $error['payload']);
        $buffer = substr($buffer, $error['consumed']);
        $close = WebSocketFrame::decode($buffer);
        self::assertSame(4001, unpack('n', substr($close['payload'], 0, 2))[1] ?? 0);
    }

    public function testAuthenticatedSubscriberCanSubscribeAndThenCloses(): void
    {
        $server = new WebSocketServer(
            new RealtimeManager(['updates' => new ArrayRealtimeChannel()]),
            pingIntervalSeconds: 0,
            idleTimeoutSeconds: 0,
            auth: static fn (mixed $token): mixed => $token === 'good' ? ['sub' => 42] : false,
            authorize: static fn (string $chan, mixed $ctx): bool => is_array($ctx) && ($ctx['sub'] ?? null) === 42,
        );
        [$client, $listener, $stream] = $this->pair();

        fwrite($client, "GET /ws HTTP/1.1\r\nHost: 127.0.0.1\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");
        fwrite($client, WebSocketFrame::encode(WebSocketFrame::OP_TEXT, '{"op":"auth","token":"good"}', mask: true, maskKey: "\x01\x02\x03\x04"));
        fwrite($client, WebSocketFrame::encode(WebSocketFrame::OP_TEXT, '{"op":"subscribe","channel":"updates"}', mask: true, maskKey: "\x01\x02\x03\x04"));
        fwrite($client, WebSocketFrame::close());

        $server->runConnection($stream);
        $raw = $this->drain($client);
        fclose($client);
        fclose($listener);
        if (is_resource($stream)) fclose($stream);

        self::assertStringContains('Sec-WebSocket-Accept', $raw);
        $head = substr($raw, 0, (int) strpos($raw, "\r\n\r\n") + 4);
        $buffer = substr($raw, strlen($head));
        $payloads = [];
        while (($frame = WebSocketFrame::decode($buffer)) !== null) {
            $payloads[] = $frame['payload'];
            $buffer = substr($buffer, $frame['consumed']);
        }
        self::assertCount(3, $payloads, 'Expected authenticated, subscribed and close frames.');
        self::assertStringContains('"op":"authenticated"', $payloads[0]);
        self::assertStringContains('"op":"subscribed"', $payloads[1]);
        self::assertSame(1000, unpack('n', substr($payloads[2], 0, 2))[1]);
    }

    public function testServeMultiplexesMultipleLiveClientsAndBroadcastsToBoth(): void
    {
        $channel = new ArrayRealtimeChannel();
        $manager = new RealtimeManager(['updates' => $channel]);
        $server = new WebSocketServer($manager, pingIntervalSeconds: 0, idleTimeoutSeconds: 0, tickMs: 2);
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($listener, (string) $errstr);
        $address = (string) stream_socket_get_name($listener, false);
        $clients = [];
        for ($i = 0; $i < 2; $i++) {
            $client = fsockopen($address, -1, $errno, $errstr, 2.0);
            self::assertTrue(is_resource($client), (string) $errstr);
            stream_set_blocking($client, false);
            fwrite($client, "GET /ws HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");
            fwrite($client, WebSocketFrame::encode(WebSocketFrame::OP_TEXT, '{"op":"subscribe","channel":"updates"}', mask: true, maskKey: "\x01\x02\x03\x04"));
            $clients[] = $client;
        }

        $wired = $server->wired();
        $probes = 0;
        $handled = $server->serveSocket($listener, shouldStop: static function () use (&$probes, $wired, &$clients): bool {
            $probes++;
            if ($probes === 5) $wired->publish('updates', new RealtimeMessage('multi.client', ['ok' => true], 'shared-1'));
            if ($probes >= 12) return true;
            return false;
        });

        self::assertSame(2, $handled);
        foreach ($clients as $client) {
            $raw = '';
            for ($i = 0; $i < 30; $i++) {
                $chunk = @fread($client, 8192);
                if (is_string($chunk) && $chunk !== '') $raw .= $chunk;
                if (str_contains($raw, '"event":"multi.client"')) break;
                usleep(1000);
            }
            self::assertStringContains('HTTP/1.1 101 Switching Protocols', $raw);
            self::assertStringContains('"event":"multi.client"', $raw);
            self::assertStringContains('"id":"shared-1"', $raw);
            fclose($client);
        }
        fclose($listener);
        self::assertSame(0, $server->connectionCount());
    }

    /** @return array{0:resource,1:resource} */
    private function pair(): array
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($listener, (string) $errstr);
        $name = (string) stream_socket_get_name($listener, false);
        $errno = 0;
        $errstr = '';
        $client = fsockopen($name, -1, $errno, $errstr, 2.0);
        self::assertTrue(is_resource($client), $errstr);
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
