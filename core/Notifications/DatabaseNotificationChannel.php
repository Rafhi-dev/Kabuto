<?php

declare(strict_types=1);

namespace Kabuto\Notifications;

use PDO;

final class DatabaseNotificationChannel implements NotificationChannel
{
    public function __construct(private readonly PDO $pdo, private readonly string $table = 'kabuto_notifications') { $this->ensureTable(); }
    public function send(mixed $notifiable, Notification $notification): void
    {
        $recipient = is_array($notifiable) ? ($notifiable['id'] ?? null) : (is_object($notifiable) ? ($notifiable->id ?? null) : null);
        if ($recipient === null) throw new \RuntimeException('Database notifications require a notifiable id.');
        $data = method_exists($notification, 'toArray') ? $notification->toArray($notifiable) : ['notification' => $notification::class];
        $stmt = $this->pdo->prepare("INSERT INTO {$this->table} (id, notifiable_id, type, data, read_at, created_at) VALUES (?, ?, ?, ?, NULL, ?)");
        $stmt->execute([bin2hex(random_bytes(16)), (string) $recipient, $notification::class, json_encode($data, JSON_THROW_ON_ERROR), time()]);
    }
    private function ensureTable(): void { $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->table} (id VARCHAR(64) PRIMARY KEY, notifiable_id VARCHAR(191) NOT NULL, type VARCHAR(255) NOT NULL, data TEXT NOT NULL, read_at INTEGER NULL, created_at INTEGER NOT NULL)"); }
}
