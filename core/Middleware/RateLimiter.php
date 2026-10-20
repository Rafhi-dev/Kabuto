<?php

declare(strict_types=1);

namespace Kabuto\Middleware;

use Closure;
use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Http\ErrorResponse;
use Kabuto\Storage\FileRateLimitStore;
use Kabuto\Storage\MemoryRateLimitStore;
use Kabuto\Storage\RateLimitStore;

final class RateLimiter
{
    /** @var array<string,array{count:int,reset:int}> */
    private static ?MemoryRateLimitStore $defaultMemoryStore = null;

    /**
     * @param int $maxAttempts Maximum allowed requests in decay window
     * @param int $decaySeconds Duration of window in seconds
     * @param (Closure(Request):string)|null $keyResolver Custom key generator (default: client IP)
     * @param string|null $storageDir Cache folder for CGI/shared hosting mode
     */
    public function __construct(
        private readonly int $maxAttempts = 60,
        private readonly int $decaySeconds = 60,
        private readonly ?Closure $keyResolver = null,
        private readonly ?string $storageDir = null,
        private readonly ?RateLimitStore $store = null,
    ) {
    }

    public function __invoke(Request $req, Response $res, callable $next): Response
    {
        $key = $this->resolveKey($req);
        $state = $this->store()->hit($key, $this->decaySeconds);
        $now = time();

        $remaining = max(0, $this->maxAttempts - $state['count']);
        $reset = $state['reset'];
        $retryAfter = max(1, $reset - $now);

        if ($state['count'] > $this->maxAttempts) {
            $response = ErrorResponse::fromThrowable(new \Kabuto\Exceptions\HttpException(429, sprintf('Too many requests. Please try again in %d seconds.', $retryAfter)), (string) ($req->get('request_id') ?? 'req_' . bin2hex(random_bytes(16))))
                ->status(429)
                ->header('X-RateLimit-Limit', (string) $this->maxAttempts)
                ->header('X-RateLimit-Remaining', '0')
                ->header('X-RateLimit-Reset', (string) $reset)
                ->header('Retry-After', (string) $retryAfter)
                ;
            return $response;
        }

        /** @var Response $response */
        $response = $next($req, $res);

        $response->header('X-RateLimit-Limit', (string) $this->maxAttempts)
            ->header('X-RateLimit-Remaining', (string) $remaining)
            ->header('X-RateLimit-Reset', (string) $reset);

        return $response;
    }

    private function resolveKey(Request $req): string
    {
        if ($this->keyResolver !== null) {
            return ($this->keyResolver)($req);
        }

        return $req->ip() . '|' . $req->path();
    }

    /**
     * @return array{count:int,reset:int}
     */
    private function store(): RateLimitStore
    {
        if ($this->store !== null) return $this->store;
        if ($this->storageDir !== null || PHP_SAPI !== 'cli') {
            return new FileRateLimitStore($this->storageDir ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kabuto_rate_limit'));
        }
        return self::$defaultMemoryStore ??= new MemoryRateLimitStore();
    }

    /**
     * Reset in-memory rate limits (useful for testing).
     */
    public static function clearMemory(): void
    {
        self::$defaultMemoryStore = null;
    }
}
