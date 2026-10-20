<?php

declare(strict_types=1);

namespace Kabuto\Notifications;

use Kabuto\Queue\DatabaseQueue;
use Kabuto\Queue\RedisQueue;
use RuntimeException;

final class NotificationManager
{
    /** @param array<string,NotificationChannel> $channels */
    public function __construct(
        private readonly array $channels = [],
        private readonly ?NotificationPreferenceStore $preferences = null,
        private readonly ?NotificationFailureStore $failures = null,
        private readonly int $attempts = 1,
        private readonly int $retryDelayMs = 100,
    ) {
        if ($attempts < 1 || $retryDelayMs < 0) {
            throw new \InvalidArgumentException('Notification attempts must be positive and retry delay cannot be negative.');
        }
    }

    /**
     * Send using all opted-in channels. Returns the count of successful
     * deliveries; channel failure details are recorded before the last error is
     * thrown, so queue retry/dead-letter handling remains available.
     */
    public function send(mixed $notifiable, Notification $notification): int
    {
        return $this->deliver($notifiable, $notification, false);
    }

    /** Queue workers own the outer retry schedule, so channel retry is single-attempt here. */
    public function sendQueued(mixed $notifiable, Notification $notification): int
    {
        return $this->deliver($notifiable, $notification, true);
    }

    private function deliver(mixed $notifiable, Notification $notification, bool $queued): int
    {
        $count = 0;
        $failures = [];
        foreach ($notification->via($notifiable) as $name) {
            if ($notification instanceof QueueableNotification && !$notification->shouldSend($notifiable, $name)) {
                continue;
            }
            if ($this->preferences !== null && !$this->preferences->allows($notifiable, $name, $notification)) {
                continue;
            }

            $channel = $this->channels[$name] ?? null;
            if (!$channel instanceof NotificationChannel) {
                throw new RuntimeException("Notification channel [{$name}] is not configured.");
            }

            $last = null;
            if ($queued) {
                $maxAttempts = 1;
                $retryDelay = 0;
            } elseif ($notification instanceof QueueableNotification) {
                $maxAttempts = max(1, $notification->attempts());
                $retryDelay = max(0, $notification->retryDelayMs());
            } else {
                $maxAttempts = $this->attempts;
                $retryDelay = $this->retryDelayMs;
            }
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                try {
                    $channel->send($notifiable, $notification);
                    $count++;
                    $last = null;
                    break;
                } catch (\Throwable $e) {
                    $last = $e;
                    if ($attempt < $maxAttempts && $retryDelay > 0) {
                        usleep($retryDelay * 1000 * $attempt);
                    }
                }
            }

            if ($last !== null) {
                if (!$queued) {
                    $this->failures?->record($notifiable, $notification, $name, $maxAttempts, $last);
                }
                $failures[] = new NotificationDeliveryException(
                    "Notification channel [{$name}] failed after {$maxAttempts} attempt(s): {$last->getMessage()}",
                    $name,
                    $maxAttempts,
                    $last,
                );
            }
        }

        if ($failures !== []) {
            throw $failures[0];
        }
        return $count;
    }

    public function dispatch(mixed $notifiable, Notification $notification, DatabaseQueue|RedisQueue $queue, int $delay = 0): int|string
    {
        return $queue->dispatch(new NotificationJob($notifiable, $notification), $delay);
    }

    /** @return array<string,NotificationChannel> */
    public function channels(): array
    {
        return $this->channels;
    }

    public function preferences(): ?NotificationPreferenceStore
    {
        return $this->preferences;
    }

    public function failures(): ?NotificationFailureStore
    {
        return $this->failures;
    }

    public function queueAttempts(): int
    {
        return $this->attempts;
    }

    public function queueRetryDelaySeconds(): int
    {
        return (int) ceil($this->retryDelayMs / 1000);
    }

    public function withPolicy(NotificationPreferenceStore $preferences, NotificationFailureStore $failures): self
    {
        return new self($this->channels, $preferences, $failures, $this->attempts, $this->retryDelayMs);
    }
}
