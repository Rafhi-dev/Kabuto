<?php

declare(strict_types=1);

namespace Kabuto\Redis;

use RuntimeException;

final class RedisConnectionFactory
{
    private ?object $connection = null;

    public function __construct(private readonly array $config = []) {}

    public function make(): object
    {
        if ($this->connection !== null) return $this->connection;
        if (!class_exists('Redis')) throw new RuntimeException('Redis requires the ext-redis PHP extension.');
        $redis = new \Redis();
        $host = (string) ($this->config['host'] ?? '127.0.0.1');
        $port = (int) ($this->config['port'] ?? 6379);
        $timeout = (float) ($this->config['timeout'] ?? 2.0);
        $attempts = max(1, (int) ($this->config['retries'] ?? 1));
        $last = null;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $scheme = !empty($this->config['tls']) ? 'tls' : 'tcp';
                $target = $scheme . '://' . $host;
                $options = !empty($this->config['tls']) ? ['ssl' => [
                    'verify_peer' => (bool) ($this->config['verify_peer'] ?? true),
                    'verify_peer_name' => (bool) ($this->config['verify_peer_name'] ?? true),
                    'cafile' => $this->config['cafile'] ?? null,
                ]] : [];
                if (!($this->config['persistent'] ?? false)) $redis->connect($target, $port, $timeout, 0, $timeout, $options);
                else $redis->pconnect($target, $port, $timeout, (string) ($this->config['persistent_id'] ?? 'kabuto'), 0, $timeout, $options);
                if (isset($this->config['password']) && $this->config['password'] !== '') $redis->auth((string) $this->config['password']);
                if (isset($this->config['database'])) $redis->select((int) $this->config['database']);
                return $this->connection = $redis;
            } catch (\Throwable $e) { $last = $e; if ($attempt < $attempts) usleep((int) ($this->config['retry_delay_ms'] ?? 100) * 1000); }
        }
        throw new RuntimeException('Redis connection failed after ' . $attempts . ' attempt(s): ' . ($last?->getMessage() ?? 'unknown error'), 0, $last);
    }

    public function ping(): bool { return $this->make()->ping() === true || $this->make()->ping() === '+PONG'; }

    public function reset(): void { $this->connection = null; }
}
