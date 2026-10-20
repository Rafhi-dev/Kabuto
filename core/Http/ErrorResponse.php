<?php

declare(strict_types=1);

namespace Kabuto\Http;

use Kabuto\Exceptions\HttpException;
use Kabuto\Exceptions\ValidationException;
use Throwable;

final class ErrorResponse
{
    public static function fromThrowable(Throwable $exception, string $requestId, bool $debug = false): Response
    {
        $status = $exception instanceof HttpException ? $exception->getStatus() : 500;
        $code = self::code($exception, $status);
        $details = [];

        if ($exception instanceof ValidationException) {
            $details = ['fields' => $exception->errors()];
        } elseif ($debug && $exception instanceof HttpException && $exception->getPayload() !== []) {
            $details = $exception->getPayload();
        }

        $error = [
            'code' => $code,
            'message' => $debug || $exception instanceof HttpException
                ? ($exception->getMessage() !== '' ? $exception->getMessage() : self::message($status))
                : 'An internal server error occurred.',
            'request_id' => $requestId,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        if ($debug && !($exception instanceof HttpException)) {
            $error['details'] = [
                'exception' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => array_slice(explode("\n", $exception->getTraceAsString()), 0, 30),
            ];
        }

        return (new Response())->status($status)->header('X-Request-ID', $requestId)->json([
            'success' => false,
            'error' => $error,
        ]);
    }

    private static function code(Throwable $exception, int $status): string
    {
        if ($exception instanceof ValidationException) {
            return 'VALIDATION_FAILED';
        }

        return match ($status) {
            400 => 'BAD_REQUEST', 401 => 'UNAUTHENTICATED', 403 => 'FORBIDDEN',
            404 => 'NOT_FOUND', 405 => 'METHOD_NOT_ALLOWED', 422 => 'VALIDATION_FAILED',
            429 => 'RATE_LIMITED', default => $status >= 500 ? 'INTERNAL_ERROR' : 'HTTP_ERROR',
        };
    }

    private static function message(int $status): string
    {
        return match ($status) {
            400 => 'Bad request.', 401 => 'Authentication required', 403 => 'Forbidden.',
            404 => 'Not found.', 405 => 'Method not allowed.', 422 => 'The given data was invalid.',
            429 => 'Too many requests.', default => 'An internal server error occurred.',
        };
    }
}
