<?php

declare(strict_types=1);

namespace Kabuto\Tests\Realtime;

use Kabuto\Realtime\ArrayRealtimeChannel;
use Kabuto\Realtime\RealtimeManager;
use Kabuto\Realtime\RealtimeMessage;
use Kabuto\Realtime\SseResponse;
use Kabuto\Tests\TestCase;

final class RealtimeTest extends TestCase
{
    public function testMessagesCanBePublishedAndRenderedAsSse(): void
    {
        $channel = new ArrayRealtimeChannel();
        $manager = new RealtimeManager(['updates' => $channel]);
        $manager->publish('updates', new RealtimeMessage('user.updated', ['id' => 7], '42'));
        $response = SseResponse::from($channel->messages());
        self::assertSame('text/event-stream; charset=utf-8', $response->headers()['Content-Type']);
        self::assertStringContains('event: user.updated', $response->getBody());
        self::assertStringContains('data: {"id":7}', $response->getBody());
    }
}
