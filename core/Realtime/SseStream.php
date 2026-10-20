<?php

declare(strict_types=1);

namespace Kabuto\Realtime;

/**
 * Streaming SSE connection: emits one chunk at a time through a write callback and never
 * accumulates the entire stream in memory.
 */
final class SseStream
{
    /** @var callable():float */
    private $now;

    /** @var callable(int):void */
    private $sleep;

    public function __construct(
        private readonly RealtimeChannel $channel,
        private readonly ?EventReplayStore $replay = null,
        private readonly int $heartbeatSeconds = 15,
        private readonly int $idleTimeoutSeconds = 0,
        private readonly int $maxDurationSeconds = 0,
        /** @var null|callable(string,mixed):bool $authorize receives (channel, subscriber context) */
        private $authorize = null,
        private readonly int $pollIntervalMs = 200,
        ?callable $now = null,
        ?callable $sleep = null,
    ) {
        $this->now = $now !== null ? $now(...) : static fn (): float => microtime(true);
        $this->sleep = $sleep !== null ? $sleep(...) : static function (int $ms): void { usleep($ms * 1000); };
    }

    /**
     * Stream the channel onto $write until the client disconnects (write returns false),
     * the deadline is reached, or $shouldStop turns true.
     * @param callable(string):bool $write must return false when the connection is gone
     * @param null|callable():bool $shouldStop
     */
    public function run(string $channelName, ?string $lastEventId, callable $write, mixed $context = null, ?callable $shouldStop = null): SseStreamEnd
    {
        if ($this->authorize !== null && !($this->authorize)($channelName, $context)) {
            throw new RealtimeAccessDeniedException("Subscriber is not authorized for realtime channel [{$channelName}].");
        }
        $writeFn = $write(...);
        $stopFn = $shouldStop !== null ? $shouldStop(...) : static fn (): bool => false;
        $start = ($this->now)();
        $lastWrite = $start;
        $delivered = 0;
        $heartbeats = 0;
        $nowFn = $this->now;
        $send = static function (string $chunk) use ($writeFn, $nowFn, &$lastWrite, &$delivered): bool {
            if (!$writeFn($chunk)) return false;
            $lastWrite = $nowFn();
            $delivered++;
            return true;
        };

        $cursor = 0;
        if ($lastEventId !== null && $this->replay !== null) {
            foreach ($this->replay->after($channelName, $lastEventId) as $message) {
                if (!$send($message->toSse())) return $this->finish($start, 'disconnect', $delivered, $heartbeats);
            }
            $cursor = count($this->channel->messages());
        }
        while (true) {
            $moment = ($this->now)();
            if ($stopFn()) return $this->finish($start, 'stop-requested', $delivered, $heartbeats);
            if ($this->maxDurationSeconds > 0 && $moment - $start >= $this->maxDurationSeconds) {
                return $this->finish($start, 'max-duration', $delivered, $heartbeats);
            }
            $messages = $this->channel->messages();
            $count = count($messages);
            for (; $cursor < $count; $cursor++) {
                $message = $messages[$cursor];
                if ($message->id === null) {
                    $message = new RealtimeMessage($message->event, $message->data, bin2hex(random_bytes(8)), $message->retry);
                }
                if (!$send($message->toSse())) return $this->finish($start, 'disconnect', $delivered, $heartbeats);
                $moment = ($this->now)();
            }
            $idle = $moment - $lastWrite;
            if ($this->idleTimeoutSeconds > 0 && $idle >= $this->idleTimeoutSeconds) {
                return $this->finish($start, 'idle-timeout', $delivered, $heartbeats);
            }
            if ($this->heartbeatSeconds > 0 && $idle >= $this->heartbeatSeconds) {
                if (!$send(": heartbeat\n\n")) return $this->finish($start, 'disconnect', $delivered, $heartbeats);
                $heartbeats++;
            }
            ($this->sleep)($this->pollIntervalMs);
        }
    }

    private function finish(float $start, string $reason, int $delivered, int $heartbeats): SseStreamEnd
    {
        return new SseStreamEnd($reason, max(0.0, ($this->now)() - $start), $delivered, $heartbeats);
    }
}
