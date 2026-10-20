<?php

declare(strict_types=1);

namespace Kabuto\Realtime;

use Kabuto\Observability\MetricsRegistry;

/**
 * WebSocket server adapter over stream sockets: handshake, authentication, channel
 * subscriptions with authorization, ping/pong keep-alive, event delivery and graceful
 * disconnect. The listener uses stream_select() to interleave clients concurrently
 * within one process; cross-worker fanout goes through WebSocketServer::deliver().
 */
final class WebSocketServer
{
    /** @var array<int, array<string,mixed>> */
    private array $connections = [];

    /** @var array<int,int> resource id => connection id for sockets managed by serve() */
    private array $managedSockets = [];

    private int $nextId = 1;

    /** @var callable():float */
    private $now;

    /** @var null|callable(token:mixed,headers:array,remote:string,context:mixed):(mixed|false) false rejects the connection */
    private $auth = null;

    /** @var null|callable(string,mixed):bool receives (channel, subscriber context) */
    private $authorize = null;

    public function __construct(
        private readonly RealtimeManager $manager,
        private readonly int $pingIntervalSeconds = 30,
        private readonly int $idleTimeoutSeconds = 900,
        private readonly int $handshakeTimeoutSeconds = 10,
        private readonly int $tickMs = 10,
        ?callable $auth = null,
        ?callable $authorize = null,
        ?callable $now = null,
        private readonly ?MetricsRegistry $metrics = null,
        private readonly ?GatewayRegistry $gateways = null,
    ) {
        $this->now = $now !== null ? $now(...) : static fn (): float => microtime(true);
        $this->auth = $auth;
        $this->authorize = $authorize;
        $this->gateways?->attachServer($this);
    }

    public function connectionCount(): int
    {
        return count($this->connections);
    }

    /** Register a connection programmatically (fanout/relay workers push events through deliver()). */
    public function attach($stream, array $subscriptions, ?callable $onDetach = null): int
    {
        $id = $this->nextId++;
        $this->connections[$id] = [
            'id' => $id,
            'socket' => $stream,
            'headers' => [],
            'context' => null,
            'remote' => '',
            'subscriptions' => array_fill_keys($subscriptions, true),
            'authorized' => $this->auth === null,
            'buffer' => '',
            'awaitingPong' => false,
            'onDetach' => $onDetach,
        ];
        $this->metrics?->gauge('kabuto_realtime_connections', $this->connectionCount());
        return $id;
    }

    /** @return int number of subscribed connections the event was written to */
    public function deliver(string $channel, RealtimeMessage $message): int
    {
        $payload = json_encode(['op' => 'event', 'channel' => $channel, 'event' => $message->event, 'data' => $message->data, 'id' => $message->id], JSON_THROW_ON_ERROR);
        $frame = WebSocketFrame::encode(WebSocketFrame::OP_TEXT, $payload);
        $sent = 0;
        foreach ($this->connections as $state) {
            if (!isset($state['subscriptions'][$channel])) continue;
            if (!$this->sendBytes($state['socket'], $frame)) {
                $this->detach($state['id'], 1006, 'client is gone');
                continue;
            }
            $sent++;
        }
        return $sent;
    }

