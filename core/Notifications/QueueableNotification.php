<?php

declare(strict_types=1);

namespace Kabuto\Notifications;

/** Opt-in delivery policy carried with a queued notification. */
interface QueueableNotification extends Notification
{
    public function attempts(): int;

    /** Per-channel retry delay in milliseconds. */
    public function retryDelayMs(): int;

    /** Optional runtime condition to suppress a channel for this recipient. */
    public function shouldSend(mixed $notifiable, string $channel): bool;
}
