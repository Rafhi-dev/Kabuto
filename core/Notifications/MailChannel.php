<?php

declare(strict_types=1);

namespace Kabuto\Notifications;

use Kabuto\Mail\MailManager;
use Kabuto\Mail\MailMessage;
use RuntimeException;

final class MailChannel implements NotificationChannel
{
    public function __construct(private readonly MailManager $mailer) {}

    public function send(mixed $notifiable, Notification $notification): void
    {
        if (!method_exists($notification, 'toMail')) throw new RuntimeException($notification::class . ' must define toMail().');
        $message = $notification->toMail($notifiable);
        if (!$message instanceof MailMessage) throw new RuntimeException('toMail() must return a MailMessage.');
        $this->mailer->send($message);
    }
}
