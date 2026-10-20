<?php

declare(strict_types=1);

namespace Kabuto\Observability;

use Throwable;

final class HealthRegistry
{
    /** @var array<string, callable():mixed> */
    private array $checks = [];

    /** @var array<string, callable():mixed> */
    private array $liveChecks = [];

    public function check(string $name, callable $callback, bool $readiness = true): self
    {
        $this->checks[$name] = $callback;
        if (!$readiness) $this->liveChecks[$name] = $callback;
        return $this;
    }

    /** @return array{status:string,checks:array<string,array<string,mixed>>,timestamp:string} */
    public function live(): array
    {
        return $this->run($this->liveChecks);
    }

    /** @return array{status:string,checks:array<string,array<string,mixed>>,timestamp:string} */
    public function ready(): array
    {
        return $this->run($this->checks);
    }

    /** @return array{status:string,checks:array<string,array<string,mixed>>,timestamp:string} */
    private function run(array $checks): array
    {
        $results = [];
        $healthy = true;
        foreach ($checks as $name => $callback) {
            $started = microtime(true);
            try {
                $value = $callback();
                $ok = $value === true || (is_array($value) && ($value['ok'] ?? false) === true);
                $detail = is_array($value) ? $value : ['ok' => $ok];
                $detail['ok'] = $ok;
                $detail['duration_ms'] = round((microtime(true) - $started) * 1000, 2);
            } catch (Throwable $exception) {
                $ok = false;
                $detail = [
                    'ok' => false,
                    'error' => $exception->getMessage(),
                    'exception' => $exception::class,
                    'duration_ms' => round((microtime(true) - $started) * 1000, 2),
                ];
            }
            $results[$name] = $detail;
            $healthy = $healthy && $ok;
        }

        return [
            'status' => $healthy ? 'ok' : 'failed',
            'checks' => $results,
            'timestamp' => gmdate('c'),
        ];
    }
}
