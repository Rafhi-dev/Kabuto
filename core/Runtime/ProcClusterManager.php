<?php

declare(strict_types=1);

namespace Kabuto\Runtime;

use Closure;
use RuntimeException;

final class ProcClusterManager
{
    private bool $running = false;
    private int $roundRobinIndex = 0;

    /**
     * @var array<int, array{
     *     process: resource,
     *     port: int,
     *     started_at: int
     * }>
     */
    private array $workers = [];

    /** @var array<int, resource> */
    private array $activeSockets = [];

    /** @var array<int, int> socket_id => peer_socket_id */
    private array $pairMap = [];

    /** @var array<int, string> socket_id => pending outgoing bytes */
    private array $buffers = [];

    public function __construct(
        private readonly string $host,
        private readonly int $publicPort,
        private readonly int $workerCount,
        private readonly int $maxRequests,
        private readonly string $basePath,
        private readonly string $driver = 'native',
    ) {
    }

    /**
     * Find an available local TCP port starting from a preferred port.
     *
     * @param list<int> $excluded
     */
    public static function findAvailablePort(string $host, int $preferredPort, array $excluded = []): int
    {
        $port = max(1024, $preferredPort);
        while (in_array($port, $excluded, true)) {
            $port++;
        }

        while ($port < 65535) {
            $context = stream_context_create(['socket' => ['backlog' => 1]]);
            $sock = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr, STREAM_SERVER_BIND, $context);
            if ($sock !== false) {
                @fclose($sock);
                return $port;
            }
            $port++;
            while (in_array($port, $excluded, true)) {
                $port++;
            }
        }

