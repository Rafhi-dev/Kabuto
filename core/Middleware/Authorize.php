<?php

declare(strict_types=1);

namespace Kabuto\Middleware;

use Kabuto\Http\Request;
use Kabuto\Http\Response;

final class Authorize
{
    /**
     * @param list<string> $roles                Allowed roles (user must have at least one).
     * @param list<string> $permissions          Required permissions.
     * @param bool         $requireAllPermissions If true, user must have ALL listed permissions.
     * @param list<string> $roleClaims           JWT/user payload keys to inspect for roles.
     * @param list<string> $permissionClaims     JWT/user payload keys to inspect for permissions.
     */
    public function __construct(
        private readonly array $roles = [],
        private readonly array $permissions = [],
        private readonly bool $requireAllPermissions = false,
        private readonly array $roleClaims = ['role', 'roles'],
        private readonly array $permissionClaims = ['permissions', 'perms', 'scope', 'scopes'],
    ) {
    }

    public function __invoke(Request $req, Response $res, callable $next): Response
    {
        $user = $req->user();

        // 1. Must be authenticated
        if ($user === null) {
            return (new Response())
                ->status(401)
                ->json([
                    'statusCode' => 401,
                    'error' => 'Unauthorized',
                    'message' => 'Authentication required.',
                ]);
        }

        // 2. Validate roles
        if ($this->roles !== []) {
            $userRoles = $this->extractRoles($user);
            $hasRole = array_intersect($this->roles, $userRoles) !== [];

            if (!$hasRole) {
                return (new Response())
                    ->status(403)
                    ->json([
                        'statusCode' => 403,
                        'error' => 'Forbidden',
                        'message' => 'Forbidden resource',
                    ]);
            }
        }

        // 3. Validate permissions
        if ($this->permissions !== []) {
            $userPermissions = $this->extractPermissions($user);

            // Superadmin wildcard '*' bypasses all permission checks
            if (!in_array('*', $userPermissions, true)) {
                if ($this->requireAllPermissions) {
                    $hasAll = array_diff($this->permissions, $userPermissions) === [];
                    if (!$hasAll) {
                        return (new Response())
                            ->status(403)
                            ->json([
                                'statusCode' => 403,
                                'error' => 'Forbidden',
                                'message' => 'Forbidden resource',
                            ]);
                    }
                } else {
                    $hasAny = array_intersect($this->permissions, $userPermissions) !== [];
                    if (!$hasAny) {
                        return (new Response())
                            ->status(403)
                            ->json([
                                'statusCode' => 403,
                                'error' => 'Forbidden',
                                'message' => 'Forbidden resource',
                            ]);
                    }
                }
            }
        }

        return $next($req, $res);
    }

    /**
     * @return list<string>
     */
    public function extractRoles(mixed $user): array
    {
        $roles = [];

        foreach ($this->roleClaims as $claim) {
            $val = is_array($user) ? ($user[$claim] ?? null) : ($user->{$claim} ?? null);
            if (is_string($val) && $val !== '') {
                $roles[] = $val;
            } elseif (is_array($val)) {
                foreach ($val as $r) {
                    if (is_string($r) && $r !== '') {
                        $roles[] = $r;
                    }
                }
            }
        }

        return array_values(array_unique($roles));
    }

    /**
     * @return list<string>
     */
    public function extractPermissions(mixed $user): array
    {
        $perms = [];

        foreach ($this->permissionClaims as $claim) {
            $val = is_array($user) ? ($user[$claim] ?? null) : ($user->{$claim} ?? null);
            if (is_string($val) && $val !== '') {
                // Support space-separated scopes: "read write admin"
                if (str_contains($val, ' ')) {
                    foreach (explode(' ', $val) as $part) {
                        if (trim($part) !== '') {
                            $perms[] = trim($part);
                        }
                    }
                } else {
                    $perms[] = $val;
                }
            } elseif (is_array($val)) {
                foreach ($val as $p) {
                    if (is_string($p) && $p !== '') {
                        $perms[] = $p;
                    }
                }
            }
        }

        return array_values(array_unique($perms));
    }
}
