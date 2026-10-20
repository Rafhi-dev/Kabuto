<?php

declare(strict_types=1);

namespace Kabuto\Tests\Notifications;

use Kabuto\Notifications\DatabaseNotificationFailureStore;
use Kabuto\Notifications\DatabaseNotificationPreferenceStore;
use Kabuto\Notifications\Notification;
use Kabuto\Tests\TestCase;
use PDO;

final class DatabaseNotificationPreferenceStoreTest extends TestCase
{
    public function testDatabasePreferenceAndFailurePersistence(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $preferences = new DatabaseNotificationPreferenceStore($pdo);
        $notification = new class implements Notification {
            public function via(mixed $notifiable): array { return ['mail']; }
        };

        self::assertTrue($preferences->allows(['id' => 7], 'mail', $notification));
        $preferences->set(['id' => 7], 'mail', false);
        self::assertFalse($preferences->allows(['id' => 7], 'mail', $notification));
        $preferences->set(['id' => 7], 'mail', true, $notification::class);
        self::assertTrue($preferences->allows(['id' => 7], 'mail', $notification));

        $failures = new DatabaseNotificationFailureStore($pdo);
        $failures->record(['id' => 7], $notification, 'mail', 3, new \RuntimeException('smtp down'));
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM kabuto_notification_failures')->fetchColumn());
    }

    public function testPreferenceRequiresNotifiableIdentifierWhenWriting(): void
    {
        $store = new DatabaseNotificationPreferenceStore(new PDO('sqlite::memory:'));
        self::assertThrows(\InvalidArgumentException::class, fn () => $store->set([], 'mail', false));
    }
}
