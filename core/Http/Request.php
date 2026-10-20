<?php

declare(strict_types=1);

namespace Kabuto\Http;

use Kabuto\Support\Crypto;
use Throwable;

final class Request
{
    /** @var array<string,string> */
    private array $params = [];

    /** @var array<string,mixed> */
    private array $attributes = [];

    private static int $idSequence = 0;
    private static ?string $workerPrefix = null;

    /**
     * Generate an ultra-fast non-blocking unique request ID.
     */
    public static function generateId(): string
    {
        if (self::$workerPrefix === null) {
            self::$workerPrefix = 'req_' . dechex(getmypid() & 0xFFFF) . '_';
        }

        return self::$workerPrefix . dechex((int) hrtime(true)) . '_' . dechex(++self::$idSequence);
    }

    /**
     * @param array<string,mixed>  $query
     * @param array<string,mixed>  $body
     * @param array<string,string> $headers
     * @param array<string,mixed>  $server
     * @param array<string,mixed>  $cookies
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private array $body = [],
        private readonly array $headers = [],
        private readonly array $server = [],
        private readonly array $cookies = [],
        private ?string $rawContent = null,
        private readonly array $files = [],
        /** @var list<string> */
        private readonly array $trustedProxies = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        // HTML forms only support GET/POST: allow overriding via a `_method`
        // field or the X-HTTP-Method-Override header.
        if ($method === 'POST') {
            $override = $_POST['_method'] ?? $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null;
            if (is_string($override) && $override !== '') {
                $method = strtoupper($override);
            }
        }

