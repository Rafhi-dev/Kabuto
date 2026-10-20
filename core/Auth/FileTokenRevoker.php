<?php

declare(strict_types=1);

namespace Kabuto\Auth;

final class FileTokenRevoker implements AtomicTokenRevoker
{
    public function __construct(private readonly string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0700, true);
    }

    public function revoke(string $jti, int $expiresAt): void
    {
        $this->mutate(static function (array &$items) use ($jti, $expiresAt): void { $items[$jti] = $expiresAt; });
    }

    public function isRevoked(string $jti): bool
    {
        return $this->mutate(static function (array &$items) use ($jti): bool {
            foreach ($items as $id => $expiresAt) if ($expiresAt <= time()) unset($items[$id]);
            return isset($items[$jti]);
        });
    }

    public function consume(string $jti, string $family, int $expiresAt): bool
    {
        return $this->mutate(static function (array &$items) use ($jti, $family, $expiresAt): bool {
            foreach ($items as $id => $expiry) if ($expiry <= time()) unset($items[$id]);
            if (isset($items['family:' . $family]) || isset($items[$jti])) return false;
            $items[$jti] = $expiresAt;
            return true;
        });
    }

    public function revokeFamily(string $family, int $expiresAt): void
    {
        $this->revoke('family:' . $family, $expiresAt);
    }

    public function isFamilyRevoked(string $family): bool
    {
        return $this->isRevoked('family:' . $family);
    }

    /** @return array<string,int> */
    private function read(): array
    {
        $data = is_file($this->path) ? json_decode(file_get_contents($this->path) ?: '', true) : [];
        return is_array($data) ? array_map('intval', $data) : [];
    }

    /** @param array<string,int> $items */
    private function write(array $items): void
    {
        $tmp = $this->path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        file_put_contents($tmp, json_encode($items, JSON_THROW_ON_ERROR), LOCK_EX);
        if (!rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot atomically persist token revocation state.');
        }
        @chmod($this->path, 0600);
    }

    private function mutate(callable $callback): mixed
    {
        $lockPath = $this->path . '.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new \RuntimeException('Cannot lock token revocation store.');
        @chmod($lockPath, 0600);
        try {
            $items = $this->read();
            $result = $callback($items);
            $this->write($items);
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
