<?php

declare(strict_types=1);

namespace Kabuto\Notifications;

interface Notification
{
    /** @return list<string> */
    public function via(mixed $notifiable): array;

}
