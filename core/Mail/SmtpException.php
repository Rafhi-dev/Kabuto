<?php

declare(strict_types=1);

namespace Kabuto\Mail;

use RuntimeException;

final class SmtpException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, public readonly ?int $smtpCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $smtpCode ?? 0, $previous);
    }
}
