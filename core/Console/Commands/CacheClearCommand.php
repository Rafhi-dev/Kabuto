<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Cache\Cache;
use Kabuto\Console\Command;

final class CacheClearCommand extends Command
{
    public function name(): string { return 'cache:clear'; }
    public function description(): string { return 'Clear the application cache'; }
    public function handle(): int { Cache::clear(); $this->success('Application cache cleared.'); return 0; }
}
