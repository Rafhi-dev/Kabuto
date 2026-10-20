<?php

declare(strict_types=1);

namespace Kabuto\Runtime;

use Closure;
use Kabuto\Application;
use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Throwable;

/**
 * High-performance, zero-dependency persistent HTTP worker server for Kabuto.
 *
 * Keeps the framework in memory across requests, utilizing non-blocking
 * socket streams (stream_select) and optional multi-process clustering
 * via pcntl_fork to deliver high RPS and low memory usage comparable to Go.
 */
final class WorkerServer
{
    private string $host;
    private int $port;
    private int $workers;
    private int $maxRequests;
    private string $publicDir;
    private bool $running = false;
    private bool $isClusterWorker = false;
    private int $requestCount = 0;

    private const MAX_HEADER_BYTES = 64 * 1024;
    private const MAX_BODY_BYTES = 10 * 1024 * 1024;

    /** @var array<int,resource> */
    private array $connections = [];

    /** @var array<int,string> */
    private array $buffers = [];

    /** @var array<int,array{peer:string,time:int}> */
    private array $meta = [];

    /** @var array<string,string> */
    private static array $mimeTypes = [
        'html'  => 'text/html; charset=utf-8',
        'htm'   => 'text/html; charset=utf-8',
        'css'   => 'text/css; charset=utf-8',
        'js'    => 'application/javascript; charset=utf-8',
        'json'  => 'application/json; charset=utf-8',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon',
        'webp'  => 'image/webp',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'txt'   => 'text/plain; charset=utf-8',
        'pdf'   => 'application/pdf',
        'xml'   => 'application/xml; charset=utf-8',
    ];

    public function __construct(
        private readonly Application $app,
        string $host = '127.0.0.1',
        int $port = 8000,
        string|int $workers = 'auto',
        int $maxRequests = 100000,
        ?string $publicDir = null,
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->workers = is_int($workers) && $workers > 0 ? $workers : self::resolveWorkers($workers);
        $this->maxRequests = max(0, $maxRequests);
        $this->publicDir = $publicDir ?? ($this->app->basePath() . '/public');
    }

    /**
     * Detect the number of available CPU cores cross-platform.
     */
    public static function detectCpuCores(): int
    {
        // 1. Environment variable (Windows & container environments)
        $envCores = $_SERVER['NUMBER_OF_PROCESSORS'] ?? $_ENV['NUMBER_OF_PROCESSORS'] ?? getenv('NUMBER_OF_PROCESSORS');
        if ($envCores !== false && is_numeric($envCores) && (int) $envCores > 0) {
            return (int) $envCores;
        }

        // 2. Linux /proc/cpuinfo
        if (is_file('/proc/cpuinfo')) {
            $cpuinfo = @file_get_contents('/proc/cpuinfo');
            if ($cpuinfo !== false && preg_match_all('/^processor\s*:/m', $cpuinfo, $matches)) {
                $count = count($matches[0]);
                if ($count > 0) {
                    return $count;
                }
            }
        }

        // 3. Command execution fallback
        if (function_exists('shell_exec')) {
            if (DIRECTORY_SEPARATOR === '\\') {
                $cmdOutput = @shell_exec('echo %NUMBER_OF_PROCESSORS%');
            } else {
                $cmdOutput = @shell_exec('nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null');
            }
            if ($cmdOutput !== null && is_numeric(trim((string) $cmdOutput)) && (int) trim((string) $cmdOutput) > 0) {
                return (int) trim((string) $cmdOutput);
            }
        }

        return 1;
    }

