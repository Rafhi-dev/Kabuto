<?php

declare(strict_types=1);

namespace Kabuto\Support;

use RuntimeException;

final class Hash
{
    /**
     * Hash a plain-text password using Argon2id if available, fallback to Bcrypt.
     *
     * @param array<string,mixed> $options
     */
    public static function make(string $value, array $options = []): string
    {
        $algo = self::algo();
        $hash = password_hash($value, $algo, $options);

        if ($hash === false) {
            throw new RuntimeException('Failed to hash password.');
        }

        return $hash;
    }

    /**
     * Verify that a plain-text value matches a hash.
     */
    public static function verify(string $value, string $hashedValue): bool
    {
        if ($hashedValue === '') {
            return false;
        }

        return password_verify($value, $hashedValue);
    }

    /**
     * Check if a hash needs to be re-hashed against current algorithm or options.
     *
     * @param array<string,mixed> $options
     */
    public static function needsRehash(string $hashedValue, array $options = []): bool
    {
        return password_needs_rehash($hashedValue, self::algo(), $options);
    }

    /**
     * Primary hashing algorithm: PASSWORD_ARGON2ID when supported, otherwise PASSWORD_BCRYPT.
     */
    public static function algo(): string|int|null
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return PASSWORD_ARGON2ID;
        }

        return PASSWORD_BCRYPT;
    }
}
