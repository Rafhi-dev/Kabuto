<?php

declare(strict_types=1);

namespace Kabuto\Realtime;

final readonly class SseStreamEnd
{
    public function __construct(
        public string $reason,
        public float $seconds,
        public int $delivered,
        public int $heartbeats,
    ) {}
}
