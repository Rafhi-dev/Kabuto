<?php

declare(strict_types=1);

namespace Kabuto\Common;

/**
 * HTTP status code constants, so handlers never use magic numbers.
 */
final class HttpStatus
{
    public const OK = 200;
    public const CREATED = 201;
    public const ACCEPTED = 202;
    public const NO_CONTENT = 204;
    public const BAD_REQUEST = 400;
    public const UNAUTHORIZED = 401;
    public const FORBIDDEN = 403;
    public const NOT_FOUND = 404;
    public const CONFLICT = 409;
    public const UNPROCESSABLE = 422;
    public const INTERNAL_ERROR = 500;
}