    /**
     * Resolve worker count from numeric value or "auto" expressions.
     * Supports:
     *   - 4 => 4
     *   - '4' => 4
     *   - 'auto' => detectCpuCores() * multiplier
     *   - 'auto:2' or 'auto*2' => detectCpuCores() * 2
     */
    public static function resolveWorkers(string|int $value = 'auto', ?int $multiplier = null): int
    {
        if (is_int($value)) {
            return max(1, $value);
        }

        $trimmed = trim(strtolower($value));

        if (is_numeric($trimmed)) {
            return max(1, (int) $trimmed);
        }

        $mult = $multiplier;

        if (preg_match('/^auto(?:[:*x](\d+))?$/', $trimmed, $matches)) {
            if (isset($matches[1]) && $matches[1] !== '') {
                $mult = (int) $matches[1];
            }
        }

        if ($mult === null || $mult <= 0) {
            $envMult = $_SERVER['SERVER_WORKER_MULTIPLIER'] ?? $_ENV['SERVER_WORKER_MULTIPLIER'] ?? getenv('SERVER_WORKER_MULTIPLIER');
            $mult = ($envMult !== false && is_numeric($envMult) && (int) $envMult > 0) ? (int) $envMult : 1;
        }

        $cores = self::detectCpuCores();

        return max(1, $cores * $mult);
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getWorkers(): int
    {
        return $this->workers;
    }

    public function getMaxRequests(): int
    {
        return $this->maxRequests;
    }

    public function getRequestCount(): int
    {
        return $this->requestCount;
    }

    /**
     * Start the worker server.
     *
     * @param ?Closure(string):void $onStart Callback fired when server is ready to accept connections.
     */
    public function start(?Closure $onStart = null): void
    {
        $context = stream_context_create([
            'socket' => [
                'so_reuseport' => 1,
                'backlog' => 1024,
            ],
        ]);

        $address = sprintf('tcp://%s:%d', $this->host, $this->port);
        $serverSocket = @stream_socket_server(
            $address,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );

        if ($serverSocket === false) {
            throw new \RuntimeException(sprintf('Failed to bind server on [%s]: %s (Code: %d)', $address, $errstr, $errno));
        }

        stream_set_blocking($serverSocket, false);

        if ($onStart !== null) {
            $onStart($address);
        }

        $this->running = true;

        // Multi-process cluster for Unix / Linux / macOS
        if ($this->workers > 1 && function_exists('pcntl_fork')) {
            $this->runCluster($serverSocket);
            return;
        }

        // Single process event loop (Windows / Single worker)
        $this->eventLoop($serverSocket);
    }

    /**
     * Process a raw HTTP request and return a Kabuto Response object.
     */
    public function handleRawRequest(string $rawRequest, string $peer = ''): Response
    {
        try {
            $serverExtra = [];
            if ($peer !== '') {
                $parts = explode(':', $peer);
                $serverExtra['REMOTE_ADDR'] = $parts[0] ?? '';
                $serverExtra['REMOTE_PORT'] = $parts[1] ?? '';
            }

            $request = Request::fromRaw($rawRequest, $serverExtra);

            // Serve static files from public/ if matched
            if (in_array($request->method(), ['GET', 'HEAD'], true)) {
                $staticResponse = $this->serveStaticFile($request->path());
                if ($staticResponse !== null) {
                    return $staticResponse;
                }
            }

            return $this->app->handle($request);
        } catch (Throwable $e) {
            $response = new Response();
            $response->status(500);
            $response->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
            return $response;
        } finally {
            if ($this->app->container()->has(\Kabuto\Database\ConnectionManager::class)) {
                try {
                    $cm = $this->app->container()->get(\Kabuto\Database\ConnectionManager::class);
                    foreach ($cm->activeConnections() as $pdo) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                    }
                } catch (\Throwable) {
                }
            }
        }
    }

    /**
     * Format a Kabuto Response into an HTTP/1.1 wire string.
     */
    public function formatHttpResponse(Response $response, bool $keepAlive = true): string
    {
        $status = $response->getStatus();
        $statusText = match ($status) {
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            304 => 'Not Modified',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            422 => 'Unprocessable Entity',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            default => 'OK',
        };

        $body = $response->getBody();
        $headers = $response->headers();

        $hasContentType = false;
        foreach (array_keys($headers) as $k) {
            if (strcasecmp($k, 'content-type') === 0) {
                $hasContentType = true;
                break;
            }
        }
        if (!$hasContentType && $status !== 204) {
            $headers['Content-Type'] = 'text/html; charset=utf-8';
        }

        $headers['Content-Length'] = (string) strlen($body);
        $headers['Connection'] = $keepAlive ? 'keep-alive' : 'close';
        $headers['Server'] = 'Kabuto-Worker/1.0';

        $output = sprintf("HTTP/1.1 %d %s\r\n", $status, $statusText);
        foreach ($headers as $key => $val) {
            if (strcasecmp($key, 'Set-Cookie') === 0) {
                continue;
            }
            $output .= sprintf("%s: %s\r\n", $key, $val);
        }
        foreach ($response->cookieHeaders() as $cookieHeader) {
            $output .= sprintf("Set-Cookie: %s\r\n", $cookieHeader);
        }
        $output .= "\r\n";
        $output .= $body;

        return $output;
    }

    /**
     * Determine if a buffer contains a complete HTTP request and find its boundary.
     *
     * @param ?int $requestLength Output parameter: byte length of the complete request
     */
    public function isRequestComplete(string $buffer, ?int &$requestLength = null): bool
    {
        if (strlen($buffer) > self::MAX_HEADER_BYTES + self::MAX_BODY_BYTES) {
            return false;
        }
        $delimiterPos = strpos($buffer, "\r\n\r\n");
        $delimiterLen = 4;

        if ($delimiterPos === false) {
            $delimiterPos = strpos($buffer, "\n\n");
            $delimiterLen = 2;
        }

        if ($delimiterPos === false) {
            return false;
        }

        $headerSection = substr($buffer, 0, $delimiterPos);
        $bodyLength = 0;

        if (preg_match('/content-length:\s*(\d+)/i', $headerSection, $match)) {
            $bodyLength = (int) $match[1];
        }

        if (strlen($headerSection) > self::MAX_HEADER_BYTES || $bodyLength > self::MAX_BODY_BYTES) {
            return false;
        }

        $totalLength = $delimiterPos + $delimiterLen + $bodyLength;

        if (strlen($buffer) >= $totalLength) {
            $requestLength = $totalLength;
            return true;
        }

        return false;
    }

