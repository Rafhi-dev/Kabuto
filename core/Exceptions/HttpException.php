<?php

declare(strict_types=1);

namespace Kabuto\Exceptions;

use RuntimeException;

class HttpException extends RuntimeException
{
    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(
        private readonly int $status,
        string $message = '',
        private readonly array $payload = [],
    ) {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /** @return array<string,mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }
}
