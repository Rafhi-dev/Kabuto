<?php

declare(strict_types=1);

namespace Kabuto\Realtime;

final class RedisPubSubChannel implements RealtimeChannel
{
    /** @var list<RealtimeMessage> */
    private array $messages = [];
    public function __construct(private readonly object $redis, private readonly string $channel, private readonly string $prefix = 'kabuto:realtime:') {}
    public function publish(RealtimeMessage $message): void
    {
        $payload = json_encode(['event' => $message->event, 'data' => $message->data, 'id' => $message->id, 'retry' => $message->retry], JSON_THROW_ON_ERROR);
        $this->redis->publish($this->prefix . $this->channel, $payload); $this->messages[] = $message;
    }
    public function messages(): array { return $this->messages; }

    /** @param callable(RealtimeMessage):void $consumer */
    public function subscribe(callable $consumer, int $timeout = 0): void
    {
        if (!method_exists($this->redis, 'subscribe')) throw new \RuntimeException('Redis client does not support pub/sub subscribe.');
        $this->redis->subscribe([$this->prefix . $this->channel], function (object $redis, string $channel, string $payload) use ($consumer): void {
            $data = json_decode($payload, true);
            if (!is_array($data) || !isset($data['event'])) return;
            $consumer(new RealtimeMessage((string) $data['event'], $data['data'] ?? null, isset($data['id']) ? (string) $data['id'] : null, isset($data['retry']) ? (int) $data['retry'] : null));
        });
    }
}
