<?php

declare(strict_types=1);

namespace Kabuto\Realtime;

interface WebSocketTransport
{
    public function publish(string $channel, RealtimeMessage $message): void;
}
