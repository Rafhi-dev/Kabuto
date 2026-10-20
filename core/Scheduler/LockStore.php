<?php

declare(strict_types=1);

namespace Kabuto\Scheduler;

interface LockStore
{
    public function acquire(string $key, string $owner, int $ttlSeconds): bool;

    public function release(string $key, string $owner): bool;
}
