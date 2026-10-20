<?php

declare(strict_types=1);

namespace Kabuto\Scheduler;

final class ArrayLockStore implements LockStore
{
    /** @var array<string,array{owner:string,expires_at:int}> */
    private array $locks = [];

    public function acquire(string $key, string $owner, int $ttlSeconds): bool
    {
        $now = time();
        if (isset($this->locks[$key]) && $this->locks[$key]['expires_at'] > $now) {
            return false;
        }

        if (isset($this->locks[$key]) && $this->locks[$key]['expires_at'] <= $now) {
            unset($this->locks[$key]);
        }
        $this->locks[$key] = ['owner' => $owner, 'expires_at' => $now + max(1, $ttlSeconds)];
        return true;
    }

    public function release(string $key, string $owner): bool
    {
        if (($this->locks[$key]['owner'] ?? null) !== $owner) {
            return false;
        }

        unset($this->locks[$key]);
        return true;
    }
}
