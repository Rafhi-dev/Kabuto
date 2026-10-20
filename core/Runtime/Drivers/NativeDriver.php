<?php

declare(strict_types=1);

namespace Kabuto\Runtime\Drivers;

use Kabuto\Runtime\WorkerServer;

final class NativeDriver implements RuntimeDriverInterface
{
    private bool $running = false;

    /** @var array<int,resource> */
    private array $connections = [];

    /** @var array<int,string> */
    private array $buffers = [];

    /** @var array<int,array{peer:string,time:int}> */
    private array $meta = [];

    public function loop($serverSocket, WorkerServer $server): void
    {
        $this->running = true;

        while ($this->running && $server->isRunning()) {
            $read = array_values($this->connections);
            $read[] = $serverSocket;
            $write = null;
            $except = null;

            $numChanged = @stream_select($read, $write, $except, 0, 100000);

            if ($numChanged === false) {
                foreach ($this->connections as $connId => $conn) {
                    if (!is_resource($conn) || @feof($conn)) {
                        $this->closeConnection($connId);
                    }
                }
                continue;
            }

            if ($numChanged === 0) {
                $this->cleanIdleConnections(5);
                continue;
            }

            // Accept new incoming connections
            if (in_array($serverSocket, $read, true)) {
                while (($client = @stream_socket_accept($serverSocket, 0, $peerName)) !== false) {
                    stream_set_blocking($client, false);

                    if (count($this->connections) >= 1000) {
                        $this->cleanIdleConnections(1);
                    }

                    $id = (int) $client;
                    $this->connections[$id] = $client;
                    $this->buffers[$id] = '';
                    $this->meta[$id] = [
                        'peer' => (string) $peerName,
                        'time' => time(),
                    ];
                }
                $read = array_filter($read, static fn($r) => $r !== $serverSocket);
            }

            // Process data from connected clients
            foreach ($read as $client) {
                $id = (int) $client;
                $data = @fread($client, 65536);

                if ($data === false || $data === '') {
                    if (!is_resource($client) || feof($client)) {
                        $this->closeConnection($id);
                    }
                    continue;
                }

                $this->buffers[$id] = ($this->buffers[$id] ?? '') . $data;
                $this->meta[$id]['time'] = time();

                $reqLength = 0;
                while (isset($this->buffers[$id]) && $server->isRequestComplete($this->buffers[$id], $reqLength)) {
                    $rawRequest = substr($this->buffers[$id], 0, $reqLength);
                    $this->buffers[$id] = substr($this->buffers[$id], $reqLength);

                    $keepAlive = !preg_match('/connection:\s*close/i', $rawRequest);
                    $response = $server->handleRawRequest($rawRequest, $this->meta[$id]['peer'] ?? '');
                    $wire = $server->formatHttpResponse($response, $keepAlive);

                    $written = @fwrite($client, $wire);
                    if ($written === false || $written < strlen($wire)) {
                        $this->closeConnection($id);
                        break;
                    }

                    $server->incrementRequestCount();

                    if (!$keepAlive) {
                        $this->closeConnection($id);
                        break;
                    }

                    if ($server->shouldRecycle()) {
                        if ($server->isClusterWorker()) {
                            $this->running = false;
                            break 2;
                        }

                        gc_collect_cycles();
                        $server->resetRequestCount();
                    }
                }
            }

            if ($server->getRequestCount() > 0 && ($server->getRequestCount() % 100) === 0) {
                gc_collect_cycles();
            }

            $this->cleanIdleConnections(5);
        }

        $this->closeAll();
    }

    private function cleanIdleConnections(int $timeoutSeconds = 5): void
    {
        $now = time();
        foreach ($this->meta as $id => $info) {
            if (($now - $info['time']) > $timeoutSeconds) {
                $this->closeConnection($id);
            }
        }
    }

    private function closeConnection(int $id): void
    {
        if (isset($this->connections[$id])) {
            @fclose($this->connections[$id]);
            unset($this->connections[$id]);
        }
        unset($this->buffers[$id], $this->meta[$id]);
    }

    private function closeAll(): void
    {
        foreach ($this->connections as $client) {
            if (is_resource($client)) {
                @fclose($client);
            }
        }
        $this->connections = [];
        $this->buffers = [];
        $this->meta = [];
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function name(): string
    {
        return 'Native (stream_select)';
    }

    public static function isSupported(): bool
    {
        return true;
    }
}
