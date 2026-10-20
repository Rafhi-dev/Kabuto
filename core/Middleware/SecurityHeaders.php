<?php

declare(strict_types=1);

namespace Kabuto\Middleware;

use Kabuto\Http\Request;
use Kabuto\Http\Response;

final class SecurityHeaders
{
    public function __construct(
        private readonly string $frameOptions = 'SAMEORIGIN',
        private readonly string $contentTypeOptions = 'nosniff',
        private readonly string $xssProtection = '1; mode=block',
        private readonly string $referrerPolicy = 'strict-origin-when-cross-origin',
        private readonly string $permissionsPolicy = 'camera=(), microphone=(), geolocation=()',
        private readonly ?string $contentSecurityPolicy = null,
        private readonly bool $hsts = false,
        private readonly int $hstsMaxAge = 31536000,
        private readonly bool $hstsSubDomains = true,
    ) {
    }

    public function __invoke(Request $req, Response $res, callable $next): Response
    {
        /** @var Response $response */
        $response = $next($req, $res);

        if ($this->contentTypeOptions !== '') {
            $response->header('X-Content-Type-Options', $this->contentTypeOptions);
        }

        if ($this->frameOptions !== '') {
            $response->header('X-Frame-Options', $this->frameOptions);
        }

        if ($this->xssProtection !== '') {
            $response->header('X-XSS-Protection', $this->xssProtection);
        }

        if ($this->referrerPolicy !== '') {
            $response->header('Referrer-Policy', $this->referrerPolicy);
        }

        if ($this->permissionsPolicy !== '') {
            $response->header('Permissions-Policy', $this->permissionsPolicy);
        }

        if ($this->contentSecurityPolicy !== null && $this->contentSecurityPolicy !== '') {
            $response->header('Content-Security-Policy', $this->contentSecurityPolicy);
        }

        if ($this->hsts) {
            $hstsValue = 'max-age=' . $this->hstsMaxAge;
            if ($this->hstsSubDomains) {
                $hstsValue .= '; includeSubDomains';
            }
            $response->header('Strict-Transport-Security', $hstsValue);
        }

        return $response;
    }
}