        return $preferredPort;
    }

    /**
     * Spawn child workers and run the master TCP dispatch loop.
     *
     * @param resource $serverSocket
     * @param ?Closure(string):void $onStart
     */
    public function start($serverSocket, ?Closure $onStart = null): void
    {
        $this->running = true;

        // Allocate internal ports and spawn child worker processes
        $usedPorts = [$this->publicPort];
        for ($i = 0; $i < $this->workerCount; $i++) {
            $port = self::findAvailablePort($this->host, $this->publicPort + 1 + $i, $usedPorts);
            $usedPorts[] = $port;
            $this->spawnWorker($i, $port);
        }

        if ($onStart !== null) {
            $onStart(sprintf('tcp://%s:%d', $this->host, $this->publicPort));
        }

        $this->dispatchLoop($serverSocket);
    }

    /**
     * Spawn an individual child worker process.
     */
    private function spawnWorker(int $index, int $port): void
    {
        $kabuto = $this->basePath . DIRECTORY_SEPARATOR . 'kabuto';
        $cmd = sprintf(
            '%s %s serve --internal-worker --worker-id=%d --host=%s --port=%d --driver=%s --max-requests=%d',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($kabuto),
            $index + 1,
            escapeshellarg($this->host),
            $port,
            escapeshellarg($this->driver),
            $this->maxRequests
        );

        $nullDevice = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $descriptors = [
            0 => ['file', $nullDevice, 'r'],
            1 => ['file', $nullDevice, 'w'],
            2 => ['file', $nullDevice, 'w'],
        ];

        $process = @proc_open($cmd, $descriptors, $pipes, $this->basePath);
        if (!is_resource($process)) {
            throw new RuntimeException(sprintf('Failed to spawn child worker process #%d on port %d.', $index + 1, $port));
        }

        $this->workers[$index] = [
            'process' => $process,
            'port' => $port,
            'started_at' => time(),
        ];

        // Wait up to 2 seconds for worker socket to become available
        for ($attempt = 0; $attempt < 20; $attempt++) {
            usleep(50000); // 50ms
            $testSock = @stream_socket_client("tcp://{$this->host}:{$port}", $errno, $errstr, 0.2);
            if ($testSock !== false) {
                @fclose($testSock);
                break;
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
        }
    }

    /**
     * Master dispatch event loop: non-blocking bidirectional socket proxy.
     *
     * @param resource $serverSocket
     */
    private function dispatchLoop($serverSocket): void
    {
        while ($this->running) {
            $this->monitorWorkers();

            $read = array_values($this->activeSockets);
            $read[] = $serverSocket;
            $write = [];

            foreach ($this->buffers as $sId => $buf) {
                if ($buf !== '' && isset($this->activeSockets[$sId])) {
                    $write[] = $this->activeSockets[$sId];
                }
            }

            $except = null;
            $changed = @stream_select($read, $write, $except, 0, 50000);

            if ($changed === false) {
                continue;
            }

            // Accept incoming public connections and pair with an internal worker
            if (in_array($serverSocket, $read, true)) {
                while (($client = @stream_socket_accept($serverSocket, 0)) !== false) {
                    stream_set_blocking($client, false);

                    $worker = $this->selectWorker();
                    if ($worker === null) {
                        @fclose($client);
                        continue;
                    }

                    $backend = @stream_socket_client(
                        "tcp://{$this->host}:{$worker['port']}",
                        $errno,
                        $errstr,
                        1.0,
                        STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
                    );

                    if ($backend === false) {
                        @fclose($client);
                        continue;
                    }

                    stream_set_blocking($backend, false);

                    $cId = (int) $client;
                    $bId = (int) $backend;

                    $this->activeSockets[$cId] = $client;
                    $this->activeSockets[$bId] = $backend;
                    $this->pairMap[$cId] = $bId;
                    $this->pairMap[$bId] = $cId;
                    $this->buffers[$cId] = '';
                    $this->buffers[$bId] = '';
                }

                $read = array_filter($read, static fn($s) => $s !== $serverSocket);
            }

            // Read data and queue to peer's outgoing buffer
            foreach ($read as $socket) {
                $sId = (int) $socket;
                if (!isset($this->pairMap[$sId])) {
                    continue;
                }
                $peerId = $this->pairMap[$sId];

                $data = @fread($socket, 65536);
                if ($data === false || $data === '') {
                    if (!is_resource($socket) || feof($socket)) {
                        $this->closePair($sId);
                    }
                    continue;
                }

                $this->buffers[$peerId] = ($this->buffers[$peerId] ?? '') . $data;
            }

            // Flush pending outgoing buffers
            foreach ($write as $socket) {
                $sId = (int) $socket;
                if (empty($this->buffers[$sId])) {
                    continue;
                }

                $written = @fwrite($socket, $this->buffers[$sId]);
                if ($written === false) {
                    $this->closePair($sId);
                    continue;
                }

                $this->buffers[$sId] = substr($this->buffers[$sId], $written);
            }
        }

        $this->shutdown();
    }

    /**
     * Round-robin selection of an active worker.
     *
     * @return array{process:resource,port:int,started_at:int}|null
     */
    private function selectWorker(): ?array
    {
        if (empty($this->workers)) {
            return null;
        }

        $keys = array_keys($this->workers);
        $count = count($keys);
        $index = $keys[$this->roundRobinIndex % $count];
        $this->roundRobinIndex++;

        return $this->workers[$index] ?? null;
    }

    /**
     * Check running status of workers and respawn any that have exited.
     */
    private function monitorWorkers(): void
    {
        foreach ($this->workers as $index => $info) {
            if (!is_resource($info['process'])) {
                $this->spawnWorker($index, $info['port']);
                continue;
            }

            $status = proc_get_status($info['process']);
            if (!$status['running']) {
                @proc_close($info['process']);
                $this->spawnWorker($index, $info['port']);
            }
        }
    }

    /**
     * Close a socket pair (client <-> worker backend).
     */
    private function closePair(int $socketId): void
    {
        $peerId = $this->pairMap[$socketId] ?? null;

        if (isset($this->activeSockets[$socketId])) {
            if (is_resource($this->activeSockets[$socketId])) {
                @fclose($this->activeSockets[$socketId]);
            }
            unset($this->activeSockets[$socketId]);
        }
        unset($this->buffers[$socketId], $this->pairMap[$socketId]);

        if ($peerId !== null) {
            if (isset($this->activeSockets[$peerId])) {
                if (is_resource($this->activeSockets[$peerId])) {
                    @fclose($this->activeSockets[$peerId]);
                }
                unset($this->activeSockets[$peerId]);
            }
            unset($this->buffers[$peerId], $this->pairMap[$peerId]);
        }
    }

    /**
     * Terminate all child worker processes and close all connections.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    private function shutdown(): void
    {
        // Close all proxied client and backend connections
        foreach ($this->activeSockets as $sock) {
            if (is_resource($sock)) {
                @fclose($sock);
            }
        }
        $this->activeSockets = [];
        $this->pairMap = [];
        $this->buffers = [];

        // Terminate all child processes
        foreach ($this->workers as $info) {
            if (is_resource($info['process'])) {
                @proc_terminate($info['process']);
                @proc_close($info['process']);
            }
        }
        $this->workers = [];
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * @return array<int, array{process:resource,port:int,started_at:int}>
     */
    public function getWorkers(): array
    {
        return $this->workers;
    }
}
