<?php

declare(strict_types=1);

namespace Kabuto\Tests\Support;

use Kabuto\Support\Hash;
use Kabuto\Tests\TestCase;

final class HashTest extends TestCase
{
    public function testHashMakeAndVerify(): void
    {
        $password = 'secret-pass-123';
        $hashed = Hash::make($password);

        self::assertFalse($password === $hashed);
        self::assertTrue(Hash::verify($password, $hashed));
        self::assertFalse(Hash::verify('wrong-password', $hashed));
    }

    public function testGlobalHashHelpers(): void
    {
        $password = 'mypassword';
        $hash = hash_make($password);

        self::assertTrue(hash_verify($password, $hash));
        self::assertFalse(hash_verify('invalid', $hash));
    }
}
