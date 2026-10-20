<?php

declare(strict_types=1);

namespace Kabuto\Tests\Auth;

use Kabuto\Auth\Gate;
use Kabuto\Exceptions\ForbiddenException;
use Kabuto\Tests\TestCase;

final class PolicyTest extends TestCase
{
    protected function tearDown(): void { Gate::flush(); }
    public function testObjectPolicyCanAuthorizeModel(): void
    {
        $model = new class { public int $owner = 7; };
        Gate::policy($model::class, new class {
            public function update(mixed $user, object $model): bool { return $user === 7 && $model->owner === 7; }
        });
        Gate::forUser(7)->authorizeModel('update', $model);
        self::assertTrue(true);
        self::assertThrows(ForbiddenException::class, fn () => Gate::forUser(8)->authorizeModel('update', $model));
    }
}