    /**
     * Attempt to serve a static file from public/ directory.
     */
    public function serveStaticFile(string $path): ?Response
    {
        if ($this->publicDir === '' || !is_dir($this->publicDir)) {
            return null;
        }

        $cleanPath = ltrim(parse_url($path, PHP_URL_PATH) ?: '', '/');
        if ($cleanPath === '') {
            return null;
        }

        $candidate = $this->publicDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cleanPath);
        $real = realpath($candidate);
        $realPublic = realpath($this->publicDir);

        $rootPrefix = $realPublic === false ? '' : rtrim($realPublic, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($real === false || $realPublic === false || !str_starts_with($real, $rootPrefix)) {
            return null;
        }

        if (!is_file($real)) {
            return null;
        }

        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $mime = self::$mimeTypes[$ext] ?? 'application/octet-stream';

        $response = new Response();
        $response->status(200);
        $response->header('Content-Type', $mime);
        $response->send((string) file_get_contents($real));

        return $response;
    }

    /**
     * The core event loop: handles socket select, accepts clients, parses and emits.
     *
     * @param resource $serverSocket
     */
    private function eventLoop($serverSocket): void
    {
        while ($this->running) {
            $read = $this->connections;
            $read[(int) $serverSocket] = $serverSocket;
            $write = null;
            $except = null;

            $numChanged = @stream_select($read, $write, $except, 1);

            if ($numChanged === false) {
                // When k6 or clients abort sockets abruptly, purge invalid/dead sockets and continue
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
            if (isset($read[(int) $serverSocket])) {
                while (($client = @stream_socket_accept($serverSocket, 0, $peerName)) !== false) {
                    stream_set_blocking($client, false);

                    // Prevent socket descriptor saturation under extreme load
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
                unset($read[(int) $serverSocket]);
            }

            // Process data from connected clients
            foreach ($read as $id => $client) {
                $data = @fread($client, 65536);

                if ($data === false || $data === '') {
                    if (!is_resource($client) || feof($client)) {
                        $this->closeConnection($id);
                    }
                    continue;
                }

                $this->buffers[$id] .= $data;
                $this->meta[$id]['time'] = time();

                $reqLength = 0;
                while ($this->isRequestComplete($this->buffers[$id], $reqLength)) {
                    $rawRequest = substr($this->buffers[$id], 0, $reqLength);
                    $this->buffers[$id] = substr($this->buffers[$id], $reqLength);

                    $keepAlive = !preg_match('/connection:\s*close/i', $rawRequest);
                    $response = $this->handleRawRequest($rawRequest, $this->meta[$id]['peer'] ?? '');
                    $wire = $this->formatHttpResponse($response, $keepAlive);

                    $written = @fwrite($client, $wire);
                    if ($written === false || $written < strlen($wire)) {
                        $this->closeConnection($id);
                        break;
                    }

                    $this->requestCount++;

                    if (!$keepAlive) {
                        $this->closeConnection($id);
                        break;
                    }

                    if ($this->maxRequests > 0 && $this->requestCount >= $this->maxRequests) {
                        // In multi-process cluster mode, exit child worker so master process respawns it
                        if ($this->isClusterWorker) {
                            $this->running = false;
                            break 2;
                        }

                        // In single-process / standalone mode (e.g. Windows), perform soft-recycle:
                        // run garbage collection and reset request counter without killing the server
                        gc_collect_cycles();
                        $this->requestCount = 0;
                    }
                }
            }

            // Trigger garbage collection periodically
            if ($this->requestCount > 0 && ($this->requestCount % 100) === 0) {
                gc_collect_cycles();
            }

            $this->cleanIdleConnections(5);
        }

        $this->closeAll();
        if (is_resource($serverSocket)) {
            @fclose($serverSocket);
        }
    }

    /**
     * Spawns multi-worker processes when pcntl is available on Unix.
     *
     * @param resource $serverSocket
     */
    private function runCluster($serverSocket): void
    {
        $children = [];

        for ($i = 0; $i < $this->workers; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new \RuntimeException('Failed to fork worker process.');
            }

            if ($pid === 0) {
                // Child worker process
                $this->isClusterWorker = true;
                $this->eventLoop($serverSocket);
                exit(0);
            }

            $children[$pid] = true;
        }

        // Master process monitors children and restarts any that exit
        while ($this->running && count($children) > 0) {
            $pid = pcntl_wait($status);
            if ($pid > 0) {
                unset($children[$pid]);
                if ($this->running) {
                    $newPid = pcntl_fork();
                    if ($newPid === 0) {
                        $this->isClusterWorker = true;
                        $this->eventLoop($serverSocket);
                        exit(0);
                    }
                    $children[$newPid] = true;
                }
            }
        }
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
}
