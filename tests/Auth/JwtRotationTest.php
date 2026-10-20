<?php

declare(strict_types=1);

namespace Kabuto\Tests\Auth;

use Kabuto\Auth\ArrayTokenRevoker;
use Kabuto\Auth\InvalidTokenException;
use Kabuto\Auth\JwtManager;
use Kabuto\Auth\DatabaseTokenRevoker;
use PDO;
use Kabuto\Tests\TestCase;

final class JwtRotationTest extends TestCase
{
    public function testRefreshTokenRotatesAndOldTokenCannotBeReused(): void
    {
        $manager = new JwtManager('test-secret-key-32-chars-long-12345', revoker: new ArrayTokenRevoker());
        $refresh = $manager->issueRefresh(['sub' => '1']);
        $rotated = $manager->rotate($refresh);
        self::assertTrue(isset($rotated['access_token'], $rotated['refresh_token']));
        self::assertThrows(InvalidTokenException::class, fn () => $manager->rotate($refresh));
    }

    public function testRefreshTokenReuseRevokesTheEntireFamily(): void
    {
        $manager = new JwtManager('test-secret-key-32-chars-long-12345', revoker: new ArrayTokenRevoker());
        $first = $manager->issueRefresh(['sub' => '1']);
        $rotated = $manager->rotate($first);

        self::assertThrows(InvalidTokenException::class, fn () => $manager->rotate($first));
        self::assertThrows(InvalidTokenException::class, fn () => $manager->verify($rotated['refresh_token'], 'refresh'));
    }

    public function testOnlyOneConcurrentRotationCanConsumeRefreshToken(): void
    {
        $manager = new JwtManager('test-secret-key-32-chars-long-12345', revoker: new ArrayTokenRevoker());
        $refresh = $manager->issueRefresh(['sub' => '42']);
        $firstRotation = $manager->rotate($refresh);

        self::assertThrows(InvalidTokenException::class, fn () => $manager->rotate($refresh));
        self::assertThrows(InvalidTokenException::class, fn () => $manager->verify($firstRotation['access_token'], 'access'));
        self::assertThrows(InvalidTokenException::class, fn () => $manager->verify($firstRotation['refresh_token'], 'refresh'));
    }

    public function testSeparateManagersCannotRotateSamePersistentRefreshToken(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $first = new JwtManager('test-secret-key-32-chars-long-12345', revoker: new DatabaseTokenRevoker($pdo));
        $second = new JwtManager('test-secret-key-32-chars-long-12345', revoker: new DatabaseTokenRevoker($pdo));
        $original = $first->issueRefresh(['sub' => 'shared-user']);

        $firstPair = $first->rotate($original);
        self::assertThrows(InvalidTokenException::class, fn () => $second->rotate($original));
        self::assertThrows(InvalidTokenException::class, fn () => $first->verify($firstPair['access_token'], 'access'));
        self::assertThrows(InvalidTokenException::class, fn () => $second->verify($firstPair['refresh_token'], 'refresh'));
    }
}
