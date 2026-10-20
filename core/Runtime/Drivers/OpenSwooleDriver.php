<?php

declare(strict_types=1);

namespace Kabuto\Runtime\Drivers;

use Closure;
use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Runtime\WorkerServer;
use OpenSwoole\HTTP\Request as SwooleRequest;
use OpenSwoole\HTTP\Response as SwooleResponse;
use OpenSwoole\HTTP\Server as SwooleHttpServer;
use RuntimeException;
use Throwable;

final class OpenSwooleDriver implements RuntimeDriverInterface
{
    private bool $running = false;

    /** @var mixed */
    private $swooleServer = null;

    public function name(): string
    {
        return 'OpenSwoole (C Coroutine Engine)';
    }

    public static function isSupported(): bool
    {
        return extension_loaded('openswoole') && class_exists('OpenSwoole\\HTTP\\Server');
    }

    /**
     * Start the standalone OpenSwoole HTTP server with coroutine & worker support.
     *
     * @param ?Closure(string):void $onStart
     */
    public function startServer(WorkerServer $server, ?Closure $onStart = null): void
    {
        if (!self::isSupported()) {
            throw new RuntimeException(
                'The OpenSwoole runtime driver requires the "openswoole" PHP extension. ' .
                'Install it via PECL: pecl install openswoole'
            );
        }

        $host = $server->getHost();
        $port = $server->getPort();
        $publicDir = $server->getPublicDir();
        $app = $server->getApp();

        $this->swooleServer = new SwooleHttpServer($host, $port);
        $this->running = true;

        $settings = [
            'worker_num'            => $server->getWorkers(),
            'enable_coroutine'      => true,
            'enable_static_handler' => is_dir($publicDir),
            'document_root'         => $publicDir,
        ];

        if ($server->getMaxRequests() > 0) {
            $settings['max_request'] = $server->getMaxRequests();
        }

        $this->swooleServer->set($settings);

        $this->swooleServer->on('Start', static function () use ($onStart, $host, $port): void {
            if ($onStart !== null) {
                $onStart(sprintf('http://%s:%d', $host, $port));
            }
        });

        $this->swooleServer->on('Request', function ($swooleReq, $swooleRes) use ($app, $server): void {
            try {
                $request = self::convertRequest($swooleReq);
                $response = $app->handle($request);
                self::emitResponse($response, $swooleRes);
                $server->incrementRequestCount();
            } catch (Throwable $e) {
                $swooleRes->status(500);
                $swooleRes->header('Content-Type', 'application/json');
                $swooleRes->end(json_encode([
                    'statusCode' => 500,
                    'error'      => 'Internal Server Error',
                    'message'    => $e->getMessage(),
                ], JSON_THROW_ON_ERROR));
            }
        });

        $this->swooleServer->start();
    }

    /**
     * Fallback loop method if called from a standard server socket context.
     *
     * @param resource $serverSocket
     */
    public function loop($serverSocket, WorkerServer $server): void
    {
        $this->startServer($server);
    }

    public function stop(): void
    {
        $this->running = false;
        if ($this->swooleServer !== null && method_exists($this->swooleServer, 'shutdown')) {
            @$this->swooleServer->shutdown();
            $this->swooleServer = null;
        }
    }

    /**
     * Convert an OpenSwoole HTTP request to a Kabuto Request.
     *
     * @param mixed $swooleReq
     */
    public static function convertRequest($swooleReq): Request
    {
        $serverVars = array_change_key_case((array) ($swooleReq->server ?? []), CASE_UPPER);
        $method = strtoupper((string) ($serverVars['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($serverVars['REQUEST_URI'] ?? '/');

        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $query = (array) ($swooleReq->get ?? []);
        $rawBody = method_exists($swooleReq, 'rawContent') ? (string) ($swooleReq->rawContent() ?? '') : '';
        $body = (array) ($swooleReq->post ?? []);

        $headers = [];
        foreach ((array) ($swooleReq->header ?? []) as $k => $v) {
            $keyLower = strtolower((string) $k);
            $headers[$keyLower] = (string) $v;
            $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $keyLower));
            $serverVars[$serverKey] = (string) $v;
        }

        $cookies = [];
        foreach ((array) ($swooleReq->cookie ?? []) as $k => $v) {
            $cookies[(string) $k] = (string) $v;
        }

        if (isset($headers['content-type'])) {
            $serverVars['CONTENT_TYPE'] = $headers['content-type'];
        }
        if (isset($headers['content-length'])) {
            $serverVars['CONTENT_LENGTH'] = $headers['content-length'];
        }

        $files = (array) ($swooleReq->files ?? []);

        return new Request(
            $method,
            $path,
            $query,
            $body,
            $headers,
            $serverVars,
            $cookies,
            $rawBody,
            $files,
        );
    }

    /**
     * Emit a Kabuto Response into an OpenSwoole HTTP response.
     *
     * @param mixed $swooleRes
     */
    public static function emitResponse(Response $response, $swooleRes): void
    {
        $swooleRes->status($response->getStatus());

        foreach ($response->headers() as $name => $value) {
            $swooleRes->header($name, (string) $value);
        }

        foreach ($response->getCookies() as $c) {
            if ($c instanceof \Kabuto\Http\Cookie) {
                $swooleRes->cookie(
                    $c->getName(),
                    $c->getValue(),
                    $c->getExpires(),
                    $c->getPath(),
                    $c->getDomain() ?? '',
                    $c->isSecure(),
                    $c->isHttpOnly(),
                    $c->getSameSite(),
                );
            } elseif (is_array($c)) {
                $swooleRes->cookie(
                    (string) ($c['name'] ?? ''),
                    (string) ($c['value'] ?? ''),
                    (int) ($c['expires'] ?? 0),
                    (string) ($c['path'] ?? '/'),
                    (string) ($c['domain'] ?? ''),
                    (bool) ($c['secure'] ?? false),
                    (bool) ($c['httponly'] ?? false),
                    (string) ($c['samesite'] ?? ''),
                );
            }
        }

        $swooleRes->end($response->getBody());
    }
}
