<?php

declare(strict_types=1);

namespace Kabuto\Realtime;

use Kabuto\Observability\MetricsRegistry;

final class RealtimeManager
{
    private ?EventReplayStore $replay = null;

    /** @var null|callable(string,RealtimeMessage):void */
    private $fanout = null;
    private ?MetricsRegistry $metrics = null;

    /** @param array<string,RealtimeChannel> $channels */
    public function __construct(private readonly array $channels = []) {}

    public function withReplay(EventReplayStore $replay): self
    {
        $copy = new self($this->channels);
        $copy->replay = $replay;
        $copy->fanout = $this->fanout;
        $copy->metrics = $this->metrics;
        return $copy;
    }

    public function withFanout(?callable $fanout): self
    {
        $copy = new self($this->channels);
        $copy->replay = $this->replay;
        $copy->fanout = $fanout;
        $copy->metrics = $this->metrics;
        return $copy;
    }

    public function withMetrics(?MetricsRegistry $metrics): self
    {
        $copy = new self($this->channels);
        $copy->replay = $this->replay;
        $copy->fanout = $this->fanout;
        $copy->metrics = $metrics;
        return $copy;
    }

    public function replay(): ?EventReplayStore
    {
        return $this->replay;
    }

    public function publish(string $channel, RealtimeMessage $message): void
    {
        $assigned = $this->replay?->register($channel, $message);
        if ($assigned !== null && $message->id === null) {
            $message = new RealtimeMessage($message->event, $message->data, $assigned, $message->retry);
        }
        $this->channel($channel)->publish($message);
        $this->metrics?->increment('kabuto_realtime_published_total', labels: ['channel' => $channel]);
        if ($this->fanout !== null) ($this->fanout)($channel, $message);
    }

    public function channel(string $channel): RealtimeChannel
    {
        return $this->channels[$channel] ?? throw new \RuntimeException("Realtime channel [{$channel}] is not configured.");
    }
}
