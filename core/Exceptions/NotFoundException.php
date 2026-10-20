<?php

declare(strict_types=1);

namespace Kabuto\Exceptions;

final class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Not Found')
    {
        parent::__construct(404, $message, ['error' => $message]);
    }
}
