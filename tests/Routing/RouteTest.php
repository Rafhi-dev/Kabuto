<?php

declare(strict_types=1);

namespace Kabuto\Tests\Routing;

use Kabuto\Routing\Route;
use Kabuto\Tests\TestCase;

final class RouteTest extends TestCase
{
    public function testStaticPatternMatches(): void
    {
        $route = new Route('GET', '/users', 'handler');

        self::assertSame([], $route->matches('/users'));
        self::assertNull($route->matches('/users/1'));
        self::assertNull($route->matches('/other'));
    }

    public function testNamedParameterMatchesSingleSegment(): void
    {
        $route = new Route('GET', '/users/:id', 'handler');

        self::assertSame(['id' => '42'], $route->matches('/users/42'));
        self::assertNull($route->matches('/users/42/posts'));
        self::assertNull($route->matches('/users/'));
    }

    public function testMultipleParameters(): void
    {
        $route = new Route('GET', '/users/:userId/posts/:postId', 'handler');

        self::assertSame(
            ['userId' => '7', 'postId' => '9'],
            $route->matches('/users/7/posts/9'),
        );
    }

    public function testWildcardMatchesRestOfPath(): void
    {
        $route = new Route('GET', '/files/*', 'handler');

        self::assertSame(['wildcard' => 'a/b/c.txt'], $route->matches('/files/a/b/c.txt'));
        self::assertSame(['wildcard' => ''], $route->matches('/files/'));
    }

    public function testRepeatedWildcardsAreNumbered(): void
    {
        $route = new Route('GET', '/a/*/*', 'handler');
        $params = $route->matches('/a/x/y/p/q');

        // Wildcards are greedy: the first one consumes as much as possible
        // while still allowing the rest of the pattern to match.
        self::assertSame('x/y/p', $params['wildcard'] ?? null);
        self::assertSame('q', $params['wildcard2'] ?? null);
    }

    public function testDuplicateNamedParameterThrows(): void
    {
        self::assertThrows(
            \InvalidArgumentException::class,
            static fn () => new Route('GET', '/users/:id/posts/:id', 'handler'),
        );
    }

    public function testParametersAreUrlDecoded(): void
    {
        $route = new Route('GET', '/hello/:name', 'handler');

        self::assertSame(['name' => 'a b'], $route->matches('/hello/a%20b'));
    }

    public function testSpecialCharsInPatternAreEscaped(): void
    {
        $route = new Route('GET', '/files/:name.json', 'handler');

        self::assertSame(['name' => 'report'], $route->matches('/files/report.json'));
        self::assertNull($route->matches('/files/reportXjson'));
    }

    public function testAccessors(): void
    {
        $route = new Route('POST', '/users', 'handler', ['mw']);

        self::assertSame('POST', $route->method());
        self::assertSame('/users', $route->pattern());
        self::assertSame('handler', $route->handler());
        self::assertSame(['mw'], $route->middleware());
    }
}
