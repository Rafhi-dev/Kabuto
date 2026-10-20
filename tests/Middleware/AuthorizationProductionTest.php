<?php

declare(strict_types=1);

namespace Kabuto\Tests\Middleware;

use Kabuto\Auth\Gate;
use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Middleware\Authorization;
use Kabuto\Tests\TestCase;

final class AuthorizationProductionTest extends TestCase
{
    protected function tearDown(): void { Gate::flush(); }

    public function testDenyByDefaultRejectsUnauthenticatedUnmarkedRoute(): void
    {
        $request = new Request('GET', '/private');
        $response = (new Authorization())($request, new Response(), static fn (): Response => (new Response())->send('ok'));
        self::assertSame(401, $response->getStatus());
    }

    public function testPublicMetadataBypassesDefaultGuard(): void
    {
        $request = new Request('GET', '/health');
        $request->set('_route_public', true);
        $response = (new Authorization())($request, new Response(), static fn (Request $request, Response $response): Response => $response->send('ok'));
        self::assertSame('ok', $response->getBody());
    }
}
