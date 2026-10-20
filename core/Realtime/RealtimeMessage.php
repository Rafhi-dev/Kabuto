<?php

declare(strict_types=1);

namespace Kabuto\Realtime;

final readonly class RealtimeMessage
{
    public function __construct(
        public string $event,
        public mixed $data,
        public ?string $id = null,
        public ?int $retry = null,
    ) {}

    public function toSse(): string
    {
        $lines = ['event: ' . $this->event];
        if ($this->id !== null) $lines[] = 'id: ' . $this->id;
        if ($this->retry !== null) $lines[] = 'retry: ' . $this->retry;
        $json = json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new \RuntimeException('Unable to encode realtime message.');
        foreach (explode("\n", $json) as $line) $lines[] = 'data: ' . $line;
        return implode("\n", $lines) . "\n\n";
    }
}
