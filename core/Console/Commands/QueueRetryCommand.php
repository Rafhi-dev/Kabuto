<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use RuntimeException;

final class QueueRetryCommand extends QueueCommand
{
    public function name(): string { return 'queue:retry'; }
    public function description(): string { return 'Retry a failed queue job'; }
    public function handle(): int
    {
        $id = $this->argument(0);
        if ($id === null || !ctype_digit($id)) throw new RuntimeException('Usage: php kabuto queue:retry <id>');
        $this->queue()->retry((int) $id);
        $this->success('Queue job #' . $id . ' queued for retry.');
        return 0;
    }
}
