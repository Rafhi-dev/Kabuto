<?php

declare(strict_types=1);

namespace Kabuto\Exceptions;

final class MethodNotAllowedException extends HttpException
{
    public function __construct(string $message = 'Method Not Allowed')
    {
        parent::__construct(405, $message, ['error' => $message]);
    }
}
