<?php

declare(strict_types=1);

namespace Kabuto\Common;

use Attribute;

/**
 * Marks a class as a NestJS-style WebSocket gateway: a class whose methods
 * annotated with #[SubscribeMessage] handle client operations for a realtime
 * WebSocket connection.
 *
 *   #[WebSocketGateway('chat', port: 8080)]
 *   final class ChatGateway
 *   {
 *       #[SubscribeMessage('echo')]
 *       public function echo(array $payload): array
 *       {
 *           return ['op' => 'echo', 'data' => $payload];
 *       }
 *   }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class WebSocketGateway
{
    /**
     * @param string              $path    Logical gateway path/namespace.
     * @param int                 $port    Suggested listen port (0 = application default).
     * @param array<string,mixed> $options Adapter options such as ping/idle timeouts.
     */
    public function __construct(
        public readonly string $path = '',
        public readonly int $port = 0,
        public readonly array $options = [],
    ) {
    }
}
