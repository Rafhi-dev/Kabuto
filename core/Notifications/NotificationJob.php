<?php

declare(strict_types=1);

namespace Kabuto\Notifications;

use Kabuto\Queue\Job;
use Kabuto\Queue\QueueableJob;

final class NotificationJob implements QueueableJob
{
    public function __construct(
        private readonly mixed $notifiable,
        private readonly Notification $notification,
    ) {
    }

    public function handle(): void
    {
        $app = \Kabuto\Application::current();
        $manager = $app->container()->get(NotificationManager::class);
        if (!$manager instanceof NotificationManager) {
            throw new \RuntimeException('NotificationManager is not available in the active application container.');
        }
        $manager->sendQueued($this->notifiable, $this->notification);
    }

    public function maxAttempts(): int
    {
        if ($this->notification instanceof QueueableNotification) {
            return max(1, $this->notification->attempts());
        }
        try {
            $manager = \Kabuto\Application::current()->container()->get(NotificationManager::class);
            return $manager instanceof NotificationManager ? max(1, $manager->queueAttempts()) : 1;
        } catch (\Throwable) {
            return 1;
        }
    }

    public function retryDelay(): int
    {
        if ($this->notification instanceof QueueableNotification) {
            $milliseconds = max(0, $this->notification->retryDelayMs());
        } else {
            try {
                $manager = \Kabuto\Application::current()->container()->get(NotificationManager::class);
                $milliseconds = $manager instanceof NotificationManager ? $manager->queueRetryDelaySeconds() * 1000 : 1000;
            } catch (\Throwable) {
                $milliseconds = 1000;
            }
        }
        return (int) ceil($milliseconds / 1000);
    }

    /**
     * Queue workers call this after their final retry. Failure hooks are
     * best-effort; they must not obscure the original delivery failure.
     */
    public function failed(\Throwable $failure): void
    {
        try {
            $app = \Kabuto\Application::current();
            $manager = $app->container()->get(NotificationManager::class);
            if ($manager instanceof NotificationManager && $manager->failures() !== null) {
                foreach ($this->notification->via($this->notifiable) as $channel) {
                    $manager->failures()->record($this->notifiable, $this->notification, $channel, $this->maxAttempts(), $failure);
                }
            } elseif (is_array($config = $app->config()->get('notifications', [])) && ($config['store'] ?? 'memory') === 'database') {
                $pdo = $app->container()->get(\Kabuto\Database\ConnectionManager::class)->connection();
                $store = new DatabaseNotificationFailureStore($pdo);
                foreach ($this->notification->via($this->notifiable) as $channel) {
                    $store->record($this->notifiable, $this->notification, $channel, $this->maxAttempts(), $failure);
                }
            }
        } catch (\Throwable) {
            // Preserve the original queue failure.
        }
    }
}
