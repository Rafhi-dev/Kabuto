<?php

declare(strict_types=1);

namespace Kabuto\Notifications;

final class ArrayNotificationFailureStore implements NotificationFailureStore
{
    /** @var list<array{notifiable:mixed,notification:string,channel:string,attempts:int,error:string,failed_at:int}> */
    private array $failures = [];

    public function record(mixed $notifiable, Notification $notification, string $channel, int $attempts, \Throwable $failure): void
    {
        $this->failures[] = [
            'notifiable' => $notifiable,
            'notification' => $notification::class,
            'channel' => $channel,
            'attempts' => $attempts,
            'error' => $failure->getMessage(),
            'failed_at' => time(),
        ];
    }

    /** @return list<array{notifiable:mixed,notification:string,channel:string,attempts:int,error:string,failed_at:int}> */
    public function failures(): array
    {
        return $this->failures;
    }
}
