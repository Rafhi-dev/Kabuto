<?php

declare(strict_types=1);

namespace Kabuto\Realtime;

interface EventReplayStore
{
    /**
     * Retain a message for Last-Event-ID replay and assign a per-channel ordered id when the message has none.
     * @return string|null the id that was assigned, or null when replay retention is disabled
     */
    public function register(string $channel, RealtimeMessage $message): ?string;

    /** @return list<RealtimeMessage> messages retained after $lastEventId, in publish order (may be empty if the cursor is unknown or too old) */
    public function after(string $channel, string $lastEventId, int $limit = 100): array;
}
