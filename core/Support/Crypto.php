<?php

declare(strict_types=1);

namespace Kabuto\Support;

final class Crypto
{
    /**
     * Encrypt a string using AES-256-CBC with HMAC-SHA256 authenticated encryption.
     */
    public static function encrypt(string $value): string
    {
        return \encrypt($value);
    }

    /**
     * Decrypt a payload produced by encrypt().
     */
    public static function decrypt(string $payload): string
    {
        return \decrypt($payload);
    }

    /**
     * Generate a cryptographically secure random string.
     */
    public static function randomString(int $length = 32): string
    {
        $bytes = random_bytes((int) ceil($length / 2));
        return substr(bin2hex($bytes), 0, $length);
    }

    /**
     * Compute HMAC-SHA256 signature using APP_KEY (or provided key).
     */
    public static function hmac(string $data, ?string $key = null): string
    {
        $secret = $key ?? \appKey();
        return hash_hmac('sha256', $data, $secret);
    }
}
