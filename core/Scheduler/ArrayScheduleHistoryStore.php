<?php

declare(strict_types=1);

namespace Kabuto\Scheduler;

final class ArrayScheduleHistoryStore implements ScheduleHistoryStore
{
    /** @var list<array<string,mixed>> */
    private array $entries = [];

    public function record(array $entry): void
    {
        $this->entries[] = $entry;
    }

    /** @return list<array<string,mixed>> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function recent(int $limit = 20): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Schedule history limit must be positive.');
        }
        return array_slice(array_reverse($this->entries), 0, $limit);
    }
}
