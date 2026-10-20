<?php

declare(strict_types=1);

namespace Kabuto\Exceptions;

/**
 * Thrown when request data fails validation. Rendered as HTTP 422.
 */
final class ValidationException extends HttpException
{
    /**
     * @param array<string,list<string>> $errors field => messages
     */
    public function __construct(
        private readonly array $errors,
        string $message = 'The given data was invalid.',
    ) {
        parent::__construct(422, $message, [
            'error' => $message,
            'errors' => $errors,
        ]);
    }

    /** @return array<string,list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }
}
