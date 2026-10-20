<?php

declare(strict_types=1);

namespace Kabuto\Realtime;

/**
 * Optional gateway contract for receiving the active WebSocket server instance,
 * mirroring NestJS's `@WebSocketServer()` injection.
 */
interface GatewayServerAware
{
    public function setWebSocketServer(WebSocketServer $server): void;
}
