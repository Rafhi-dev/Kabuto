<?php

declare(strict_types=1);

namespace Kabuto\Runtime\Drivers;

use Closure;
use Kabuto\Application;
use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Runtime\WorkerServer;
use RuntimeException;
use Throwable;

final class RoadRunnerDriver implements RuntimeDriverInterface
{
    private bool $running = false;

    public function name(): string
    {
        return 'RoadRunner (High-Performance Process Manager)';
    }

    public static function isSupported(): bool
    {
        return true;
    }

    /**
     * Check if currently running inside a RoadRunner worker process.
     */
    public static function isInsideRoadRunner(): bool
    {
        return getenv('RR_MODE') !== false || getenv('RR_RELAY') !== false;
    }

    /**
     * Start the RoadRunner worker loop.
     *
     * @param resource|null $serverSocket
     */
    public function loop($serverSocket, WorkerServer $server): void
    {
        $this->running = true;
        $app = $server->getApp();

        // 1. If official spiral/roadrunner-worker package is installed:
        if (class_exists(\Spiral\RoadRunner\Worker::class)) {
            $this->runOfficialWorker($app, $server);
            return;
        }

        // 2. Pure zero-dependency Goridge pipe loop:
        $this->runBuiltinWorker($app, $server);
    }

    /**
     * Run worker using official Spiral\RoadRunner package.
     */
    private function runOfficialWorker(Application $app, WorkerServer $server): void
    {
        /** @var \Spiral\RoadRunner\Worker $worker */
        $worker = \Spiral\RoadRunner\Worker::create();

        while ($this->running && ($payload = $worker->waitPayload()) !== null) {
            try {
                $ctx = json_decode($payload->header, true) ?: [];
                $request = self::convertContext($ctx, (string) $payload->body);

                $response = $app->handle($request);
                $server->incrementRequestCount();

                $headers = $response->headers();
                $cookieHeaders = $response->cookieHeaders();
                if ($cookieHeaders !== []) {
                    $headers['Set-Cookie'] = $cookieHeaders;
                }

                $respContext = json_encode([
                    'status'  => $response->getStatus(),
                    'headers' => $headers,
                ], JSON_THROW_ON_ERROR);

                $worker->respond(new \Spiral\RoadRunner\Payload($response->getBody(), $respContext));

                if ($server->shouldRecycle()) {
                    $worker->stop();
                    break;
                }
            } catch (Throwable $e) {
                $worker->error((string) $e);
            }
        }
    }

    /**
     * Run worker using Kabuto's built-in zero-dependency Goridge pipe protocol.
     */
    private function runBuiltinWorker(Application $app, WorkerServer $server): void
    {
        $stdin = fopen('php://stdin', 'rb');
        $stdout = fopen('php://stdout', 'wb');

        if (!is_resource($stdin) || !is_resource($stdout)) {
            throw new RuntimeException('Failed to open php://stdin or php://stdout for RoadRunner pipe relay.');
        }

        while ($this->running) {
            $frame = self::readFrame($stdin);
            if ($frame === null) {
                break;
            }

            [$flags, $payload] = $frame;

            // Stop/Ping control frame
            if (($flags & 0x01) !== 0) {
                break;
            }

            try {
                [$ctx, $body] = self::parsePayload($payload);
                $request = self::convertContext($ctx, $body);

                $response = $app->handle($request);
                $server->incrementRequestCount();

                $respPayload = self::buildResponsePayload($response);
                self::writeFrame($stdout, 0, $respPayload);

                if ($server->shouldRecycle()) {
                    // Send stop signal frame to RoadRunner
                    self::writeFrame($stdout, 0x01, '{"stop":true}');
                    break;
                }
            } catch (Throwable $e) {
                $errJson = json_encode(['error' => $e->getMessage()]);
                self::writeFrame($stdout, 0x04, (string) $errJson);
            }
        }

        @fclose($stdin);
        @fclose($stdout);
    }