        return new self(
            $method,
            $path,
            $query,
            $_POST,
            self::collectHeaders($_SERVER),
            $_SERVER,
            $_COOKIE,
            null,
            self::collectFiles($_FILES ?? []),
        );
    }

    /**
     * Parse a raw HTTP/1.1 wire protocol request string into a Request object.
     *
     * @param array<string,mixed> $serverExtra e.g. REMOTE_ADDR, REMOTE_PORT
     */
    public static function fromRaw(string $rawBuffer, array $serverExtra = []): self
    {
        $delimiterPos = strpos($rawBuffer, "\r\n\r\n");
        $delimiterLen = 4;
        if ($delimiterPos === false) {
            $delimiterPos = strpos($rawBuffer, "\n\n");
            $delimiterLen = 2;
        }

        if ($delimiterPos !== false) {
            $headerSection = substr($rawBuffer, 0, $delimiterPos);
            $rawBody = substr($rawBuffer, $delimiterPos + $delimiterLen);
        } else {
            $headerSection = $rawBuffer;
            $rawBody = '';
        }

        $firstLineEnd = strpos($headerSection, "\r\n");
        $lineEndLen = 2;
        if ($firstLineEnd === false) {
            $firstLineEnd = strpos($headerSection, "\n");
            $lineEndLen = 1;
        }

        if ($firstLineEnd !== false) {
            $requestLine = substr($headerSection, 0, $firstLineEnd);
            $restHeaders = substr($headerSection, $firstLineEnd + $lineEndLen);
        } else {
            $requestLine = $headerSection;
            $restHeaders = '';
        }

        $requestLine = trim($requestLine);
        $sp1 = strpos($requestLine, ' ');
        if ($sp1 !== false) {
            $method = strtoupper(substr($requestLine, 0, $sp1));
            $sp2 = strpos($requestLine, ' ', $sp1 + 1);
            if ($sp2 !== false) {
                $uri = substr($requestLine, $sp1 + 1, $sp2 - $sp1 - 1);
                $protocol = trim(substr($requestLine, $sp2 + 1));
            } else {
                $uri = trim(substr($requestLine, $sp1 + 1));
                $protocol = 'HTTP/1.1';
            }
        } else {
            $method = 'GET';
            $uri = '/';
            $protocol = 'HTTP/1.1';
        }

        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            $path = parse_url($uri, PHP_URL_PATH) ?: '/';
            $queryString = parse_url($uri, PHP_URL_QUERY);
            $query = [];
            if ($queryString !== null && $queryString !== '') {
                parse_str($queryString, $query);
            }
        } else {
            $qPos = strpos($uri, '?');
            if ($qPos === false) {
                $path = $uri !== '' ? $uri : '/';
                $query = [];
            } else {
                $path = substr($uri, 0, $qPos);
                if ($path === '') {
                    $path = '/';
                }
                $queryString = substr($uri, $qPos + 1);
                $query = [];
                if ($queryString !== '') {
                    parse_str($queryString, $query);
                }
            }
        }

        $headers = [];
        $cookies = [];
        $server = $serverExtra;
        $server['REQUEST_METHOD'] = $method;
        $server['REQUEST_URI'] = $uri;
        $server['SERVER_PROTOCOL'] = $protocol !== '' ? $protocol : 'HTTP/1.1';

        if ($restHeaders !== '') {
            $lines = explode("\n", $restHeaders);
            foreach ($lines as $line) {
                $line = rtrim($line, "\r");
                if ($line === '') {
                    continue;
                }
                $colonPos = strpos($line, ':');
                if ($colonPos === false) {
                    continue;
                }

                $keyLower = strtolower(rtrim(substr($line, 0, $colonPos)));
                $valTrimmed = ltrim(substr($line, $colonPos + 1));
                $headers[$keyLower] = $valTrimmed;

                $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $keyLower));
                $server[$serverKey] = $valTrimmed;

                if ($keyLower === 'cookie') {
                    $cookiePairs = explode(';', $valTrimmed);
                    foreach ($cookiePairs as $cookiePair) {
                        $eqPos = strpos($cookiePair, '=');
                        if ($eqPos !== false) {
                            $cName = trim(substr($cookiePair, 0, $eqPos));
                            $cVal = trim(substr($cookiePair, $eqPos + 1));
                            $cookies[$cName] = urldecode($cVal);
                        }
                    }
                }
            }
        }

        if (isset($headers['content-type'])) {
            $server['CONTENT_TYPE'] = $headers['content-type'];
        }
        if (isset($headers['content-length'])) {
            $server['CONTENT_LENGTH'] = $headers['content-length'];
        }

        $body = [];
        $contentType = $headers['content-type'] ?? '';
        if ($rawBody !== '' && str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($rawBody, $body);
        }

        if ($method === 'POST') {
            $override = $body['_method'] ?? $headers['x-http-method-override'] ?? null;
            if (is_string($override) && $override !== '') {
                $method = strtoupper($override);
            }
        }

        return new self(
            $method,
            $path,
            $query,
            $body,
            $headers,
            $server,
            $cookies,
            $rawBody,
        );
    }

    /**
     * @param array<string,mixed> $server
     * @return array<string,string>
     */
    private static function collectHeaders(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[strtolower($name)] = (string) $value;
            }
        }
        if (isset($server['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $server['CONTENT_TYPE'];
        }
        if (isset($server['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $server['CONTENT_LENGTH'];
        }
        // PHP under Apache/CGI exposes Authorization differently.
        if (!isset($headers['authorization'])) {
            $authorization = $server['REDIRECT_HTTP_AUTHORIZATION'] ?? $server['HTTP_AUTHORIZATION'] ?? null;
            if (is_string($authorization) && $authorization !== '') {
                $headers['authorization'] = $authorization;
            }
        }
        return $headers;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function is(string $method): bool
    {
        return strtoupper($method) === $this->method;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }
        return $this->query[$key] ?? $default;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    /** @return array<string,mixed> */
    public function body(): array
    {
        return $this->body;
    }

    /** @param array<string,mixed> $body */
    public function setBody(array $body): void
    {
        $this->body = $body;
    }

    public function rawBody(): string
    {
        return $this->rawContent ?? (string) @file_get_contents('php://input');
    }

    public function header(string $key, ?string $default = null): ?string
    {
        return $this->headers[strtolower($key)] ?? $default;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function cookie(string $key, ?string $default = null, bool $decrypt = false): ?string
    {
        $value = $this->cookies[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        if ($decrypt) {
            try {
                return Crypto::decrypt($value);
            } catch (Throwable) {
                return $default;
            }
        }

        return (string) $value;
    }

    public function hasCookie(string $key): bool
    {
        return isset($this->cookies[$key]);
    }

    /** @return array<string,mixed> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function param(string $key, ?string $default = null): ?string
    {
        return $this->params[$key] ?? $default;
    }

    /** @return array<string,string> */
    public function params(): array
    {
        return $this->params;
    }

    /** @param array<string,string> $params */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Get the authenticated user payload, or a specific field of it.
     */
    public function user(?string $key = null, mixed $default = null): mixed
    {
        $user = $this->attributes['user'] ?? null;

        if ($key === null) {
            return $user;
        }

        if (is_array($user)) {
            return $user[$key] ?? $default;
        }

        if (is_object($user)) {
            return $user->{$key} ?? $default;
        }

        return $default;
    }

    /**
     * Check if the authenticated user has a specific role (or one of the given roles).
     *
     * @param string|list<string> $roles
     */
    public function hasRole(string|array $roles): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $checkRoles = is_array($roles) ? $roles : [$roles];
        $userRoles = [];

        foreach (['role', 'roles'] as $claim) {
            $val = is_array($user) ? ($user[$claim] ?? null) : ($user->{$claim} ?? null);
            if (is_string($val) && $val !== '') {
                $userRoles[] = $val;
            } elseif (is_array($val)) {
                foreach ($val as $r) {
                    if (is_string($r) && $r !== '') {
                        $userRoles[] = $r;
                    }
                }
            }
        }

        return array_intersect($checkRoles, $userRoles) !== [];
    }

    /**
     * Check if the authenticated user has a specific permission or ability.
     */
    public function can(string $ability, mixed ...$args): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        // 1. If Gate has a defined ability, evaluate with Gate
        if (\Kabuto\Auth\Gate::has($ability)) {
            return \Kabuto\Auth\Gate::forUser($user)->check($ability, $args);
        }

        // 2. Otherwise check user permissions claim
        $perms = [];
        foreach (['permissions', 'perms', 'scope', 'scopes'] as $claim) {
            $val = is_array($user) ? ($user[$claim] ?? null) : ($user->{$claim} ?? null);
            if (is_string($val) && $val !== '') {
                if (str_contains($val, ' ')) {
                    foreach (explode(' ', $val) as $p) {
                        $perms[] = trim($p);
                    }
                } else {
                    $perms[] = $val;
                }
            } elseif (is_array($val)) {
                foreach ($val as $p) {
                    if (is_string($p) && $p !== '') {
                        $perms[] = $p;
                    }
                }
            }
        }

        return in_array('*', $perms, true) || in_array($ability, $perms, true);
    }

    public function ip(): string
    {
        if ($this->isFromTrustedProxy()) {
            $forwarded = $this->header('x-forwarded-for');
            if (is_string($forwarded)) {
                $candidate = trim(explode(',', $forwarded)[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) return $candidate;
            }
        }
        return (string) ($this->server['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    public function isSecure(): bool
    {
        if (($this->server['HTTPS'] ?? '') === 'on' || (string) ($this->server['SERVER_PORT'] ?? '') === '443') return true;
        return $this->isFromTrustedProxy() && strtolower((string) $this->header('x-forwarded-proto', '')) === 'https';
    }

    private function isFromTrustedProxy(): bool
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '');
        foreach ($this->trustedProxies as $proxy) {
            if ($proxy === '*' || $proxy === $remote) return true;
            if (str_contains($proxy, '/') && $this->ipInCidr($remote, $proxy)) return true;
        }
        return false;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$network, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $ipBin = inet_pton($ip); $networkBin = inet_pton($network);
        if ($ipBin === false || $networkBin === false || $bits === null) return false;
        $bits = (int) $bits; $bytes = intdiv($bits, 8); $remainder = $bits % 8;
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($networkBin, 0, $bytes)) return false;
        return $remainder === 0 || ((ord($ipBin[$bytes]) & (0xFF << (8 - $remainder))) === (ord($networkBin[$bytes]) & (0xFF << (8 - $remainder))));
    }

    public function file(string $key): ?UploadedFile
    {
        $file = $this->files[$key] ?? null;

        if (is_array($file)) {
            return $file[0] ?? null;
        }

        return $file instanceof UploadedFile ? $file : null;
    }

    public function files(?string $key = null): array
    {
        if ($key === null) {
            return $this->files;
        }

        $file = $this->files[$key] ?? [];

        return is_array($file) ? $file : [$file];
    }

    public function hasFile(string $key): bool
    {
        $file = $this->file($key);

        return $file !== null && $file->isValid();
    }

    public static function collectFiles(array $rawFiles): array
    {
        $normalized = [];

        foreach ($rawFiles as $key => $file) {
            if ($file instanceof UploadedFile) {
                $normalized[$key] = $file;
                continue;
            }

            if (!is_array($file) || !isset($file['name'])) {
                continue;
            }

            if (is_array($file['name'])) {
                $subFiles = [];
                $count = count($file['name']);
                $keys = array_keys($file['name']);
                foreach ($keys as $idx) {
                    $subFiles[] = new UploadedFile(
                        (string) ($file['tmp_name'][$idx] ?? ''),
                        (string) ($file['name'][$idx] ?? ''),
                        isset($file['type'][$idx]) ? (string) $file['type'][$idx] : null,
                        (int) ($file['size'][$idx] ?? 0),
                        (int) ($file['error'][$idx] ?? UPLOAD_ERR_OK),
                    );
                }
                $normalized[$key] = $subFiles;
            } else {
                $normalized[$key] = new UploadedFile(
                    (string) ($file['tmp_name'] ?? ''),
                    (string) ($file['name'] ?? ''),
                    isset($file['type']) ? (string) $file['type'] : null,
                    (int) ($file['size'] ?? 0),
                    (int) ($file['error'] ?? UPLOAD_ERR_OK),
                );
            }
        }

        return $normalized;
    }
}
