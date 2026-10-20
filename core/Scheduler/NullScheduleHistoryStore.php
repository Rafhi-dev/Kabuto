<?php

declare(strict_types=1);

namespace Kabuto\Scheduler;

final class NullScheduleHistoryStore implements ScheduleHistoryStore
{
    public function record(array $entry): void
    {
    }

    public function recent(int $limit = 20): array
    {
        return [];
    }
}
