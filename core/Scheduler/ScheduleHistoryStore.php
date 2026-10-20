<?php

declare(strict_types=1);

namespace Kabuto\Scheduler;

interface ScheduleHistoryStore
{
    /** @param array<string,mixed> $entry */
    public function record(array $entry): void;

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 20): array;
}
