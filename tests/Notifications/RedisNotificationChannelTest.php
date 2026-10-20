<?php

declare(strict_types=1);

namespace Kabuto\Tests\Notifications;

use Kabuto\Notifications\Notification;
use Kabuto\Notifications\RedisNotificationChannel;
use Kabuto\Tests\Fixtures\FakeRedis;
use Kabuto\Tests\TestCase;

final class RedisNotificationChannelTest extends TestCase
{
    public function testNotificationIsPublishedToRecipientTopic(): void
    {
        $redis = new FakeRedis();
        $notification = new class implements Notification {
            public function via(mixed $notifiable): array { return ['redis']; }
            public function toArray(mixed $notifiable): array { return ['message' => 'hello']; }
        };
        (new RedisNotificationChannel($redis, 'app:notify:'))->send(['id' => 42], $notification);
        self::assertCount(1, $redis->published);
        self::assertSame('app:notify:42', $redis->published[0][0]);
    }
}
