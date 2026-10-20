<?php

declare(strict_types=1);

namespace Kabuto\Tests\Notifications;

use Kabuto\Notifications\Notification;
use Kabuto\Notifications\WebhookNotificationChannel;
use Kabuto\Tests\TestCase;

final class WebhookNotificationChannelTest extends TestCase
{
    public function testWebhookUsesSignedRequest(): void
    {
        $captured = null;
        $notification = new class implements Notification { public function via(mixed $notifiable): array { return ['webhook']; } public function toWebhook(mixed $notifiable): array { return ['id' => 1]; } };
        $channel = new WebhookNotificationChannel('https://example.test/hook', 'secret', function (string $url, string $body, array $headers) use (&$captured): int { $captured = [$url, $body, $headers]; return 204; });
        $channel->send(['id' => 1], $notification);
        self::assertSame('https://example.test/hook', $captured[0]);
        self::assertTrue(isset($captured[2]['X-Kabuto-Signature']));
    }
}
