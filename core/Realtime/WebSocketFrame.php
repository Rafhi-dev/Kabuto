<?php

declare(strict_types=1);

namespace Kabuto\Realtime;

/**
 * Server-side RFC 6455 handshake and WebSocket frame codec.
 */
final class WebSocketFrame
{
    public const OP_CONT = 0x0;
    public const OP_TEXT = 0x1;
    public const OP_CLOSE = 0x8;
    public const OP_PING = 0x9;
    public const OP_PONG = 0xA;

    public static function encode(int $opcode, string $payload = '', bool $mask = false, ?string $maskKey = null): string
    {
        $len = strlen($payload);
        $frame = chr(0x80 | $opcode);
        $maskBit = $mask ? 0x80 : 0;
        if ($len <= 125) $frame .= chr($maskBit | $len);
        elseif ($len <= 65535) $frame .= chr($maskBit | 126) . pack('n', $len);
        else $frame .= chr($maskBit | 127) . pack('J', $len);
        if (!$mask) return $frame . $payload;
        $key = $maskKey ?? random_bytes(4);
        $frame .= $key;
        if ($len > 0) {
            $frame .= substr($payload ^ str_repeat($key, intdiv($len + 3, 4) + 1), 0, $len);
        }
        return $frame;
    }

    /** @return null|array{fin:int,opcode:int,payload:string,consumed:int} null when more bytes are needed */
    public static function decode(string $buffer): ?array
    {
        $bytes = strlen($buffer);
        if ($bytes < 2) return null;
        $first = ord($buffer[0]);
        $second = ord($buffer[1]);
        $opcode = $first & 0x0F;
        $offset = 2;
        $len = $second & 0x7F;
        if ($len === 126) {
            if ($bytes < 4) return null;
            $len = (int) unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($len === 127) {
            if ($bytes < 10) return null;
            $len = (int) unpack('J', substr($buffer, 2, 8))[1];
            $offset = 10;
        }
        if ($len > 0x7FFFFFFF) throw new WebSocketException('WebSocket frame exceeds 2GB.');
        $masked = ($second >> 7) & 1;
        $maskKey = '';
        if ($masked) {
            if ($bytes < $offset + 4) return null;
            $maskKey = substr($buffer, $offset, 4);
            $offset += 4;
        }
        if ($bytes < $offset + $len) return null;
        $payload = substr($buffer, $offset, $len);
        if ($masked && $len > 0) {
            $payload = substr($payload ^ str_repeat($maskKey, intdiv($len + 3, 4) + 1), 0, $len);
        }
        return ['fin' => ($first >> 7) & 1, 'opcode' => $opcode, 'payload' => $payload, 'consumed' => $offset + $len];
    }

    public static function close(int $code = 1000, string $reason = ''): string
    {
        return self::encode(self::OP_CLOSE, pack('n', $code) . substr($reason, 0, 123));
    }
}
