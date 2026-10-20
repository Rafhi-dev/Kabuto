<?php

declare(strict_types=1);

namespace Kabuto\Realtime;

/**
 * Server-side RFC 6455 opening handshake validation (RFC 7230 style header map, lowercase keys).
 */
final class WebSocketHandshake
{
    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** @param array<string,string|array<int,string>> $headers */
    public static function validate(array $headers): string
    {
        $version = self::text($headers['sec-websocket-version'] ?? '');
        if ($version !== '' && $version !== '13') {
            throw new WebSocketException('Unsupported WebSocket version [' . $version . '].', "HTTP/1.1 426 Upgrade Required\r\nSec-WebSocket-Version: 13\r\nConnection: Upgrade\r\nUpgrade: websocket\r\n\r\n");
        }
        if (!str_contains(strtolower(self::text($headers['upgrade'] ?? '')), 'websocket')) {
            throw new WebSocketException('Missing websocket upgrade header.', "HTTP/1.1 400 Bad Request\r\n\r\n");
        }
        if (!str_contains(strtolower(self::text($headers['connection'] ?? '')), 'upgrade')) {
            throw new WebSocketException('Missing websocket connection header.', "HTTP/1.1 400 Bad Request\r\n\r\n");
        }
        $key = self::text($headers['sec-websocket-key'] ?? '');
        $decoded = $key === '' ? false : base64_decode($key, true);
        if ($decoded === false || strlen($decoded) !== 16) {
            throw new WebSocketException('Invalid Sec-WebSocket-Key header.', "HTTP/1.1 400 Bad Request\r\n\r\n");
        }
        return self::accept($key);
    }

    public static function accept(string $key): string
    {
        return base64_encode(sha1($key . self::GUID, true));
    }

    public static function response(string $accept): string
    {
        return "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " . $accept . "\r\n\r\n";
    }

    private static function text(string|array $value): string
    {
        return is_array($value) ? trim(implode(', ', $value)) : trim($value);
    }
}