    /** Handle a single accepted socket end to end (handshake + message loop). */
    public function runConnection($stream, mixed $context = null, ?callable $sleep = null, ?callable $shouldStop = null): void
    {
        $sleepFn = $sleep !== null ? $sleep(...) : static function (int $ms): void { usleep($ms * 1000); };
        $stopFn = $shouldStop !== null ? $shouldStop(...) : static fn (): bool => false;
        try {
            [$headers, $rest] = $this->readHeaders($stream, ($this->now)() + $this->handshakeTimeoutSeconds);
            $accept = WebSocketHandshake::validate($headers);
        } catch (WebSocketException $e) {
            if ($e->response !== '') @fwrite($stream, $e->response);
            @fclose($stream);
            return;
        }
        fwrite($stream, WebSocketHandshake::response($accept));
        $id = $this->nextId++;
        $this->connections[$id] = [
            'id' => $id,
            'socket' => $stream,
            'headers' => $headers,
            'context' => $context,
            'remote' => (string) (stream_socket_get_name($stream, true) ?: ''),
            'subscriptions' => [],
            'authorized' => $this->auth === null,
            'buffer' => $rest,
            'awaitingPong' => false,
            'onDetach' => null,
        ];
                            $this->metrics?->gauge('kabuto_realtime_connections', $this->connectionCount());
        $state = &$this->connections[$id];
        $lastPing = ($this->now)();
        $lastSeen = $lastPing;
        try {
            while (true) {
                $moment = ($this->now)();
                $chunk = @fread($stream, 65536);
                if ($chunk === false) {
                    $state['onDetach'] === null || ($state['onDetach'])(1006, 'connection error');
                    @fclose($stream);
                    return;
                }
                $closed = false;
                if ($chunk !== '') {
                    $lastSeen = ($this->now)();
                    $state['buffer'] .= $chunk;
                }
                while (($frame = WebSocketFrame::decode($state['buffer'])) !== null) {
                    $state['buffer'] = substr($state['buffer'], $frame['consumed']);
                    $result = $this->handleFrame($frame, $state);
                    if ($result !== null) {
                        $this->detach($id, $result['code'], $result['reason']);
                        $closed = true;
                        break;
                    }
                }
                if ($closed) return;
                if ($chunk === '') {
                    if ($stopFn()) {
                        $this->detach($id, 1001, 'server stopping');
                        return;
                    }
                    $idle = (($this->now)()) - $lastSeen;
                    if ($this->idleTimeoutSeconds > 0 && $idle >= $this->idleTimeoutSeconds) {
                        $this->detach($id, 1001, 'idle timeout');
                        return;
                    }
                    if ($this->pingIntervalSeconds > 0 && (($this->now)()) - $lastPing >= $this->pingIntervalSeconds) {
                        $this->sendJson($stream, ['op' => 'ping', 't' => (int) (($this->now)())]);
                        $lastPing = ($this->now)();
                    }
                    $sleepFn($this->tickMs);
                }
            }
        } finally {
            unset($this->connections[$id]);
        }
    }

    /** Concurrent, non-blocking accept loop driven by stream_select(). Returns accepted connection count. */
    public function serve(string $address, mixed $context = null, ?callable $shouldStop = null, int $acceptTimeoutSeconds = 1): int
    {
        $server = @stream_socket_server($address, $errno, $errstr);
        if ($server === false) {
            throw new WebSocketException("Unable to listen on [{$address}]: {$errstr}.");
        }
        try {
            return $this->serveSocket($server, $context, $shouldStop, $acceptTimeoutSeconds);
        } finally {
            if (is_resource($server)) fclose($server);
        }
    }

