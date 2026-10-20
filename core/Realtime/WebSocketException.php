<?php

declare(strict_types=1);

namespace Kabuto\Realtime;

final class WebSocketException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $response = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
