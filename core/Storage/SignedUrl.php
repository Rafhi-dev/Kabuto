<?php

declare(strict_types=1);

namespace Kabuto\Storage;

use Kabuto\Support\Crypto;

final class SignedUrl
{
    public static function create(string $path, int $expiresAt, string $method = 'GET'): string
    {
        $payload = $method . '|' . $path . '|' . $expiresAt;
        $signature = Crypto::hmac($payload);
        return $path . '?expires=' . $expiresAt . '&signature=' . rawurlencode($signature);
    }

    public static function verify(string $path, int $expiresAt, string $signature, string $method = 'GET'): bool
    {
        if ($expiresAt <= time()) return false;
        return hash_equals(Crypto::hmac($method . '|' . $path . '|' . $expiresAt), $signature);
    }
}
