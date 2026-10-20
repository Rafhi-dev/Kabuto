<?php

declare(strict_types=1);

namespace Kabuto\Notifications;

final class RedisNotificationChannel implements NotificationChannel
{
    public function __construct(private readonly object $redis, private readonly string $prefix = 'kabuto:notifications:') {}

    public function send(mixed $notifiable, Notification $notification): void
    {
        $recipient = is_array($notifiable) ? ($notifiable['id'] ?? $notifiable['email'] ?? 'anonymous') : (is_object($notifiable) && isset($notifiable->id) ? $notifiable->id : 'anonymous');
        $payload = ['recipient' => (string) $recipient, 'notification' => $notification::class];
        if (method_exists($notification, 'toArray')) $payload['data'] = $notification->toArray($notifiable);
        $this->redis->publish($this->prefix . (string) $recipient, json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
