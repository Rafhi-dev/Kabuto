<?php

declare(strict_types=1);

namespace Kabuto\Storage;

final class MemoryRateLimitStore implements RateLimitStore
{
    /** @var array<string,array{count:int,reset:int}> */
    private array $items = [];

    public function hit(string $key, int $decaySeconds): array
    {
        $now = time();
        if (!isset($this->items[$key]) || $this->items[$key]['reset'] <= $now) {
            return $this->items[$key] = ['count' => 1, 'reset' => $now + $decaySeconds];
        }
        return $this->items[$key] = ['count' => $this->items[$key]['count'] + 1, 'reset' => $this->items[$key]['reset']];
    }
}
