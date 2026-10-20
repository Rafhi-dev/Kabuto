<?php

declare(strict_types=1);

namespace Kabuto\Middleware;

use Kabuto\Exceptions\BadRequestException;
use Kabuto\Http\Request;
use Kabuto\Http\Response;
use JsonException;

final class JsonBody
{
    /**
     * @param callable(Request):string|null $reader overrides php://input (testing)
     */
    public function __construct(
        private readonly mixed $reader = null,
        private readonly int $maxBytes = 2_097_152,
        private readonly int $maxDepth = 32,
    )
    {
    }

    public function __invoke(Request $request, Response $response, callable $next): Response
    {
        $contentType = $request->header('content-type', '') ?? '';

        if (str_contains($contentType, 'application/json')) {
            $raw = $this->reader !== null ? ($this->reader)($request) : $request->rawBody();
            if ($this->maxBytes > 0 && strlen($raw) > $this->maxBytes) {
                throw new BadRequestException('JSON body exceeds the configured size limit.');
            }
            if ($raw !== '') {
                try {
                    $decoded = json_decode($raw, true, $this->maxDepth, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new BadRequestException('Invalid JSON body.');
                }
                if (!is_array($decoded)) {
                    throw new BadRequestException('Invalid JSON body.');
                }
                $request->setBody($decoded);
            }
        }

        return $next($request, $response);
    }
}