    /**
     * Read a complete Goridge binary frame from stream.
     *
     * @param resource $stream
     * @return array{0: int, 1: string}|null
     */
    public static function readFrame($stream): ?array
    {
        $header = '';
        while (strlen($header) < 13) {
            $chunk = @fread($stream, 13 - strlen($header));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $header .= $chunk;
        }

        $meta = unpack('Cflags/Psize/Vcrc', $header);
        if ($meta === false) {
            return null;
        }

        $flags = (int) $meta['flags'];
        $size = (int) $meta['size'];

        $payload = '';
        while (strlen($payload) < $size) {
            $chunk = @fread($stream, min(65536, $size - strlen($payload)));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $payload .= $chunk;
        }

        return [$flags, $payload];
    }

    /**
     * Write a Goridge binary frame to stream.
     *
     * @param resource $stream
     */
    public static function writeFrame($stream, int $flags, string $payload): bool
    {
        $size = strlen($payload);
        $crc = crc32($payload);
        $header = pack('CPV', $flags, $size, $crc);

        $written = @fwrite($stream, $header . $payload);
        @fflush($stream);

        return $written !== false;
    }

    /**
     * Parse RoadRunner HTTP payload into [context, body].
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    public static function parsePayload(string $payload): array
    {
        if (strlen($payload) < 4) {
            return [[], ''];
        }

        $ctxLen = unpack('Vlen', substr($payload, 0, 4))['len'] ?? 0;
        $contextJson = substr($payload, 4, $ctxLen);
        $body = substr($payload, 4 + $ctxLen);

        $context = json_decode($contextJson, true) ?: [];

        return [$context, $body];
    }

    /**
     * Build response payload for RoadRunner.
     */
    public static function buildResponsePayload(Response $response): string
    {
        $headers = $response->headers();
        $cookieHeaders = $response->cookieHeaders();
        if ($cookieHeaders !== []) {
            $headers['Set-Cookie'] = $cookieHeaders;
        }

        $contextJson = json_encode([
            'status'  => $response->getStatus(),
            'headers' => $headers,
        ], JSON_THROW_ON_ERROR);

        $body = $response->getBody();

        return pack('V', strlen($contextJson)) . $contextJson . $body;
    }

    /**
     * Convert RoadRunner context & body to a Kabuto Request.
     *
     * @param array<string, mixed> $ctx
     */
    public static function convertContext(array $ctx, string $body = ''): Request
    {
        $method = strtoupper((string) ($ctx['method'] ?? 'GET'));
        $uri = (string) ($ctx['uri'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $rawQuery = (string) ($ctx['rawQuery'] ?? '');
        $query = [];

        if ($rawQuery !== '') {
            parse_str($rawQuery, $query);
        } else {
            $qStr = (string) parse_url($uri, PHP_URL_QUERY);
            if ($qStr !== '') {
                parse_str($qStr, $query);
            }
        }

        $headers = [];
        $serverVars = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI'    => $uri,
            'SERVER_PROTOCOL'=> (string) ($ctx['protocol'] ?? 'HTTP/1.1'),
            'REMOTE_ADDR'    => (string) ($ctx['remoteAddr'] ?? '127.0.0.1'),
        ];

        foreach ((array) ($ctx['headers'] ?? []) as $name => $vals) {
            $valStr = is_array($vals) ? implode(', ', $vals) : (string) $vals;
            $keyLower = strtolower((string) $name);
            $headers[$keyLower] = $valStr;
            $serverVars['HTTP_' . strtoupper(str_replace('-', '_', $keyLower))] = $valStr;
        }

        $cookies = (array) ($ctx['cookies'] ?? []);

        $parsedBody = (array) ($ctx['parsed'] ?? []);
        if ($parsedBody === [] && str_contains($headers['content-type'] ?? '', 'application/x-www-form-urlencoded')) {
            parse_str($body, $parsedBody);
        }

        return new Request(
            $method,
            $path,
            $query,
            $parsedBody,
            $headers,
            $serverVars,
            $cookies,
            $body
        );
    }

    public function stop(): void
    {
        $this->running = false;
    }
}
