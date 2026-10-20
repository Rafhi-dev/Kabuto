<?php

declare(strict_types=1);

namespace Kabuto\Runtime\Drivers;

use Fiber;
use Kabuto\Runtime\WorkerServer;
use Revolt\EventLoop;
use RuntimeException;

final class RevoltDriver implements RuntimeDriverInterface
{
    private bool $running = false;

    /** @var array<int,string> */
    private array $watchers = [];

    /** @var array<int,resource> */
    private array $connections = [];

    /** @var array<int,string> */
    private array $buffers = [];

    /** @var array<int,array{peer:string,time:int}> */
    private array $meta = [];

    public function loop($serverSocket, WorkerServer $server): void
    {
        if (!self::isSupported()) {
            throw new RuntimeException(
                'The Revolt runtime driver requires the "revolt/event-loop" package. ' .
                'Install it via Composer: composer require revolt/event-loop'
            );
        }

        $this->running = true;

        // Periodic idle connection cleaner (every 5 seconds)
        $cleanerWatcher = EventLoop::repeat(5.0, function (): void {
            $this->cleanIdleConnections(5);
        });

        // Accept new connections
        $acceptWatcher = EventLoop::onReadable($serverSocket, function (string $wId, $serverSocket) use ($server): void {
            while (($client = @stream_socket_accept($serverSocket, 0, $peerName)) !== false) {
                stream_set_blocking($client, false);
                $id = (int) $client;
                $this->connections[$id] = $client;
                $this->buffers[$id] = '';
                $this->meta[$id] = [
                    'peer' => (string) $peerName,
                    'time' => time(),
                ];

                $clientWatcher = EventLoop::onReadable($client, function (string $cwId, $client) use ($server, $id): void {
                    $data = @fread($client, 65536);
                    if ($data === false || $data === '') {
                        if (!is_resource($client) || feof($client)) {
                            $this->closeConnection($id);
                        }
                        return;
                    }

                    $this->buffers[$id] = ($this->buffers[$id] ?? '') . $data;
                    $this->meta[$id]['time'] = time();

                    $reqLength = 0;
                    while (isset($this->buffers[$id]) && $server->isRequestComplete($this->buffers[$id], $reqLength)) {
                        $rawRequest = substr($this->buffers[$id], 0, $reqLength);
                        $this->buffers[$id] = substr($this->buffers[$id], $reqLength);

                        $keepAlive = !preg_match('/connection:\s*close/i', $rawRequest);

                        // Execute each request inside a Fiber for cooperative multitasking
                        $fiber = new Fiber(function () use ($server, $rawRequest, $client, $id, $keepAlive): void {
                            $response = $server->handleRawRequest($rawRequest, $this->meta[$id]['peer'] ?? '');
                            $wire = $server->formatHttpResponse($response, $keepAlive);

                            $written = @fwrite($client, $wire);
                            if ($written === false || $written < strlen($wire)) {
                                $this->closeConnection($id);
                                return;
                            }

                            $server->incrementRequestCount();

                            if (!$keepAlive) {
                                $this->closeConnection($id);
                                return;
                            }

                            if ($server->shouldRecycle()) {
                                if ($server->isClusterWorker()) {
                                    $this->stop();
                                    return;
                                }
                                gc_collect_cycles();
                                $server->resetRequestCount();
                            }
                        });

                        $fiber->start();
                    }
                });

                $this->watchers[$id] = $clientWatcher;
            }
        });

        EventLoop::run();

        // Cleanup watchers on exit
        EventLoop::cancel($acceptWatcher);
        EventLoop::cancel($cleanerWatcher);
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
        if (isset($this->watchers[$id])) {
            EventLoop::cancel($this->watchers[$id]);
            unset($this->watchers[$id]);
        }

        if (isset($this->connections[$id])) {
            if (is_resource($this->connections[$id])) {
                @fclose($this->connections[$id]);
            }
            unset($this->connections[$id]);
        }

        unset($this->buffers[$id], $this->meta[$id]);
    }

    private function closeAll(): void
    {
        foreach ($this->watchers as $id => $watcherId) {
            EventLoop::cancel($watcherId);
        }
        $this->watchers = [];

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
        if (class_exists(EventLoop::class)) {
            EventLoop::stop();
        }
    }

    public function name(): string
    {
        return 'Revolt (Fiber EventLoop)';
    }

    public static function isSupported(): bool
    {
        return class_exists(EventLoop::class);
    }
}
