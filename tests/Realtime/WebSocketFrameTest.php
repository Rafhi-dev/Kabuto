<?php

declare(strict_types=1);

namespace Kabuto\Tests\Realtime;

use Kabuto\Realtime\WebSocketFrame;
use Kabuto\Tests\TestCase;

final class WebSocketFrameTest extends TestCase
{
    public function testShortTextRoundtrip(): void
    {
        $bytes = WebSocketFrame::encode(WebSocketFrame::OP_TEXT, 'hello');
        self::assertSame("\x81\x05hello", $bytes);
        $frame = WebSocketFrame::decode($bytes);
        self::assertSame(1, $frame['fin']);
        self::assertSame(WebSocketFrame::OP_TEXT, $frame['opcode']);
        self::assertSame('hello', $frame['payload']);
        self::assertSame(7, $frame['consumed']);
    }

    public function testExtendedLengthRoundtrip(): void
    {
        $payload = str_repeat('x', 300);
        $bytes = WebSocketFrame::encode(WebSocketFrame::OP_TEXT, $payload);
        self::assertSame("\x81\x7e", substr($bytes, 0, 2));
        $frame = WebSocketFrame::decode($bytes);
        self::assertSame($payload, $frame['payload']);
        self::assertSame(2 + 2 + 300, $frame['consumed']);
    }

    public function testLargeLengthRoundtrip(): void
    {
        $payload = str_repeat('y', 70_000);
        $frame = WebSocketFrame::decode(WebSocketFrame::encode(WebSocketFrame::OP_TEXT, $payload));
        self::assertSame($payload, $frame['payload']);
    }

    public function testMaskedClientFrameIsUnmasked(): void
    {
        $bytes = WebSocketFrame::encode(WebSocketFrame::OP_TEXT, '{"op":"ping"}', mask: true, maskKey: "\x12\x34\x56\x78");
        $frame = WebSocketFrame::decode($bytes);
        self::assertTrue((ord($bytes[1]) >> 7) === 1, 'Client frames must carry the mask bit.');
        self::assertSame('{"op":"ping"}', $frame['payload']);
    }

    public function testCloseFrameCarriesCodeAndReason(): void
    {
        $bytes = WebSocketFrame::close(1008, 'not allowed');
        $frame = WebSocketFrame::decode($bytes);
        self::assertSame(WebSocketFrame::OP_CLOSE, $frame['opcode']);
        self::assertSame(1008, unpack('n', substr($frame['payload'], 0, 2))[1]);
        self::assertSame('not allowed', substr($frame['payload'], 2));
    }

    public function testPartialFramesYieldNull(): void
    {
        self::assertNull(WebSocketFrame::decode(''));
        self::assertNull(WebSocketFrame::decode("\x81"));
        self::assertNull(WebSocketFrame::decode(substr(WebSocketFrame::encode(WebSocketFrame::OP_TEXT, 'abcd'), 0, -1)));
    }

    public function testZeroLengthPongFrame(): void
    {
        $bytes = WebSocketFrame::encode(WebSocketFrame::OP_PONG, '');
        self::assertSame("\x8a\x00", $bytes);
        $frame = WebSocketFrame::decode($bytes);
        self::assertSame('', $frame['payload']);
    }
}
