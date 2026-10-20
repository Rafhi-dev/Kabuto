<?php

declare(strict_types=1);

namespace Kabuto\Notifications;

use PDO;

final class DatabaseNotificationPreferenceStore implements NotificationPreferenceStore
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS kabuto_notification_preferences (id VARCHAR(64) PRIMARY KEY, notifiable_id VARCHAR(191) NOT NULL, channel VARCHAR(100) NOT NULL, notification_type VARCHAR(255) NOT NULL, enabled INTEGER NOT NULL DEFAULT 1, updated_at INTEGER NOT NULL)');
    }

    public function set(mixed $notifiable, string $channel, bool $enabled, ?string $notificationClass = null): void
    {
        $id = $this->identifier($notifiable);
        $type = $notificationClass ?? '';
        $key = hash('sha256', $id . "\0" . $channel . "\0" . $type);
        $delete = $this->pdo->prepare('DELETE FROM kabuto_notification_preferences WHERE id = ?');
        $delete->execute([$key]);
        $insert = $this->pdo->prepare('INSERT INTO kabuto_notification_preferences (id, notifiable_id, channel, notification_type, enabled, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
        $insert->execute([$key, $id, $channel, $type, $enabled ? 1 : 0, time()]);
    }

    public function allows(mixed $notifiable, string $channel, Notification $notification): bool
    {
        $id = $this->identifier($notifiable, false);
        if ($id === null) {
            return true;
        }
        $stmt = $this->pdo->prepare('SELECT enabled FROM kabuto_notification_preferences WHERE notifiable_id = ? AND channel = ? AND notification_type IN (?, \'\') ORDER BY CASE WHEN notification_type = ? THEN 0 ELSE 1 END LIMIT 1');
        $stmt->execute([$id, $channel, $notification::class, $notification::class]);
        $enabled = $stmt->fetchColumn();
        return $enabled === false ? true : (int) $enabled === 1;
    }

    private function identifier(mixed $notifiable, bool $required = true): ?string
    {
        $id = is_array($notifiable)
            ? ($notifiable['id'] ?? $notifiable['email'] ?? null)
            : (is_object($notifiable) ? ($notifiable->id ?? $notifiable->email ?? null) : $notifiable);
        if ($id === null || $id === '') {
            if ($required) {
                throw new \InvalidArgumentException('Database notification preferences require a notifiable id or email.');
            }
            return null;
        }
        if (!is_scalar($id)) {
            throw new \InvalidArgumentException('Notifiable preference identifier must be scalar.');
        }
        return (string) $id;
    }
}
