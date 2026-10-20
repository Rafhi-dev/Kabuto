<?php

declare(strict_types=1);

namespace Kabuto\Tests\Realtime;

use Kabuto\Realtime\RedisPubSubChannel;
use Kabuto\Realtime\RealtimeMessage;
use Kabuto\Tests\TestCase;

final class RedisPubSubChannelTest extends TestCase
{
    public function testMessageIsPublishedAndRetainedLocally(): void
    {
        $redis = new class { public array $published = []; public function publish(string $channel, string $payload): int { $this->published[] = [$channel, $payload]; return 1; } };
        $channel = new RedisPubSubChannel($redis, 'updates', 'app:');
        $channel->publish(new RealtimeMessage('changed', ['id' => 1]));
        self::assertCount(1, $redis->published);
        self::assertSame('app:updates', $redis->published[0][0]);
        self::assertCount(1, $channel->messages());
    }
}
