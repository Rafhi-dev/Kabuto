<?php

declare(strict_types=1);

namespace Kabuto\Tests\Http;

use Kabuto\Http\Request;
use Kabuto\Tests\TestCase;

final class TrustedProxyTest extends TestCase
{
    public function testForwardedHeadersAreIgnoredByDefault(): void
    {
        $request = new Request('GET', '/', [], [], ['x-forwarded-for' => '203.0.113.9', 'x-forwarded-proto' => 'https'], ['REMOTE_ADDR' => '10.0.0.2']);
        self::assertSame('10.0.0.2', $request->ip());
        self::assertFalse($request->isSecure());
    }

    public function testForwardedHeadersCanBeEnabledForTrustedProxy(): void
    {
        $request = new Request('GET', '/', [], [], ['x-forwarded-for' => '203.0.113.9', 'x-forwarded-proto' => 'https'], ['REMOTE_ADDR' => '10.0.0.2'], [], null, [], ['10.0.0.0/24']);
        self::assertSame('203.0.113.9', $request->ip());
        self::assertTrue($request->isSecure());
    }
}
