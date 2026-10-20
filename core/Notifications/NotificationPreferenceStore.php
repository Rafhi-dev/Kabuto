<?php

declare(strict_types=1);

namespace Kabuto\Notifications;

interface NotificationPreferenceStore
{
    public function allows(mixed $notifiable, string $channel, Notification $notification): bool;
}
