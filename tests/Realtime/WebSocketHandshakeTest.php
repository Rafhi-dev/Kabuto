<?php

declare(strict_types=1);

namespace Kabuto\Tests\Realtime;

use Kabuto\Realtime\WebSocketException;
use Kabuto\Realtime\WebSocketHandshake;
use Kabuto\Tests\TestCase;

final class WebSocketHandshakeTest extends TestCase
{
    private const SAMPLE_KEY = 'dGhlIHNhbXBsZSBub25jZQ==';

    public function testValidHandshakeProducesRfcAcceptValue(): void
    {
        $accept = WebSocketHandshake::validate([
            'upgrade' => 'websocket',
            'connection' => 'keep-alive, Upgrade',
            'sec-websocket-key' => self::SAMPLE_KEY,
            'sec-websocket-version' => '13',
        ]);
        self::assertSame('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $accept);

        $response = WebSocketHandshake::response($accept);
        self::assertStringContains('HTTP/1.1 101 Switching Protocols', $response);
        self::assertStringContains('Sec-WebSocket-Accept: ' . $accept, $response);
        self::assertStringContains('Upgrade: websocket', $response);
        self::assertStringContains('Connection: Upgrade', $response);
    }

    public function testHeaderListValuesAreJoined(): void
    {
        $accept = WebSocketHandshake::validate([
            'upgrade' => ['websocket'],
            'connection' => ['Upgrade'],
            'sec-websocket-key' => self::SAMPLE_KEY,
        ]);
        self::assertSame('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $accept);
    }

    public function testWrongVersionAnswers426(): void
    {
        self::assertThrows(WebSocketException::class, static function (): void {
            WebSocketHandshake::validate([
                'upgrade' => 'websocket',
                'connection' => 'Upgrade',
                'sec-websocket-key' => self::SAMPLE_KEY,
                'sec-websocket-version' => '8',
            ]);
        });
        try {
            WebSocketHandshake::validate(['upgrade' => 'websocket', 'connection' => 'Upgrade', 'sec-websocket-key' => self::SAMPLE_KEY, 'sec-websocket-version' => '8']);
        } catch (WebSocketException $e) {
            self::assertStringContains('HTTP/1.1 426 Upgrade Required', $e->response);
            self::assertStringContains('Sec-WebSocket-Version: 13', $e->response);
        }
    }

    public function testMissingUpgradeHeaderAnswers400(): void
    {
        try {
            WebSocketHandshake::validate(['connection' => 'Upgrade', 'sec-websocket-key' => self::SAMPLE_KEY]);
        } catch (WebSocketException $e) {
            self::assertStringContains('HTTP/1.1 400 Bad Request', $e->response);
        }
    }

    public function testKeyMustBe16Base64Bytes(): void
    {
        foreach (['', 'tooshort', base64_encode('12345678'), 'not base64!!'] as $key) {
            self::assertThrows(WebSocketException::class, static function () use ($key): void {
                WebSocketHandshake::validate(['upgrade' => 'websocket', 'connection' => 'Upgrade', 'sec-websocket-key' => $key, 'sec-websocket-version' => '13']);
            }, "Key [{$key}] must be rejected.");
        }
    }
}
