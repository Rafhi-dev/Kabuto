<?php

declare(strict_types=1);

namespace Kabuto\Tests\Notifications;

use Kabuto\Application;
use Kabuto\Notifications\ArrayNotificationChannel;
use Kabuto\Notifications\ArrayNotificationFailureStore;
use Kabuto\Notifications\Notification;
use Kabuto\Notifications\NotificationJob;
use Kabuto\Notifications\NotificationManager;
use Kabuto\Notifications\QueueableNotification;
use Kabuto\Queue\DatabaseQueue;
use Kabuto\Tests\TestCase;
use PDO;

final class QueueableSuccessTestNotification implements Notification
{
    public function via(mixed $notifiable): array { return ['array']; }
}

final class QueueableFailedTestNotification implements Notification
{
    public function via(mixed $notifiable): array { return ['mail']; }
}

final class RetryPolicyTestNotification implements QueueableNotification
{
    public function via(mixed $notifiable): array { return ['remote']; }
    public function attempts(): int { return 2; }
    public function retryDelayMs(): int { return 1000; }
    public function shouldSend(mixed $notifiable, string $channel): bool { return true; }
}

final class QueueFailureHookTestNotification implements Notification
{
    public function via(mixed $notifiable): array { return ['remote']; }
}

final class QueuePolicyTestNotification implements QueueableNotification
{
    public function via(mixed $notifiable): array { return ['remote']; }
    public function attempts(): int { return 4; }
    public function retryDelayMs(): int { return 2500; }
    public function shouldSend(mixed $notifiable, string $channel): bool { return true; }
}

final class QueueableNotificationTest extends TestCase
{
    public function testDatabaseQueueNotificationJobResolvesManagerFromWorkerContainer(): void
    {
        $channel = new ArrayNotificationChannel();
        $app = new Application();
        $app->container()->instance(NotificationManager::class, new NotificationManager(['array' => $channel]));

        $notification = new QueueableSuccessTestNotification();
        $job = new NotificationJob(['id' => 23], $notification);

        // Serialize/unserialize as the DB queue worker does; job has no manager
        // instance or connection resource embedded in its payload.
        $job = unserialize(serialize($job), ['allowed_classes' => true]);
        $job->handle();
        self::assertSame(1, count($channel->sent()));
    }

    public function testQueueFailureHookPersistsTerminalNotificationFailure(): void
    {
        $failures = new ArrayNotificationFailureStore();
        $manager = new NotificationManager([], failures: $failures);
        $notification = new QueueableFailedTestNotification();
        $job = new NotificationJob(['id' => 31], $notification);
        $app = new Application();
        $app->container()->instance(NotificationManager::class, $manager);
        $job->failed(new \RuntimeException('permanent mail failure'));

        self::assertSame(1, count($failures->failures()));
        self::assertSame('permanent mail failure', $failures->failures()[0]['error']);
    }

    public function testQueueablePolicyUsesNotificationSpecificAttempts(): void
    {
        $notification = new RetryPolicyTestNotification();
        $job = new NotificationJob(['id' => 44], $notification);
        self::assertSame(2, $job->maxAttempts());
        self::assertSame(1, $job->retryDelay());
    }

    public function testQueueJobUsesNotificationSpecificBackoff(): void
    {
        $job = new NotificationJob(['id' => 45], new QueuePolicyTestNotification());
        self::assertSame(4, $job->maxAttempts());
        self::assertSame(3, $job->retryDelay());
    }

    public function testDatabaseQueueCallsFailedHookAtTerminalAttempt(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $queue = new DatabaseQueue($pdo);
        $marker = sys_get_temp_dir() . '/kabuto-notification-failed-' . uniqid();
        $app = new Application();
        $app->container()->instance(NotificationManager::class, new NotificationManager([
            'remote' => new class($marker) implements \Kabuto\Notifications\NotificationChannel {
                public function __construct(private string $marker) {}
                public function send(mixed $notifiable, Notification $notification): void { throw new \RuntimeException('channel down'); }
            },
        ]));

        $job = new NotificationJob(['id' => 51], new QueueFailureHookTestNotification());
        $queue->dispatch($job);
        self::assertTrue($queue->workOnce(maxAttempts: 1, retryDelay: 0));
        self::assertCount(1, $queue->failed());
        self::assertStringContains('channel down', $queue->failed()[0]['last_error']);
    }
}
