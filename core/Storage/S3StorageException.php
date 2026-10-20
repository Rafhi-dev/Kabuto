<?php

declare(strict_types=1);

namespace Kabuto\Storage;

use RuntimeException;

final class S3StorageException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $operation,
        public readonly int $status,
        public readonly ?string $serviceCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function retryable(): bool
    {
        return $this->status === 0 || in_array($this->status, [408, 425, 429, 500, 502, 503, 504], true);
    }
}
