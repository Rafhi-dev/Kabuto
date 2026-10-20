<?php

declare(strict_types=1);

namespace Kabuto\Queue;

interface Job
{
    public function handle(): void;
}
