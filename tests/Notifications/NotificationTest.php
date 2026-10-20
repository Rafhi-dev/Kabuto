<?php

declare(strict_types=1);

namespace Kabuto\Tests\Notifications;

use Kabuto\Notifications\ArrayNotificationChannel;
use Kabuto\Notifications\ArrayNotificationFailureStore;
use Kabuto\Notifications\ArrayNotificationPreferenceStore;
use Kabuto\Notifications\NotificationDeliveryException;
use Kabuto\Notifications\NotificationJob;
use Kabuto\Notifications\Notification;
use Kabuto\Notifications\NotificationManager;
use Kabuto\Tests\TestCase;

final class NotificationJobSerializationTestNotification implements Notification
{
    public function via(mixed $notifiable): array { return []; }
}

final class NotificationTest extends TestCase
{
    public function testNotificationUsesConfiguredChannels(): void
    {
        $channel = new ArrayNotificationChannel();
        $notification = new class implements \Kabuto\Notifications\QueueableNotification {
            public function via(mixed $notifiable): array { return ['array']; }
            public function attempts(): int { return 1; }
            public function retryDelayMs(): int { return 0; }
            public function shouldSend(mixed $notifiable, string $channel): bool { return ($notifiable['allowed'] ?? true) === true; }
        };
        $manager = new NotificationManager(['array' => $channel]);
        self::assertSame(1, $manager->send(['id' => 1], $notification));
        self::assertCount(1, $channel->sent());
    }

    public function testUnknownChannelFailsClearly(): void
    {
        $notification = new class implements Notification {
            public function via(mixed $notifiable): array { return ['sms']; }
        };
        self::assertThrows(\RuntimeException::class, fn () => (new NotificationManager())->send(null, $notification));
    }

    public function testChannelFailureRetriesAndRecordsFailure(): void
    {
        $attempts = 0;
        $channel = new class($attempts) implements \Kabuto\Notifications\NotificationChannel {
            public function __construct(private int &$attempts) {}
            public function send(mixed $notifiable, Notification $notification): void
            {
                $this->attempts++;
                throw new \RuntimeException('temporary failure');
            }
        };
        $notification = new class implements Notification {
            public function via(mixed $notifiable): array { return ['remote']; }
        };
        $failures = new ArrayNotificationFailureStore();
        $manager = new NotificationManager(['remote' => $channel], failures: $failures, attempts: 2, retryDelayMs: 0);

        try {
            $manager->send(['id' => 9], $notification);
            self::assertTrue(false, 'Expected notification failure.');
        } catch (NotificationDeliveryException $e) {
            self::assertSame('remote', $e->channel);
            self::assertSame(2, $e->attempts);
        }
        self::assertSame(2, $attempts);
        self::assertSame(1, count($failures->failures()));
        self::assertSame('temporary failure', $failures->failures()[0]['error']);
    }

    public function testNotificationAndRecipientPreferencesCanOptOut(): void
    {
        $channel = new ArrayNotificationChannel();
        $preferences = new ArrayNotificationPreferenceStore();
        $notification = new class implements \Kabuto\Notifications\QueueableNotification {
            public function via(mixed $notifiable): array { return ['array']; }
            public function attempts(): int { return 1; }
            public function retryDelayMs(): int { return 0; }
            public function shouldSend(mixed $notifiable, string $channel): bool { return ($notifiable['allowed'] ?? true) === true; }
        };
        $preferences->set(['id' => 5], 'array', false);
        $manager = new NotificationManager(['array' => $channel], $preferences);

        self::assertSame(0, $manager->send(['id' => 5], $notification));
        self::assertSame(0, $manager->send(['id' => 6, 'allowed' => false], $notification));
        self::assertSame(1, $manager->send(['id' => 6, 'allowed' => true], $notification));
        self::assertSame(1, count($channel->sent()));
    }

    public function testNotificationSpecificPreferenceOverridesChannelDefault(): void
    {
        $preferences = new ArrayNotificationPreferenceStore();
        $notification = new class implements Notification {
            public function via(mixed $notifiable): array { return ['array']; }
        };
        $preferences->set(['id' => 5], 'array', false);
        $preferences->set(['id' => 5], 'array', true, $notification::class);
        self::assertTrue($preferences->allows(['id' => 5], 'array', $notification));
    }

    public function testQueueableNotificationJobContainsNoManagerInstance(): void
    {
        $notification = new NotificationJobSerializationTestNotification();
        $job = new NotificationJob(['id' => 1], $notification);
        self::assertTrue(str_contains(serialize($job), 'NotificationJob'));
        self::assertInstanceOf(\Kabuto\Queue\QueueableJob::class, $job);
        self::assertSame(1, $job->maxAttempts());
        self::assertSame(1, $job->retryDelay());
    }
}
