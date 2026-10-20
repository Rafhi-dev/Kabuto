<?php

declare(strict_types=1);

namespace Kabuto\Tests\Auth;

use Kabuto\Auth\DatabaseTokenRevoker;
use Kabuto\Auth\FileTokenRevoker;
use Kabuto\Auth\RedisTokenRevoker;
use Kabuto\Tests\TestCase;
use PDO;

final class PersistentTokenRevokerTest extends TestCase
{
    public function testDatabaseRevokerPersistsAndUpdatesTokens(): void
    {
        $revoker = new DatabaseTokenRevoker(new PDO('sqlite::memory:'));
        $revoker->revoke('jti-1', time() + 60);
        self::assertTrue($revoker->isRevoked('jti-1'));
        $revoker->revoke('jti-1', time() + 120);
        self::assertTrue($revoker->isRevoked('jti-1'));
        self::assertFalse($revoker->isRevoked('missing'));
    }

    public function testDatabaseRefreshTokenConsumeIsOneTime(): void
    {
        $revoker = new DatabaseTokenRevoker(new PDO('sqlite::memory:'));
        self::assertTrue($revoker->consume('refresh-jti', 'family-1', time() + 60));
        self::assertFalse($revoker->consume('refresh-jti', 'family-1', time() + 60));
    }

    public function testDatabaseConsumeIsSharedAcrossIndependentInstances(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $first = new DatabaseTokenRevoker($pdo);
        $second = new DatabaseTokenRevoker($pdo);
        self::assertTrue($first->consume('shared-jti', 'shared-family', time() + 60));
        self::assertFalse($second->consume('shared-jti', 'shared-family', time() + 60));
    }

    public function testFileRevokerPersistsAcrossInstances(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kabuto-jwt-');
        $first = new FileTokenRevoker($path);
        $first->revoke('jti-file', time() + 60);
        self::assertTrue((new FileTokenRevoker($path))->isRevoked('jti-file'));
        @unlink($path);
    }

    public function testFileRefreshTokenConsumeIsOneTime(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kabuto-jwt-');
        $revoker = new FileTokenRevoker($path);
        self::assertTrue($revoker->consume('refresh-jti', 'family-1', time() + 60));
        self::assertFalse($revoker->consume('refresh-jti', 'family-1', time() + 60));
        @unlink($path);
        @unlink($path . '.lock');
    }

    public function testFileConsumeIsSharedAcrossIndependentInstances(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kabuto-jwt-share-' . bin2hex(random_bytes(5)) . '.json';
        $first = new FileTokenRevoker($path);
        $second = new FileTokenRevoker($path);
        self::assertTrue($first->consume('shared-jti', 'shared-family', time() + 60));
        self::assertFalse($second->consume('shared-jti', 'shared-family', time() + 60));
        @unlink($path);
        @unlink($path . '.lock');
    }

    public function testRedisRevokerUsesExpiringKeys(): void
    {
        $redis = new class {
            public array $values = [];
            public function setex(string $key, int $ttl, string $value): bool { $this->values[$key] = $value; return true; }
            public function get(string $key): string|false { return $this->values[$key] ?? false; }
            public function set(string $key, string $value, array $options = []): bool|string
            {
                if (in_array('nx', $options, true) && isset($this->values[$key])) return false;
                $this->values[$key] = $value;
                return 'OK';
            }
        };
        $revoker = new RedisTokenRevoker($redis, 'jwt:');
        $revoker->revoke('jti-redis', time() + 60);
        self::assertTrue($revoker->isRevoked('jti-redis'));
        self::assertFalse($revoker->isRevoked('missing'));
    }

    public function testRedisRefreshTokenConsumeIsOneTime(): void
    {
        $redis = new class {
            public array $values = [];
            public function get(string $key): string|false { return $this->values[$key] ?? false; }
            public function set(string $key, string $value, array $options = []): bool|string
            {
                if (in_array('nx', $options, true) && isset($this->values[$key])) return false;
                $this->values[$key] = $value;
                return 'OK';
            }
        };
        $revoker = new RedisTokenRevoker($redis, 'jwt:');
        self::assertTrue($revoker->consume('refresh-jti', 'family-1', time() + 60));
        self::assertFalse($revoker->consume('refresh-jti', 'family-1', time() + 60));
    }
}
