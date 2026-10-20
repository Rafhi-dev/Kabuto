<?php

declare(strict_types=1);

namespace Kabuto\Tests\Realtime\Fixtures;

use Kabuto\Common\SubscribeMessage;
use Kabuto\Common\WebSocketGateway;

#[WebSocketGateway('admin')]
final class AdminGateway
{
    /** @return array<string,mixed> */
    #[SubscribeMessage('admin.stats')]
    public function stats(): array
    {
        return ['op' => 'stats', 'ok' => true];
    }
}
