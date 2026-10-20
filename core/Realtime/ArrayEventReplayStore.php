<?php

declare(strict_types=1);

namespace Kabuto\Realtime;

final class ArrayEventReplayStore implements EventReplayStore
{
    /** @var array<string, list<RealtimeMessage>> */
    private array $stored = [];

    /** @var array<string, int> */
    private array $sequences = [];

    private readonly string $token;

    public function __construct(private readonly int $capacity = 500, ?string $instanceToken = null)
    {
        $this->token = $instanceToken !== null && $instanceToken !== '' ? $instanceToken : substr(bin2hex(random_bytes(6)), 0, 8);
    }

    public function register(string $channel, RealtimeMessage $message): ?string
    {
        if ($this->capacity <= 0) return null;
        $sequence = ($this->sequences[$channel] ?? 0) + 1;
        $this->sequences[$channel] = $sequence;
        $id = $message->id ?? $sequence . '-' . $this->token;
        $this->stored[$channel][] = $message->id === null ? new RealtimeMessage($message->event, $message->data, $id, $message->retry) : $message;
        if (count($this->stored[$channel]) > $this->capacity) array_shift($this->stored[$channel]);
        return $id;
    }

    public function after(string $channel, string $lastEventId, int $limit = 100): array
    {
        $cursor = $this->parseCursor($lastEventId);
        if ($cursor === null) return [];
        $out = [];
        foreach ($this->stored[$channel] ?? [] as $message) {
            if ((int) $message->id <= $cursor) continue;
            $out[] = $message;
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    private function parseCursor(string $lastEventId): ?int
    {
        return preg_match('/^\d+/', $lastEventId) === 1 ? (int) $lastEventId : null;
    }
}
