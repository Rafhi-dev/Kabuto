<?php

declare(strict_types=1);

namespace Kabuto\Tests\Realtime\Fixtures;

use Kabuto\Common\SubscribeMessage;
use Kabuto\Common\WebSocketGateway;
use Kabuto\Realtime\GatewayServerAware;
use Kabuto\Realtime\WebSocketServer;

#[WebSocketGateway('chat', port: 8080)]
final class ChatGateway implements GatewayServerAware
{
    public ?WebSocketServer $server = null;

    public function setWebSocketServer(WebSocketServer $server): void
    {
        $this->server = $server;
    }

    /** @return array<string,mixed> */
    #[SubscribeMessage('echo')]
    public function echo(array $payload): array
    {
        return ['op' => 'echo', 'data' => $payload];
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $client @return array<string,mixed> */
    #[SubscribeMessage('chat.join')]
    public function join(array $payload, array $client): array
    {
        return ['op' => 'joined', 'room' => $payload['room'] ?? '', 'client' => $client['id'] ?? null];
    }
}