    /** Serve an already-bound listening socket; the caller retains ownership of its lifecycle. */
    public function serveSocket($server, mixed $context = null, ?callable $shouldStop = null, int $acceptTimeoutSeconds = 1): int
    {
        if (!is_resource($server)) throw new WebSocketException('A valid listening socket resource is required.');
        stream_set_blocking($server, false);
        $handled = 0;
        try {
            while ($shouldStop === null || !$shouldStop()) {
                $read = [$server];
                $write = [];
                foreach ($this->connections as $state) {
                    if (!isset($state['serve_managed']) || !$state['serve_managed']) continue;
                    $read[] = $state['socket'];
                    if (($state['outgoing'] ?? '') !== '') $write[] = $state['socket'];
                }
                $except = null;
                $selected = @stream_select($read, $write, $except, 0, max(1, $this->tickMs) * 1000);
                if ($selected === false) throw new WebSocketException('stream_select failed while serving WebSocket connections.');

                foreach ($read as $ready) {
                    if ($ready === $server) {
                        while (($client = @stream_socket_accept($server, 0)) !== false) {
                            stream_set_blocking($client, false);
                            $id = $this->nextId++;
                            $now = ($this->now)();
                            $this->connections[$id] = [
                                'id' => $id,
                                'socket' => $client,
                                'headers' => [],
                                'context' => $context,
                                'remote' => (string) (stream_socket_get_name($client, true) ?: ''),
                                'subscriptions' => [],
                                'authorized' => $this->auth === null,
                                'buffer' => '',
                                'header_buffer' => '',
                                'phase' => 'handshake',
                                'handshake_deadline' => $now + $this->handshakeTimeoutSeconds,
                                'last_seen' => $now,
                                'last_ping' => $now,
                                'awaiting_pong' => false,
                                'outgoing' => '',
                                'onDetach' => null,
                                'serve_managed' => true,
                            ];
                            $this->managedSockets[(int) $client] = $id;
                            $this->metrics?->gauge('kabuto_realtime_connections', $this->connectionCount());
                            $handled++;
                        }
                        continue;
                    }
                    $id = $this->managedSockets[(int) $ready] ?? null;
                    if ($id === null || !isset($this->connections[$id])) continue;
                    $chunk = @fread($ready, 65536);
                    if ($chunk === false || ($chunk === '' && feof($ready))) {
                        $this->detach($id, 1001, 'peer disconnected');
                        continue;
                    }
                    if ($chunk === '') continue;
                    $state = &$this->connections[$id];
                    $state['last_seen'] = ($this->now)();
                    if ($state['phase'] === 'handshake') {
                        $state['header_buffer'] .= $chunk;
                        $this->processHandshake($id);
                    } else {
                        $state['buffer'] .= $chunk;
                        $this->processFrames($id);
                    }
                    unset($state);
                }

                foreach ($write as $ready) {
                    $id = $this->managedSockets[(int) $ready] ?? null;
                    if ($id === null || !isset($this->connections[$id])) continue;
                    $outgoing = $this->connections[$id]['outgoing'];
                    $written = @fwrite($ready, $outgoing);
                    if ($written === false) {
                        $this->detach($id, 1001, 'socket write failed');
                        continue;
                    }
                    $this->connections[$id]['outgoing'] = substr($outgoing, $written);
                }

                $now = ($this->now)();
                foreach (array_keys($this->connections) as $id) {
                    if (!isset($this->connections[$id]['serve_managed'])) continue;
                    $state = $this->connections[$id];
                    if ($state['phase'] === 'handshake' && $now >= $state['handshake_deadline']) {
                        @fwrite($state['socket'], "HTTP/1.1 408 Request Timeout\r\nConnection: close\r\n\r\n");
                        $this->detach($id, 1001, 'handshake timeout');
                        continue;
                    }
                    if ($state['phase'] !== 'open') continue;
                    if ($this->idleTimeoutSeconds > 0 && $now - $state['last_seen'] >= $this->idleTimeoutSeconds) {
                        $this->detach($id, 1001, 'idle timeout');
                        continue;
                    }
                    if ($this->pingIntervalSeconds > 0 && $now - $state['last_ping'] >= $this->pingIntervalSeconds) {
                        $this->sendBytes($state['socket'], WebSocketFrame::encode(WebSocketFrame::OP_PING, (string) (int) $now));
                        $this->connections[$id]['last_ping'] = $now;
                    }
                }
            }
        } finally {
            foreach (array_keys($this->connections) as $id) {
                if (isset($this->connections[$id]['serve_managed'])) $this->detach($id, 1001, 'server stopping');
            }
        }
        return $handled;
    }

