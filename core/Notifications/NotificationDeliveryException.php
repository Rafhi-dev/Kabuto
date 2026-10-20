<?php

declare(strict_types=1);

namespace Kabuto\Notifications;

use RuntimeException;

final class NotificationDeliveryException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $channel,
        public readonly int $attempts,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
