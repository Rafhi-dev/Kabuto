<?php

declare(strict_types=1);

namespace Kabuto\Runtime;

use Closure;
use Kabuto\Application;
use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Runtime\Drivers\NativeDriver;
use Kabuto\Runtime\Drivers\OpenSwooleDriver;
use Kabuto\Runtime\Drivers\RevoltDriver;
use Kabuto\Runtime\Drivers\RuntimeDriverInterface;
use RuntimeException;
use Throwable;

/**
 * High-performance, zero-dependency persistent HTTP worker server for Kabuto.
 *
 * Keeps the framework in memory across requests, utilizing non-blocking
 * socket streams (stream_select or optional Revolt event loop) and multi-process
 * clustering via pcntl_fork (Unix) or proc_open (Windows) to deliver high RPS.
 */
final class WorkerServer
{
    private string $host;
    private int $port;
    private int $workers;
    private int $maxRequests;
    private string $publicDir;
    private string $driverName;
    private RuntimeDriverInterface $driverInstance;
    private bool $isInternalWorker;
    private bool $running = false;
    private bool $isClusterWorker = false;
    private int $requestCount = 0;
    private ?ProcClusterManager $clusterManager = null;

    private const MAX_HEADER_BYTES = 64 * 1024;
    private const MAX_BODY_BYTES = 10 * 1024 * 1024;

    private static int $lastDateSecond = 0;
    private static string $cachedDateHeader = '';

    /** @var array<int,string> */
    private static array $statusLines = [
        200 => "HTTP/1.1 200 OK\r\n",
        201 => "HTTP/1.1 201 Created\r\n",
        204 => "HTTP/1.1 204 No Content\r\n",
        301 => "HTTP/1.1 301 Moved Permanently\r\n",
        302 => "HTTP/1.1 302 Found\r\n",
        304 => "HTTP/1.1 304 Not Modified\r\n",
        400 => "HTTP/1.1 400 Bad Request\r\n",
        401 => "HTTP/1.1 401 Unauthorized\r\n",
        403 => "HTTP/1.1 403 Forbidden\r\n",
        404 => "HTTP/1.1 404 Not Found\r\n",
        405 => "HTTP/1.1 405 Method Not Allowed\r\n",
        422 => "HTTP/1.1 422 Unprocessable Entity\r\n",
        429 => "HTTP/1.1 429 Too Many Requests\r\n",
        500 => "HTTP/1.1 500 Internal Server Error\r\n",
        502 => "HTTP/1.1 502 Bad Gateway\r\n",
        503 => "HTTP/1.1 503 Service Unavailable\r\n",
    ];

