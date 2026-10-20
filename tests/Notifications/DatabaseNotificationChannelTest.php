<?php

declare(strict_types=1);

namespace Kabuto\Tests\Notifications;

use Kabuto\Notifications\DatabaseNotificationChannel;
use Kabuto\Notifications\Notification;
use Kabuto\Tests\TestCase;
use PDO;

final class DatabaseNotificationChannelTest extends TestCase
{
    public function testNotificationIsPersisted(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $notification = new class implements Notification { public function via(mixed $notifiable): array { return ['database']; } public function toArray(mixed $notifiable): array { return ['ok' => true]; } };
        (new DatabaseNotificationChannel($pdo))->send(['id' => 9], $notification);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM kabuto_notifications')->fetchColumn());
    }
}
