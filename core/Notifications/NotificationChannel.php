<?php

declare(strict_types=1);

namespace Kabuto\Notifications;

interface NotificationChannel
{
    public function send(mixed $notifiable, Notification $notification): void;
}