    private function processHandshake(int $id): void
    {
        $state = &$this->connections[$id];
        $buffer = $state['header_buffer'];
        $separator = strpos($buffer, "\r\n\r\n");
        if ($separator === false) {
            if (strlen($buffer) > 16_384) $this->detach($id, 1002, 'oversized handshake');
            return;
        }

        $headerText = substr($buffer, 0, $separator);
        $state['buffer'] .= substr($buffer, $separator + 4);
        $state['header_buffer'] = '';
        $lines = explode("\r\n", $headerText);
        $requestLine = array_shift($lines) ?? '';
        if (!preg_match('/^GET\s+\S+\s+HTTP\/1\.[01]$/i', trim($requestLine))) {
            @fwrite($state['socket'], "HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n");
            $this->detach($id, 1002, 'invalid handshake request');
            return;
        }
        $headers = [];
        foreach ($lines as $line) {
            if (!str_contains($line, ':')) continue;
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
        try {
            $accept = WebSocketHandshake::validate($headers);
        } catch (WebSocketException $exception) {
            if ($exception->response !== '') @fwrite($state['socket'], $exception->response);
            $this->detach($id, 1002, 'invalid handshake');
            return;
        }
        $state['headers'] = $headers;
        $state['phase'] = 'open';
        $state['last_seen'] = ($this->now)();
        $state['last_ping'] = $state['last_seen'];
        $state['outgoing'] .= WebSocketHandshake::response($accept);
        unset($state);
        $this->processFrames($id);
    }

    private function processFrames(int $id): void
    {
        if (!isset($this->connections[$id])) return;
        $state = &$this->connections[$id];
        while (($frame = WebSocketFrame::decode($state['buffer'])) !== null) {
            $state['buffer'] = substr($state['buffer'], $frame['consumed']);
            $result = $this->handleFrame($frame, $state);
            if ($result !== null) {
                $this->detach($id, $result['code'], $result['reason']);
                break;
            }
        }
        unset($state);
    }

    private function queueBytes(int $id, string $bytes): void
    {
        if (isset($this->connections[$id])) $this->connections[$id]['outgoing'] .= $bytes;
    }

    private function sendBytes($stream, string $bytes): bool
    {
        $id = is_resource($stream) ? ($this->managedSockets[(int) $stream] ?? null) : null;
        if ($id !== null) {
            $this->queueBytes($id, $bytes);
            return true;
        }
        return @fwrite($stream, $bytes) === strlen($bytes);
    }

    /** Wire this server to receive RealtimeManager publishes; assign the returned manager instead of the unwired one. */
    public function wired(): RealtimeManager
    {
        $server = $this;
        return $this->manager->withFanout(static function (string $channel, RealtimeMessage $message) use ($server): void {
            $server->deliver($channel, $message);
        });
    }

    /** @param array{fin:int,opcode:int,payload:string,consumed:int} $frame @return null|array{code:int,reason:string} */
    private function handleFrame(array $frame, array &$state): ?array
    {
        switch ($frame['opcode']) {
            case WebSocketFrame::OP_TEXT:
                $op = json_decode($frame['payload'], true);
                if (!is_array($op)) {
                    $this->sendJson($state['socket'], ['op' => 'error', 'message' => 'Text frames must contain a JSON operation.']);
                    return ['code' => 1003, 'reason' => 'invalid json op'];
                }
                return $this->dispatchOperation($op, $state);
            case WebSocketFrame::OP_CONT:
                return ['code' => 1002, 'reason' => 'unexpected continuation frame'];
            case WebSocketFrame::OP_PING:
                $this->sendBytes($state['socket'], WebSocketFrame::encode(WebSocketFrame::OP_PONG, $frame['payload']));
                return null;
            case WebSocketFrame::OP_PONG:
                $state['awaitingPong'] = false;
                return null;
            case WebSocketFrame::OP_CLOSE:
                return ['code' => 1000, 'reason' => 'client requested close'];
            default:
                $this->sendJson($state['socket'], ['op' => 'error', 'message' => 'Unsupported opcode ' . $frame['opcode'] . '.']);
                return ['code' => 1003, 'reason' => 'unsupported opcode'];
        }
    }

    /** @param array<string,mixed> $op @return null|array{code:int,reason:string} */
    private function dispatchOperation(array $op, array &$state): ?array
    {
        $name = (string) ($op['op'] ?? '');
        switch ($name) {
            case 'ping':
                $this->sendJson($state['socket'], ['op' => 'pong', 't' => time()]);
                return null;
            case 'auth':
                if ($this->auth === null) {
                    $this->sendJson($state['socket'], ['op' => 'authenticated']);
                    return null;
                }
                $result = ($this->auth)($op['token'] ?? null, $state['headers'], $state['remote'], $state['context']);
                if ($result === false) {
                    $this->sendJson($state['socket'], ['op' => 'error', 'message' => 'Authentication failed.']);
                    return ['code' => 4001, 'reason' => 'authentication failed'];
                }
                $state['authorized'] = true;
                $state['authContext'] = $result === true ? null : $result;
                $this->sendJson($state['socket'], ['op' => 'authenticated']);
                return null;
            case 'subscribe':
                if ($this->auth !== null && !$state['authorized']) {
                    $this->sendJson($state['socket'], ['op' => 'error', 'message' => 'Authentication required before subscribing.']);
                    return ['code' => 4002, 'reason' => 'authentication required'];
                }
                $channel = (string) ($op['channel'] ?? '');
                $subscriber = $state['authContext'] ?? $state['context'];
                if ($this->authorize !== null && !($this->authorize)($channel, $subscriber)) {
                    $this->sendJson($state['socket'], ['op' => 'error', 'message' => 'Channel not authorized: ' . $channel]);
                    return ['code' => 1008, 'reason' => 'channel not authorized'];
                }
                $state['subscriptions'][$channel] = true;
                $this->sendJson($state['socket'], ['op' => 'subscribed', 'channel' => $channel]);
                return null;
            case 'unsubscribe':
                $channel = (string) ($op['channel'] ?? '');
                unset($state['subscriptions'][$channel]);
                $this->sendJson($state['socket'], ['op' => 'unsubscribed', 'channel' => $channel]);
                return null;
            case 'pong':
                $state['awaitingPong'] = false;
                return null;
            default:
                return $this->dispatchGatewayOperation($name, $op, $state);
        }
    }

    /** @param array<string,mixed> $op @return null|array{code:int,reason:string} */
    private function dispatchGatewayOperation(string $name, array $op, array &$state): ?array
    {
        if ($this->gateways !== null && $this->gateways->has($name)) {
            $payload = is_array($op['data'] ?? null) ? $op['data'] : $op;
            $response = $this->gateways->dispatch($name, $payload, [
                'id' => $state['id'],
                'remote' => $state['remote'],
                'headers' => $state['headers'],
                'context' => $state['authContext'] ?? $state['context'],
                'subscriptions' => array_keys($state['subscriptions']),
            ]);

            if ($response !== null) {
                $this->sendJson($state['socket'], is_array($response) ? $response : ['op' => 'message', 'event' => $name, 'data' => $response]);
            }

            return null;
        }

        $this->sendJson($state['socket'], ['op' => 'error', 'message' => 'Unsupported op: ' . ($name === '' ? '(missing)' : $name)]);
        return ['code' => 1008, 'reason' => 'unsupported operation'];
    }

    private function detach(int $id, int $code, string $reason): void
    {
        $state = $this->connections[$id] ?? null;
        if ($state === null) return;
        @fwrite($state['socket'], WebSocketFrame::close($code, $reason));
        if (isset($state['serve_managed'])) unset($this->managedSockets[(int) $state['socket']]);
        unset($this->connections[$id]);
        $this->metrics?->gauge('kabuto_realtime_connections', $this->connectionCount());
        if ($state['onDetach'] !== null) ($state['onDetach'])($code, $reason);
        @fclose($state['socket']);
    }

    private function sendJson($stream, array $payload): void
    {
        $this->sendBytes($stream, WebSocketFrame::encode(WebSocketFrame::OP_TEXT, json_encode($payload, JSON_THROW_ON_ERROR)));
    }

    /** @return array{0:array<string,string>,1:string} parsed headers plus any bytes read past the handshake */
    private function readHeaders($stream, float $deadline): array
    {
        stream_set_blocking($stream, false);
        $raw = '';
        while (!str_contains($raw, "\r\n\r\n")) {
            $chunk = @fread($stream, 8192);
            if ($chunk === '' || $chunk === false) {
                if (($this->now)() >= $deadline) throw new WebSocketException('Handshake headers timed out.');
                usleep(10_000);
                continue;
            }
            $raw .= $chunk;
        }
        $split = (int) strpos($raw, "\r\n\r\n");
        $head = substr($raw, 0, $split);
        $rest = substr($raw, $split + 4);
        $headers = [];
        foreach (explode("\r\n", $head) as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }
        return [$headers, $rest];
    }
}