    /**
     * Get a cached HTTP Date header value updated once per second.
     */
    public static function getHttpDate(): string
    {
        $now = time();
        if ($now !== self::$lastDateSecond) {
            self::$lastDateSecond = $now;
            self::$cachedDateHeader = gmdate('D, d M Y H:i:s', $now) . ' GMT';
        }

        return self::$cachedDateHeader;
    }

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
        string $driver = 'auto',
        bool $isInternalWorker = false,
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->workers = is_int($workers) && $workers > 0 ? $workers : self::resolveWorkers($workers);
        $this->maxRequests = max(0, $maxRequests);
        $this->publicDir = $publicDir ?? ($this->app->basePath() . '/public');
        $this->driverName = strtolower(trim($driver));
        $this->driverInstance = self::resolveDriver($this->driverName);
        $this->isInternalWorker = $isInternalWorker;
    }

    /**
     * Resolve the runtime event loop driver.
     */
    public static function resolveDriver(string $driver = 'auto'): RuntimeDriverInterface
    {
        $d = strtolower(trim($driver));

        if ($d === 'openswoole') {
            if (!OpenSwooleDriver::isSupported()) {
                throw new RuntimeException(
                    'The OpenSwoole runtime driver requires the "openswoole" PHP extension. ' .
                    'Install it via PECL: pecl install openswoole'
                );
            }
            return new OpenSwooleDriver();
        }

        if ($d === 'revolt') {
            if (!RevoltDriver::isSupported()) {
                throw new RuntimeException(
                    'The Revolt runtime driver requires the "revolt/event-loop" package. ' .
                    'Install it via Composer: composer require revolt/event-loop'
                );
            }
            return new RevoltDriver();
        }

        if ($d === 'auto' && RevoltDriver::isSupported()) {
            return new RevoltDriver();
        }

        return new NativeDriver();
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

    public function incrementRequestCount(): int
    {
        return ++$this->requestCount;
    }

    public function resetRequestCount(): void
    {
        $this->requestCount = 0;
    }

    public function shouldRecycle(): bool
    {
        return $this->maxRequests > 0 && $this->requestCount >= $this->maxRequests;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function isClusterWorker(): bool
    {
        return $this->isClusterWorker;
    }

    public function setIsClusterWorker(bool $val): void
    {
        $this->isClusterWorker = $val;
    }

    public function getDriver(): RuntimeDriverInterface
    {
        return $this->driverInstance;
    }

    public function getDriverName(): string
    {
        return $this->driverInstance->name();
    }

    public function getApp(): Application
    {
        return $this->app;
    }

    public function getPublicDir(): string
    {
        return $this->publicDir;
    }

    /**
     * Start the worker server.
     *
     * @param ?Closure(string):void $onStart Callback fired when server is ready to accept connections.
     */
    public function start(?Closure $onStart = null): void
    {
        if ($this->driverInstance instanceof OpenSwooleDriver) {
            $this->running = true;
            $this->driverInstance->startServer($this, $onStart);
            return;
        }

        $context = stream_context_create([
            'socket' => [
                'so_reuseport' => 1,
                'so_reuseaddr' => 1,
                'backlog' => 4096,
                'tcp_nodelay' => true,
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
            throw new RuntimeException(sprintf('Failed to bind server on [%s]: %s (Code: %d)', $address, $errstr, $errno));
        }

        stream_set_blocking($serverSocket, false);

        $this->running = true;

        // If this is an internal child worker spawned by ProcClusterManager:
        if ($this->isInternalWorker) {
            $this->isClusterWorker = true;
            $this->driverInstance->loop($serverSocket, $this);
            return;
        }

        // Multi-process cluster for Unix / Linux / macOS (pcntl)
        if ($this->workers > 1 && function_exists('pcntl_fork')) {
            if ($onStart !== null) {
                $onStart($address);
            }
            $this->runPcntlCluster($serverSocket);
            return;
        }

        // Multi-process cluster for Windows / environments without pcntl (proc_open)
        if ($this->workers > 1) {
            $this->clusterManager = new ProcClusterManager(
                $this->host,
                $this->port,
                $this->workers,
                $this->maxRequests,
                $this->app->basePath(),
                $this->driverName
            );
            $this->clusterManager->start($serverSocket, $onStart);
            return;
        }

        // Single process event loop
        if ($onStart !== null) {
            $onStart($address);
        }
        $this->driverInstance->loop($serverSocket, $this);
    }

    /**
     * Compatibility wrapper for single-worker event loop.
     *
     * @param resource $serverSocket
     */
    public function eventLoop($serverSocket): void
    {
        $this->driverInstance->loop($serverSocket, $this);
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
                'statusCode' => 500,
                'error'      => 'Internal Server Error',
                'message'    => $e->getMessage(),
            ]);
            return $response;
        }
    }

    /**
     * Try to serve a static file from public/ directly.
     */
    public function serveStaticFile(string $uri): ?Response
    {
        $decoded = rawurldecode($uri);
        $clean = preg_replace('#/+#', '/', $decoded) ?? $decoded;

        if (str_contains($clean, '..') || str_contains($clean, "\0")) {
            return null;
        }

        $fullPath = rtrim($this->publicDir, '/\\') . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $clean), '\\/');

        if (!is_file($fullPath)) {
            return null;
        }

        $realPublic = realpath($this->publicDir);
        $realFile = realpath($fullPath);

        if ($realPublic === false || $realFile === false || !str_starts_with($realFile, $realPublic)) {
            return null;
        }

        $ext = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
        $contentType = self::$mimeTypes[$ext] ?? 'application/octet-stream';

        $content = @file_get_contents($realFile);
        if ($content === false) {
            return null;
        }

        $res = new Response();
        $res->status(200);
        $res->header('Content-Type', $contentType);
        $res->header('Content-Length', (string) strlen($content));
        $res->header('Cache-Control', 'public, max-age=3600');
        $res->send($content);

        return $res;
    }

    /**
     * Check if a buffer contains a full, well-formed HTTP request.
     */
    public function isRequestComplete(string $buffer, int &$requestLength): bool
    {
        $requestLength = 0;

        $delimiterPos = strpos($buffer, "\r\n\r\n");
        $delimiterLen = 4;

        if ($delimiterPos === false) {
            $delimiterPos = strpos($buffer, "\n\n");
            $delimiterLen = 2;
        }

        if ($delimiterPos === false) {
            if (strlen($buffer) > self::MAX_HEADER_BYTES) {
                $requestLength = strlen($buffer);
                return true;
            }
            return false;
        }

        $headerSection = substr($buffer, 0, $delimiterPos);

        $bodyLength = 0;
        $clPos = stripos($headerSection, 'content-length:');
        if ($clPos !== false) {
            $numStart = $clPos + 15;
            $len = strlen($headerSection);
            while ($numStart < $len && ($headerSection[$numStart] === ' ' || $headerSection[$numStart] === "\t")) {
                $numStart++;
            }
            $bodyLength = (int) substr($headerSection, $numStart, 16);
        }

        if (strlen($headerSection) > self::MAX_HEADER_BYTES || $bodyLength > self::MAX_BODY_BYTES) {
            $requestLength = strlen($buffer);
            return true;
        }

        $totalLength = $delimiterPos + $delimiterLen + $bodyLength;

        if (strlen($buffer) >= $totalLength) {
            $requestLength = $totalLength;
            return true;
        }

        return false;
    }

    /**
     * Serialize a Kabuto Response object into an HTTP/1.1 wire protocol string.
     */
    public function formatHttpResponse(Response $response, bool $keepAlive = true): string
    {
        $status = $response->getStatus();
        $output = self::$statusLines[$status] ?? sprintf("HTTP/1.1 %d %s\r\n", $status, self::getReasonPhrase($status));
        $headers = $response->headers();
        $body = $response->getBody();

        if (!isset($headers['Date'])) {
            $headers['Date'] = self::getHttpDate();
        }

        if (!isset($headers['Server'])) {
            $headers['Server'] = 'Kabuto-Worker/1.0';
        }

        $headers['Connection'] = $keepAlive ? 'keep-alive' : 'close';

        if (!isset($headers['Content-Length'])) {
            $headers['Content-Length'] = (string) strlen($body);
        }

        foreach ($headers as $k => $v) {
            $output .= $k . ': ' . $v . "\r\n";
        }

        foreach ($response->cookieHeaders() as $ch) {
            $output .= 'Set-Cookie: ' . $ch . "\r\n";
        }

        $output .= "\r\n";
        $output .= $body;

        return $output;
    }

    private static function getReasonPhrase(int $status): string
    {
        return match ($status) {
            200 => 'OK',
            201 => 'Created',
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
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            default => 'Unknown',
        };
    }

    /**
     * Spawns multi-worker processes when pcntl is available on Unix.
     *
     * @param resource $serverSocket
     */
    private function runPcntlCluster($serverSocket): void
    {
        $children = [];

        for ($i = 0; $i < $this->workers; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Failed to fork worker process.');
            }

            if ($pid === 0) {
                // Child worker process
                $this->isClusterWorker = true;
                $this->driverInstance->loop($serverSocket, $this);
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
                        $this->driverInstance->loop($serverSocket, $this);
                        exit(0);
                    }
                    $children[$newPid] = true;
                }
            }
        }
    }

    public function stop(): void
    {
        $this->running = false;
        if ($this->clusterManager !== null) {
            $this->clusterManager->stop();
        }
        if ($this->driverInstance !== null) {
            $this->driverInstance->stop();
        }
    }
}
