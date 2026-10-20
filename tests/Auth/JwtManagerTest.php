<?php

declare(strict_types=1);

namespace Kabuto\Tests\Auth;

use Kabuto\Auth\ArrayTokenRevoker;
use Kabuto\Auth\InvalidTokenException;
use Kabuto\Auth\JwtManager;
use Kabuto\Tests\TestCase;
use RuntimeException;

final class JwtManagerTest extends TestCase
{
    public function testIssuerAudienceRefreshAndRevocation(): void
    {
        $revoker = new ArrayTokenRevoker();
        $manager = new JwtManager('test-secret-key-32-chars-long-12345', 60, 120, 'kabuto', 'api', $revoker);
        $token = $manager->issueAccess(['sub' => '42']);
        $payload = $manager->verify($token, 'access');
        self::assertSame('kabuto', $payload['iss']);
        self::assertSame('api', $payload['aud']);
        self::assertTrue(isset($payload['jti']));
        $manager->revoke($token);
        self::assertThrows(InvalidTokenException::class, fn () => $manager->verify($token));
    }

    public function testLeewayIsAppliedAndMaximumTtlIsEnforced(): void
    {
        $manager = new JwtManager('test-secret-key-32-chars-long-12345', 120, 120, null, null, null, 5, 60, 120);
        $token = \Kabuto\Auth\JWT::encode(['sub' => '42', 'typ' => 'access', 'exp' => time() - 2], 'test-secret-key-32-chars-long-12345', 0);
        self::assertSame('42', $manager->verify($token, 'access')['sub']);
        self::assertThrows(RuntimeException::class, static fn (): string => $manager->issueAccess(['sub' => '42']));
    }
}
