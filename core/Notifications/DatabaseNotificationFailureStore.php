<?php

declare(strict_types=1);

namespace Kabuto\Notifications;

use PDO;

final class DatabaseNotificationFailureStore implements NotificationFailureStore
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS kabuto_notification_failures (id VARCHAR(64) PRIMARY KEY, notifiable_id VARCHAR(191) NOT NULL, notification_type VARCHAR(255) NOT NULL, channel VARCHAR(100) NOT NULL, attempts INTEGER NOT NULL, error TEXT NOT NULL, failed_at INTEGER NOT NULL)');
    }

    public function record(mixed $notifiable, Notification $notification, string $channel, int $attempts, \Throwable $failure): void
    {
        $id = is_array($notifiable)
            ? ($notifiable['id'] ?? $notifiable['email'] ?? 'anonymous')
            : (is_object($notifiable) ? ($notifiable->id ?? $notifiable->email ?? 'anonymous') : ($notifiable ?? 'anonymous'));
        if (!is_scalar($id)) {
            $id = 'anonymous';
        }
        $stmt = $this->pdo->prepare('INSERT INTO kabuto_notification_failures (id, notifiable_id, notification_type, channel, attempts, error, failed_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            bin2hex(random_bytes(16)),
            substr((string) $id, 0, 191),
            $notification::class,
            $channel,
            $attempts,
            substr($failure->getMessage(), 0, 4000),
            time(),
        ]);
    }
}
