<?php

declare(strict_types=1);

namespace Kabuto\Realtime;

final class RedisRealtimeFanoutRelay
{
    public function __construct(
        private readonly object $redis,
        private readonly string $channel = 'realtime:fanout',
        private readonly string $prefix = 'kabuto:',
    ) {
    }

    /** @param callable(string,RealtimeMessage):void $consumer */
    public function subscribe(callable $consumer): void
    {
        if (!method_exists($this->redis, 'subscribe')) {
            throw new \RuntimeException('Redis client does not support pub/sub subscribe.');
        }

        $this->redis->subscribe([$this->prefix . $this->channel], function (object $redis, string $channel, string $payload) use ($consumer): void {
            $data = json_decode($payload, true);
            if (!is_array($data) || !isset($data['channel'], $data['event'])) return;
            ($consumer)(
                (string) $data['channel'],
                new RealtimeMessage(
                    (string) $data['event'],
                    $data['data'] ?? null,
                    isset($data['id']) ? (string) $data['id'] : null,
                    isset($data['retry']) ? (int) $data['retry'] : null,
                ),
            );
        });
    }

    public function subscribeTo(WebSocketServer $server): void
    {
        $this->subscribe(static function (string $channel, RealtimeMessage $message) use ($server): void {
            $server->deliver($channel, $message);
        });
    }
}
