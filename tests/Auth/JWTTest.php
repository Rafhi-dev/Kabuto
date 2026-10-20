<?php

declare(strict_types=1);

namespace Kabuto\Tests\Auth;

use Kabuto\Auth\ExpiredTokenException;
use Kabuto\Auth\InvalidTokenException;
use Kabuto\Auth\JWT;
use Kabuto\Tests\TestCase;

final class JWTTest extends TestCase
{
    private const SECRET = 'test-secret-key-32-chars-long-12345';

    private ?string $backupEnv = null;
    private ?string $backupServer = null;

    protected function setUp(): void
    {
        $this->backupEnv = $_ENV['JWT_SECRET'] ?? null;
        $this->backupServer = $_SERVER['JWT_SECRET'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->backupEnv === null) {
            unset($_ENV['JWT_SECRET']);
        } else {
            $_ENV['JWT_SECRET'] = $this->backupEnv;
        }

        if ($this->backupServer === null) {
            unset($_SERVER['JWT_SECRET']);
        } else {
            $_SERVER['JWT_SECRET'] = $this->backupServer;
        }
    }

    public function testEncodeAndDecode(): void
    {
        $payload = [
            'sub' => 42,
            'name' => 'John Doe',
            'role' => 'admin',
        ];

        $token = JWT::sign($payload, self::SECRET, 1800);
        self::assertTrue(is_string($token));
        self::assertCount(3, explode('.', $token));

        $decoded = JWT::decode($token, self::SECRET);
        self::assertSame(42, $decoded['sub']);
        self::assertSame('John Doe', $decoded['name']);
        self::assertSame('admin', $decoded['role']);
        self::assertTrue(isset($decoded['iat']));
        self::assertTrue(isset($decoded['exp']));
        self::assertSame($decoded['iat'] + 1800, $decoded['exp']);
    }

    public function testTamperedSignatureFails(): void
    {
        $token = JWT::sign(['sub' => 1], self::SECRET);
        $parts = explode('.', $token);

        // Tamper with payload
        $parts[1] = JWT::base64UrlEncode('{"sub":999}');
        $tamperedToken = implode('.', $parts);

        self::assertNull(JWT::verify($tamperedToken, self::SECRET));

        $caught = false;
        try {
            JWT::decode($tamperedToken, self::SECRET);
        } catch (InvalidTokenException) {
            $caught = true;
        }
        self::assertTrue($caught);
    }

    public function testExpiredTokenThrows(): void
    {
        // Issue token that expired 10 seconds ago
        $token = JWT::encode(['sub' => 1], self::SECRET, -10);

        self::assertNull(JWT::verify($token, self::SECRET));

        $caught = false;
        try {
            JWT::decode($token, self::SECRET);
        } catch (ExpiredTokenException) {
            $caught = true;
        }
        self::assertTrue($caught);
    }

    public function testNotBeforeClaim(): void
    {
        $token = JWT::encode(['sub' => 1, 'nbf' => time() + 100], self::SECRET, 3600);

        self::assertNull(JWT::verify($token, self::SECRET));

        $caught = false;
        try {
            JWT::decode($token, self::SECRET);
        } catch (InvalidTokenException) {
            $caught = true;
        }
        self::assertTrue($caught);
    }

    public function testLeewayAllowsSlightSkew(): void
    {
        $token = JWT::encode(['sub' => 1], self::SECRET, -2);

        // Without leeway, it fails
        self::assertNull(JWT::verify($token, self::SECRET));

        // With 5s leeway, it succeeds
        $decoded = JWT::decode($token, self::SECRET, leeway: 5);
        self::assertSame(1, $decoded['sub']);
    }

    public function testAutoResolvesSecretFromEnv(): void
    {
        $_ENV['JWT_SECRET'] = self::SECRET;

        $token = JWT::sign(['user' => 'mach']);
        $decoded = JWT::decode($token);

        self::assertSame('mach', $decoded['user']);
    }

    public function testGlobalHelpers(): void
    {
        $_ENV['JWT_SECRET'] = self::SECRET;

        $token = jwt_sign(['id' => 99], ttl: 600);
        self::assertTrue(is_string($token));

        $verified = jwt_verify($token);
        self::assertTrue(is_array($verified));
        self::assertSame(99, $verified['id']);

        $decoded = jwt_decode($token);
        self::assertSame(99, $decoded['id']);
    }
}
