<?php

declare(strict_types=1);

namespace Kabuto\Exceptions;

final class ForbiddenException extends HttpException
{
    public function __construct(string $message = 'Forbidden resource')
    {
        parent::__construct(403, $message, ['statusCode' => 403, 'error' => 'Forbidden', 'message' => $message]);
    }
}
