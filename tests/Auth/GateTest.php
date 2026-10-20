<?php

declare(strict_types=1);

namespace Kabuto\Tests\Auth;

use Kabuto\Auth\Gate;
use Kabuto\Exceptions\ForbiddenException;
use Kabuto\Tests\TestCase;
use stdClass;

final class GateTest extends TestCase
{
    protected function setUp(): void
    {
        Gate::flush();
    }

    protected function tearDown(): void
    {
        Gate::flush();
    }

    public function testGateDefineAndAllows(): void
    {
        Gate::define('edit-post', function (?array $user, stdClass $post): bool {
            return $user !== null && $user['id'] === $post->userId;
        });

        $post = new stdClass();
        $post->userId = 42;

        $owner = ['id' => 42, 'name' => 'Owner'];
        $stranger = ['id' => 99, 'name' => 'Stranger'];

        self::assertTrue(Gate::forUser($owner)->allows('edit-post', $post));
        self::assertFalse(Gate::forUser($stranger)->allows('edit-post', $post));
        self::assertTrue(Gate::forUser($stranger)->denies('edit-post', $post));
    }

    public function testGateAuthorizeThrowsForbiddenException(): void
    {
        Gate::define('delete-account', function (?array $user): bool {
            return ($user['role'] ?? null) === 'admin';
        });

        $user = ['id' => 10, 'role' => 'user'];

        $caught = false;
        try {
            Gate::forUser($user)->authorize('delete-account');
        } catch (ForbiddenException) {
            $caught = true;
        }

        self::assertTrue($caught);
    }

    public function testGateBeforeCallbackBypass(): void
    {
        Gate::before(function (?array $user, string $ability): ?bool {
            if (($user['role'] ?? null) === 'superadmin') {
                return true; // Bypass any check
            }
            return null; // Continue to defined ability
        });

        Gate::define('access-restricted-area', function (): bool {
            return false;
        });

        $superadmin = ['id' => 1, 'role' => 'superadmin'];
        $regularUser = ['id' => 2, 'role' => 'user'];

        self::assertTrue(Gate::forUser($superadmin)->allows('access-restricted-area'));
        self::assertFalse(Gate::forUser($regularUser)->allows('access-restricted-area'));
    }

    public function testGlobalGateHelper(): void
    {
        Gate::define('view-metrics', function (?array $user): bool {
            return ($user['role'] ?? null) === 'analyst';
        });

        $instance = gate();
        self::assertTrue($instance instanceof Gate);

        // When user is not set, it denies
        self::assertFalse(gate('view-metrics'));
    }

    public function testGateResolvesUserFromActiveRequestInContainer(): void
    {
        Gate::define('edit-settings', function (?array $user): bool {
            return ($user['id'] ?? null) === 42;
        });

        $app = \Kabuto\Application::current();
        $request = new \Kabuto\Http\Request('GET', '/settings');
        $request->set('user', ['id' => 42]);
        $app->container()->instance(\Kabuto\Http\Request::class, $request);

        self::assertTrue(gate('edit-settings'));

        $app->container()->forgetInstance(\Kabuto\Http\Request::class);
        self::assertFalse(gate('edit-settings'));
    }
}
