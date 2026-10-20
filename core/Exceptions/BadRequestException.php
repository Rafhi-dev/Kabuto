<?php

declare(strict_types=1);

namespace Kabuto\Exceptions;

final class BadRequestException extends HttpException
{
    public function __construct(string $message = 'Bad Request')
    {
        parent::__construct(400, $message, ['error' => $message]);
    }
}
