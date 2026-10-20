<?php

declare(strict_types=1);

namespace Kabuto\Tests\Realtime;

use Kabuto\Realtime\RedisRealtimeFanoutRelay;
use Kabuto\Realtime\RealtimeMessage;
use Kabuto\Tests\TestCase;

final class RedisRealtimeFanoutRelayTest extends TestCase
{
    public function testRelaysEnvelopeToConsumer(): void
    {
        $redis = new class {
            public function subscribe(array $channels, callable $callback): void
            {
                $callback($this, $channels[0], json_encode([
                    'channel' => 'updates',
                    'event' => 'order.updated',
                    'data' => ['id' => 7],
                    'id' => '2-node',
                ], JSON_THROW_ON_ERROR));
            }
        };
        $received = [];
        (new RedisRealtimeFanoutRelay($redis, prefix: 'app:'))->subscribe(static function (string $channel, RealtimeMessage $message) use (&$received): void {
            $received = [$channel, $message];
        });

        self::assertSame('updates', $received[0]);
        self::assertSame('order.updated', $received[1]->event);
        self::assertSame('2-node', $received[1]->id);
    }
}
