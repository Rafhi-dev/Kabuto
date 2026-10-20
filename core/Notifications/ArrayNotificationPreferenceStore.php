<?php

declare(strict_types=1);

namespace Kabuto\Notifications;

/** In-memory preference store suitable for tests and single-process apps. */
final class ArrayNotificationPreferenceStore implements NotificationPreferenceStore
{
    /** @var array<string,bool> */
    private array $preferences = [];

    public function set(mixed $notifiable, string $channel, bool $enabled, ?string $notificationClass = null): void
    {
        $this->preferences[$this->key($notifiable, $channel, $notificationClass)] = $enabled;
    }

    public function allows(mixed $notifiable, string $channel, Notification $notification): bool
    {
        $classKey = $this->key($notifiable, $channel, $notification::class);
        $channelKey = $this->key($notifiable, $channel, null);
        return $this->preferences[$classKey] ?? $this->preferences[$channelKey] ?? true;
    }

    private function key(mixed $notifiable, string $channel, ?string $notificationClass): string
    {
        if (is_array($notifiable)) {
            $recipient = $notifiable['id'] ?? $notifiable['email'] ?? 'anonymous';
        } elseif (is_object($notifiable)) {
            $recipient = $notifiable->id ?? $notifiable->email ?? 'anonymous';
        } else {
            $recipient = $notifiable ?? 'anonymous';
        }
        return (string) $recipient . '|' . $channel . '|' . ($notificationClass ?? '*');
    }
}
