<?php

declare(strict_types=1);

namespace Kabuto\Tests\Middleware;

use Kabuto\Auth\Gate;
use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Middleware\PolicyAuthorize;
use Kabuto\Tests\TestCase;

final class PolicyAuthorizeTest extends TestCase
{
    protected function tearDown(): void { Gate::flush(); }
    public function testPolicyMiddlewareResolvesModelAndContinues(): void
    {
        $model = new class { public int $owner = 5; };
        Gate::policy($model::class, new class { public function view(mixed $user, object $model): bool { return $user === 5; } });
        $request = new Request('GET', '/items/1'); $request->set('user', 5);
        $response = (new PolicyAuthorize('view', static fn () => $model))($request, new Response(), static fn ($req, $res) => $res->send('ok'));
        self::assertSame('ok', $response->getBody());
    }
}
