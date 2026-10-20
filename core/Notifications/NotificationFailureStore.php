<?php

declare(strict_types=1);

namespace Kabuto\Notifications;

interface NotificationFailureStore
{
    public function record(mixed $notifiable, Notification $notification, string $channel, int $attempts, \Throwable $failure): void;
}
