<?php

declare(strict_types=1);

namespace Kabuto\Notifications;

final class ArrayNotificationChannel implements NotificationChannel
{
    /** @var list<array{notifiable:mixed,notification:Notification}> */
    private array $sent = [];
    public function send(mixed $notifiable, Notification $notification): void { $this->sent[] = compact('notifiable', 'notification'); }
    public function sent(): array { return $this->sent; }
}
