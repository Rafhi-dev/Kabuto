<?php

declare(strict_types=1);

namespace Kabuto\Storage;

interface RateLimitStore
{
    /** @return array{count:int,reset:int} */
    public function hit(string $key, int $decaySeconds): array;
}
