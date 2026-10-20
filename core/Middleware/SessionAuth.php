<?php

declare(strict_types=1);

namespace Kabuto\Middleware;

use Kabuto\Auth\SessionManager;
use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Http\ErrorResponse;
use Kabuto\Exceptions\HttpException;

final class SessionAuth
{
    public function __construct(private readonly SessionManager $sessions) {}

    public function __invoke(Request $request, Response $response, callable $next): Response
    {
        $this->sessions->start($request);
        if ($this->sessions->user() === null) {
            return ErrorResponse::fromThrowable(
                new HttpException(401, 'Authentication required.'),
                (string) ($request->get('request_id') ?? 'req_' . bin2hex(random_bytes(16))),
            );
        }
        $request->set('user', $this->sessions->user());
        return $this->sessions->commit($next($request, $response));
    }
}
