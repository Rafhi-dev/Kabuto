<?php

declare(strict_types=1);

namespace Kabuto\Tests\Support;

use Kabuto\Tests\TestCase;

final class CryptoTest extends TestCase
{
    private ?string $backup = null;

    protected function setUp(): void
    {
        $this->backup = $_SERVER['APP_KEY'] ?? null;
        $_SERVER['APP_KEY'] = 'base64:' . base64_encode(random_bytes(32));
        $_ENV['APP_KEY'] = $_SERVER['APP_KEY'];
    }

    protected function tearDown(): void
    {
        if ($this->backup === null) {
            unset($_SERVER['APP_KEY'], $_ENV['APP_KEY']);
        } else {
            $_SERVER['APP_KEY'] = $this->backup;
            $_ENV['APP_KEY'] = $this->backup;
        }
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        if (!extension_loaded('openssl')) {
            self::assertTrue(true, 'openssl missing, skipped.');
            return;
        }

        $cipher = encrypt('hello world');

        self::assertFalse($cipher === 'hello world');
        self::assertSame('hello world', decrypt($cipher));
    }

    public function testCiphertextsDiffer(): void
    {
        if (!extension_loaded('openssl')) {
            self::assertTrue(true, 'openssl missing, skipped.');
            return;
        }

        self::assertFalse(encrypt('same') === encrypt('same'));
    }

    public function testTamperedPayloadThrows(): void
    {
        if (!extension_loaded('openssl')) {
            self::assertTrue(true, 'openssl missing, skipped.');
            return;
        }

        $cipher = encrypt('secret');
        $tampered = substr($cipher, 0, -4) . 'AAAA';

        self::assertThrows(
            \RuntimeException::class,
            static fn () => decrypt($tampered),
        );
    }

    public function testMissingKeyThrows(): void
    {
        unset($_SERVER['APP_KEY'], $_ENV['APP_KEY']);
        putenv('APP_KEY');

        // config() may still hold an old key; pass an explicit empty value path
        // by pointing config to empty through env fallback chain.
        $_SERVER['APP_KEY'] = '';

        self::assertThrows(
            \RuntimeException::class,
            static fn () => encrypt('x'),
        );
    }

    public function testCryptoClassMethods(): void
    {
        $random = \Kabuto\Support\Crypto::randomString(16);
        self::assertSame(16, strlen($random));

        $hmac = \Kabuto\Support\Crypto::hmac('test-data', 'secret-key');
        self::assertSame(64, strlen($hmac));
    }
}
