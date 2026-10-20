<?php

declare(strict_types=1);

namespace Kabuto\Storage;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;

/**
 * Dependency-free S3-compatible object storage adapter implementing AWS
 * Signature Version 4. The request callback is the HTTP boundary, allowing an
 * application to inject its preferred client without requiring it in core.
 */
final class S3StorageAdapter implements ObjectStorageAdapter
{
    private const MAX_SINGLE_PUT_SIZE = 5_368_709_120;

    /** @var callable(string,string,array<string,string>,?string,float):array{status:int,body:string,headers?:array<string,string>} */
    private readonly \Closure $request;

    /** @var list<string> */
    private readonly array $allowedContentTypes;

    /** @var array<string,string> */
    private array $lastMetadata = [];

    /** @var array<string,array{path:string,size:int,parts:array<int,int>}> */
    private array $multipartUploads = [];

    /** @param callable(string,string,array<string,string>,?string,float):array{status:int,body:string,headers?:array<string,string>} $request */
    public function __construct(
        private readonly string $bucket,
        private readonly string $endpoint,
        callable $request,
        private readonly string $region = 'us-east-1',
        private readonly string $accessKey = '',
        private readonly string $secretKey = '',
        private readonly ?string $sessionToken = null,
        private readonly bool $pathStyle = true,
        private readonly bool $public = false,
        private readonly float $timeout = 15.0,
        private readonly int $retries = 2,
        private readonly int $maxObjectSize = 5_497_558_138_880,
        array $allowedContentTypes = [],
        private readonly ?DateTimeInterface $clock = null,
    ) {
        if ($bucket === '') {
            throw new \InvalidArgumentException('S3 bucket must not be empty.');
        }
        if ($endpoint === '') {
            throw new \InvalidArgumentException('S3 endpoint must not be empty.');
        }
        $endpointParts = parse_url($endpoint);
        if ($endpointParts === false || !isset($endpointParts['scheme'], $endpointParts['host']) || !in_array(strtolower($endpointParts['scheme']), ['https', 'http'], true)) {
            throw new \InvalidArgumentException('S3 endpoint must be an absolute HTTP or HTTPS URL.');
        }
        if (($endpointParts['user'] ?? '') !== '' || isset($endpointParts['pass']) || isset($endpointParts['query']) || isset($endpointParts['fragment'])) {
            throw new \InvalidArgumentException('S3 endpoint must not embed credentials, query parameters, or fragments.');
        }
        if ($region === '' || preg_match('/[^a-z0-9-]/i', $region)) {
            throw new \InvalidArgumentException('S3 region contains invalid characters.');
        }
        if (($accessKey === '') !== ($secretKey === '')) {
            throw new \InvalidArgumentException('S3 access key and secret key must both be provided or both be empty.');
        }
        if ($timeout <= 0 || $retries < 0 || $maxObjectSize < 0) {
            throw new \InvalidArgumentException('S3 timeout must be positive; retries and max object size must not be negative.');
        }
        $this->request = $request(...);
        $this->allowedContentTypes = array_values(array_unique(array_map('strtolower', $allowedContentTypes)));
    }

    public function put(string $path, string $contents, string $contentType = 'application/octet-stream'): bool
    {
        $this->validateObject($path, strlen($contents), $contentType);
        if (strlen($contents) > self::MAX_SINGLE_PUT_SIZE) {
            throw new \InvalidArgumentException('S3 single PUT is limited to 5 GiB; use multipart upload for larger objects.');
        }
        $headers = ['Content-Type' => $contentType, 'Content-Length' => (string) strlen($contents)];
        if ($this->public) {
            $headers['x-amz-acl'] = 'public-read';
        }
        $response = $this->perform('PUT', $this->uri($path), $headers, $contents);
        $this->assertSuccess('PUT', $path, $response);
        $this->lastMetadata = $this->metadataFromResponse($response);
        return true;
    }

    public function get(string $path): ?string
    {
        $response = $this->perform('GET', $this->uri($path), [], null);
        if ($response['status'] === 404) {
            return null;
        }
        $this->assertSuccess('GET', $path, $response);
        $this->lastMetadata = $this->metadataFromResponse($response);
        return $response['body'];
    }

    public function exists(string $path): bool
    {
        $response = $this->perform('HEAD', $this->uri($path), [], null);
        if ($response['status'] === 404) {
            return false;
        }
        $this->assertSuccess('HEAD', $path, $response);
        $this->lastMetadata = $this->metadataFromResponse($response);
        return true;
    }

    public function delete(string $path): bool
    {
        $response = $this->perform('DELETE', $this->uri($path), [], null);
        if ($response['status'] === 404) {
            return false;
        }
        $this->assertSuccess('DELETE', $path, $response);
        return true;
    }

    /**
     * A plain URL is only public when the bucket/object policy grants access.
     * Use presignedUrl() for private objects.
     */
    public function url(string $path): string
    {
        $this->validatePath($path);
        return $this->uri($path);
    }

    public function publicUrl(string $path): string
    {
        if (!$this->public) {
            throw new RuntimeException('This S3 adapter is configured for a private bucket; use presignedUrl() instead.');
        }
        return $this->url($path);
    }

    /**
     * Generate a SigV4 presigned GET or PUT URL (maximum seven days).
     *
     * @param array<string,string|int|float> $query additional query parameters
     */
    public function presignedUrl(
        string $path,
        string $method = 'GET',
        int $expiresIn = 900,
        array $query = [],
        ?DateTimeInterface $now = null,
        array $headers = [],
    ): string {
        $this->assertSigningConfigured();
        $this->validatePath($path);
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'PUT', 'HEAD'], true)) {
            throw new \InvalidArgumentException('S3 presigned URL method must be GET, PUT, or HEAD.');
        }
        if ($expiresIn < 1 || $expiresIn > 604800) {
            throw new \InvalidArgumentException('S3 presigned URL expiry must be between 1 and 604800 seconds.');
        }
        if ($method === 'PUT') {
            $putHeaders = array_change_key_case($headers, CASE_LOWER);
            if ($this->maxObjectSize > 0 && !isset($putHeaders['content-length'])) {
                throw new \InvalidArgumentException('Presigned PUT requires contentLength so the configured maximum object size can be enforced.');
            }
            if ($this->allowedContentTypes !== [] && !isset($putHeaders['content-type'])) {
                throw new \InvalidArgumentException('Presigned PUT requires contentType when a content type allow-list is configured.');
            }
            if (isset($putHeaders['content-length'])) {
                $length = filter_var($putHeaders['content-length'], FILTER_VALIDATE_INT);
                if ($length === false || $length < 0) {
                    throw new \InvalidArgumentException('Presigned PUT content length must be a non-negative integer.');
                }
                $this->validateSize($length);
                if (isset($putHeaders['content-type'])) {
                    $this->validateObject($path, $length, (string) $putHeaders['content-type']);
                }
            }
        }

        $time = $this->timestamp($now);
        $date = substr($time, 0, 8);
        $scope = $date . '/' . $this->region . '/s3/aws4_request';
        $host = $this->host();
        $params = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $this->accessKey . '/' . $scope,
            'X-Amz-Date' => $time,
            'X-Amz-Expires' => (string) $expiresIn,
            'X-Amz-SignedHeaders' => 'host',
        ];
        foreach ($query as $key => $value) {
            if (str_starts_with(strtolower((string) $key), 'x-amz-')) {
                throw new \InvalidArgumentException('Custom presigned query parameters must not override X-Amz signing fields.');
            }
            if (array_key_exists((string) $key, $params)) {
                throw new \InvalidArgumentException("Presigned query parameter [{$key}] is already defined.");
            }
            $params[(string) $key] = $value;
        }
        if ($this->sessionToken !== null && $this->sessionToken !== '') {
            $params['X-Amz-Security-Token'] = $this->sessionToken;
        }
        $normalizedHeaders = ['host' => strtolower($host)];
        foreach ($headers as $name => $value) {
            $lower = strtolower(trim((string) $name));
            if ($lower === 'host' || str_starts_with($lower, 'x-amz-')) {
                throw new \InvalidArgumentException('Presigned extra headers cannot override host or x-amz headers.');
            }
            $this->validateHeaderValue((string) $value, (string) $name);
            $normalizedHeaders[$lower] = preg_replace('/\s+/', ' ', trim((string) $value)) ?? trim((string) $value);
        }
        ksort($normalizedHeaders, SORT_STRING);
        $canonicalHeaderBlock = '';
        foreach ($normalizedHeaders as $name => $value) {
            $canonicalHeaderBlock .= $name . ':' . $value . "\n";
        }
        $signedHeaderNames = implode(';', array_keys($normalizedHeaders));
        $params['X-Amz-SignedHeaders'] = $signedHeaderNames;
        $canonicalQuery = $this->canonicalQuery($params);
        // AWS's canonical request format has an empty line between the
        // canonical header block (which itself ends in LF) and SignedHeaders.
        // The header-block trailing LF accounts for that separator.
        $canonicalRequest = $method . "\n"
            . $this->canonicalUri($path) . "\n"
            . $canonicalQuery . "\n"
            . $canonicalHeaderBlock
            . "\n" . $signedHeaderNames . "\nUNSIGNED-PAYLOAD";
        $stringToSign = "AWS4-HMAC-SHA256\n{$time}\n{$scope}\n" . hash('sha256', $canonicalRequest);
        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($date));

        return $this->uri($path) . '?' . $canonicalQuery . '&X-Amz-Signature=' . $signature;
    }

    /** Alias with a verb-first name for discoverability. */
    public function presignedGetUrl(string $path, int $expiresIn = 900, ?DateTimeInterface $now = null): string
    {
        return $this->presignedUrl($path, 'GET', $expiresIn, [], $now);
    }

    /** @param array<string,string|int|float> $query */
    public function presignedPutUrl(
        string $path,
        int $expiresIn = 900,
        array $query = [],
        ?DateTimeInterface $now = null,
        ?string $contentType = null,
        ?int $contentLength = null,
    ): string
    {
        $headers = [];
        if ($contentType !== null) {
            $this->validateObject($path, $contentLength ?? 0, $contentType);
            $headers['content-type'] = $contentType;
        }
        if ($contentLength !== null) {
            if ($contentLength < 0) {
                throw new \InvalidArgumentException('Presigned PUT content length cannot be negative.');
            }
            $this->validateSize($contentLength);
            $headers['content-length'] = (string) $contentLength;
        }
        return $this->presignedUrl($path, 'PUT', $expiresIn, $query, $now, $headers);
    }

    /**
     * Start an S3 multipart upload and return its upload ID.
     */
    public function createMultipartUpload(string $path, string $contentType = 'application/octet-stream', array $metadata = []): string
    {
        $this->validateObject($path, 0, $contentType);
        $this->multipartUploads = array_filter($this->multipartUploads, static fn (array $upload): bool => isset($upload['path'], $upload['size'], $upload['parts']));
        if (count($this->multipartUploads) >= 1000) {
            throw new RuntimeException('S3 adapter has reached its limit of 1000 tracked multipart uploads.');
        }
        $headers = ['Content-Type' => $contentType];
        if ($this->public) {
            $headers['x-amz-acl'] = 'public-read';
        }
        foreach ($metadata as $name => $value) {
            if (!preg_match('/^[a-zA-Z0-9_.-]+$/', (string) $name)) {
                throw new \InvalidArgumentException('S3 metadata names may contain only letters, digits, underscore, dot, and hyphen.');
            }
            $this->validateHeaderValue((string) $value, 'metadata');
            $headers['x-amz-meta-' . strtolower((string) $name)] = (string) $value;
        }
        $response = $this->perform('POST', $this->uri($path) . '?uploads=', $headers, '');
        $this->assertSuccess('CreateMultipartUpload', $path, $response);
        if (!preg_match('/<UploadId>(.*?)<\/UploadId>/s', $response['body'], $match)) {
            throw new RuntimeException('S3 multipart initialization response did not contain an UploadId.');
        }
        $uploadId = html_entity_decode($match[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
        $this->multipartUploads[$uploadId] = ['path' => $path, 'size' => 0, 'parts' => []];
        return $uploadId;
    }

    /**
     * Upload one multipart part. Part numbers are in [1, 10000]. Returns ETag.
     */
    public function uploadPart(string $path, string $uploadId, int $partNumber, string $contents): string
    {
        $this->validatePath($path);
        if ($uploadId === '' || $partNumber < 1 || $partNumber > 10000) {
            throw new \InvalidArgumentException('Multipart upload requires an upload ID and part number in [1, 10000].');
        }
        $this->assertMultipartPath($path, $uploadId);
        $this->validateSize(strlen($contents));
        if (strlen($contents) > self::MAX_SINGLE_PUT_SIZE) {
            throw new \InvalidArgumentException('S3 multipart part cannot exceed 5 GiB.');
        }
        $currentSize = $this->multipartUploads[$uploadId]['size'] ?? 0;
        $oldPartSize = $this->multipartUploads[$uploadId]['parts'][$partNumber] ?? 0;
        $this->validateSize($currentSize - $oldPartSize + strlen($contents));
        $uri = $this->uri($path) . '?' . $this->canonicalQuery(['partNumber' => $partNumber, 'uploadId' => $uploadId]);
        $response = $this->perform('PUT', $uri, ['Content-Length' => (string) strlen($contents)], $contents);
        $this->assertSuccess('UploadPart', $path, $response);
        $etag = $this->header($response, 'etag');
        if ($etag === null || $etag === '') {
            throw new RuntimeException('S3 UploadPart response did not contain an ETag.');
        }
        $this->multipartUploads[$uploadId]['size'] = $currentSize - $oldPartSize + strlen($contents);
        $this->multipartUploads[$uploadId]['parts'][$partNumber] = strlen($contents);
        return trim($etag, '"');
    }

    /**
     * Complete a multipart upload from [partNumber => etag] pairs.
     *
     * @param array<int,string> $parts
     */
    public function completeMultipartUpload(string $path, string $uploadId, array $parts): bool
    {
        $this->validatePath($path);
        if ($uploadId === '' || $parts === []) {
            throw new \InvalidArgumentException('Multipart completion requires an upload ID and at least one part.');
        }
        $this->assertMultipartPath($path, $uploadId);
        ksort($parts, SORT_NUMERIC);
        if (isset($this->multipartUploads[$uploadId])) {
            $partNumbers = array_keys($parts);
            foreach ($partNumbers as $index => $number) {
                if (!isset($this->multipartUploads[$uploadId]['parts'][$number])) {
                    throw new \InvalidArgumentException("Multipart part [{$number}] was not uploaded by this adapter instance.");
                }
                if ($index < count($partNumbers) - 1 && $this->multipartUploads[$uploadId]['parts'][$number] < 5_242_880) {
                    throw new \InvalidArgumentException('Every multipart part except the last must be at least 5 MiB.');
                }
            }
        }
        $xml = '<CompleteMultipartUpload>';
        foreach ($parts as $number => $etag) {
            if (!is_int($number) || $number < 1 || $number > 10000 || $etag === '') {
                throw new \InvalidArgumentException('Multipart parts must have valid part numbers and non-empty ETags.');
            }
            $xml .= '<Part><PartNumber>' . $number . '</PartNumber><ETag>&quot;' . htmlspecialchars(trim($etag, '"'), ENT_XML1) . '&quot;</ETag></Part>';
        }
        $xml .= '</CompleteMultipartUpload>';
        $uri = $this->uri($path) . '?' . $this->canonicalQuery(['uploadId' => $uploadId]);
        $response = $this->perform('POST', $uri, ['Content-Type' => 'application/xml'], $xml);
        $this->assertSuccess('CompleteMultipartUpload', $path, $response);
        if (preg_match('/<Error(?:\s|>)/', $response['body'])) {
            throw new RuntimeException('S3 multipart completion returned an error: ' . $this->normalizeBody($response['body']));
        }
        $this->lastMetadata = $this->metadataFromResponse($response);
        unset($this->multipartUploads[$uploadId]);
        return true;
    }

    public function abortMultipartUpload(string $path, string $uploadId): bool
    {
        $this->validatePath($path);
        if ($uploadId === '') {
            throw new \InvalidArgumentException('Multipart abort requires an upload ID.');
        }
        $this->assertMultipartPath($path, $uploadId);
        $uri = $this->uri($path) . '?' . $this->canonicalQuery(['uploadId' => $uploadId]);
        $response = $this->perform('DELETE', $uri, [], null);
        $this->assertSuccess('AbortMultipartUpload', $path, $response);
        unset($this->multipartUploads[$uploadId]);
        return true;
    }

    /** @return array<string,string> last ETag, version, cache-control and metadata headers */
    public function metadata(): array
    {
        return $this->lastMetadata;
    }

    /** Construct an adapter from storage.s3 config and the built-in stream transport. */
    public static function configured(array $config): self
    {
        return self::fromConfig($config, new NativeHttpTransport());
    }

    /** @param array<string,mixed> $config */
    public static function fromConfig(array $config, callable $request, ?DateTimeInterface $clock = null): self
    {
        foreach (['bucket', 'endpoint'] as $required) {
            if (!isset($config[$required]) || trim((string) $config[$required]) === '') {
                throw new \InvalidArgumentException("S3 configuration is missing [{$required}].");
            }
        }

        return new self(
            bucket: (string) $config['bucket'],
            endpoint: (string) $config['endpoint'],
            request: $request,
            region: (string) ($config['region'] ?? 'us-east-1'),
            accessKey: (string) ($config['access_key'] ?? ''),
            secretKey: (string) ($config['secret_key'] ?? ''),
            sessionToken: isset($config['session_token']) ? (string) $config['session_token'] : null,
            pathStyle: (bool) ($config['path_style'] ?? true),
            public: (bool) ($config['public'] ?? false),
            timeout: (float) ($config['timeout'] ?? 15),
            retries: (int) ($config['retries'] ?? 2),
            maxObjectSize: (int) ($config['max_object_size'] ?? 5_497_558_138_880),
            allowedContentTypes: (array) ($config['allowed_content_types'] ?? []),
            clock: $clock,
        );
    }

    /**
     * PUT object from metadata/options. Content type restrictions and maximum
     * size are enforced before the HTTP transport is invoked.
     *
     * @param array<string,string> $options cache_control, content_disposition, metadata.*
     */
    public function putWithOptions(string $path, string $contents, string $contentType = 'application/octet-stream', array $options = []): bool
    {
        $this->validateObject($path, strlen($contents), $contentType);
        if (strlen($contents) > self::MAX_SINGLE_PUT_SIZE) {
            throw new \InvalidArgumentException('S3 single PUT is limited to 5 GiB; use multipart upload for larger objects.');
        }
        $headers = ['Content-Type' => $contentType, 'Content-Length' => (string) strlen($contents)];
        if ($this->public) {
            $headers['x-amz-acl'] = 'public-read';
        }
        foreach (['cache_control' => 'Cache-Control', 'content_disposition' => 'Content-Disposition', 'content_encoding' => 'Content-Encoding'] as $key => $header) {
            if (isset($options[$key])) {
                $this->validateHeaderValue((string) $options[$key], $header);
                $headers[$header] = $options[$key];
            }
        }
        foreach ($options as $key => $value) {
            if (str_starts_with($key, 'metadata.')) {
                $metadataName = substr($key, 9);
                if ($metadataName === '') {
                    throw new \InvalidArgumentException('S3 metadata keys must not be empty.');
                }
                if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $metadataName)) {
                    throw new \InvalidArgumentException('S3 metadata names may contain only letters, digits, underscore, dot, and hyphen.');
                }
                $this->validateHeaderValue((string) $value, 'metadata');
                $headers['x-amz-meta-' . strtolower($metadataName)] = (string) $value;
            }
        }
        $response = $this->perform('PUT', $this->uri($path), $headers, $contents);
        $this->assertSuccess('PUT', $path, $response);
        $this->lastMetadata = $this->metadataFromResponse($response);
        return true;
    }

    /**
     * Canonical URI for SigV4 uses AWS encoding rules (not form encoding).
     */
    private function canonicalUri(string $path): string
    {
        $segments = explode('/', ltrim($path, '/'));
        $encoded = array_map('rawurlencode', $segments);
        $keyPath = implode('/', $encoded);
        $basePath = $this->canonicalBasePath();
        if ($this->pathStyle) {
            return $basePath . '/' . rawurlencode($this->bucket) . '/' . $keyPath;
        }
        return $basePath . '/' . $keyPath;
    }

    private function uri(string $path): string
    {
        $this->validatePath($path);
        $endpoint = rtrim($this->endpoint, '/');
        $keyPath = implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
        if ($this->pathStyle) {
            return $endpoint . '/' . rawurlencode($this->bucket) . '/' . $keyPath;
        }
        $parts = parse_url($endpoint);
        if ($parts === false || !isset($parts['host'])) {
            throw new \InvalidArgumentException('S3 endpoint must be a valid absolute URL.');
        }
        $scheme = $parts['scheme'] ?? 'https';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $basePath = $parts['path'] ?? '';
        $host = strtolower((string) $parts['host']);
        $bucketHost = str_starts_with($host, strtolower($this->bucket) . '.') ? $host : $this->bucket . '.' . $host;
        return $scheme . '://' . $bucketHost . $port . rtrim($basePath, '/') . '/' . $keyPath;
    }

    /** @param array<string,string|int|float> $params */
    private function canonicalQuery(array $params): string
    {
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = [rawurlencode((string) $key), rawurlencode((string) $value)];
        }
        usort($pairs, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return implode('&', array_map(static fn (array $pair): string => $pair[0] . '=' . $pair[1], $pairs));
    }

    private function perform(string $method, string $uri, array $headers, ?string $body): array
    {
        $retrySafe = in_array(strtoupper($method), ['GET', 'HEAD', 'PUT', 'DELETE'], true);
        $attempts = $retrySafe ? max(1, $this->retries + 1) : 1;
        $last = null;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                // Re-sign every attempt so the timestamp/signature cannot expire
                // while a long retry sequence is in progress.
                $attemptHeaders = $this->accessKey !== '' ? $this->signedHeaders($method, $uri, $headers, $body) : $headers;
                $response = ($this->request)($method, $uri, $attemptHeaders, $body, (float) $this->timeout);
                $status = (int) ($response['status'] ?? 0);
                $normalized = ['status' => $status, 'body' => (string) ($response['body'] ?? ''), 'headers' => (array) ($response['headers'] ?? [])];
                if (in_array($status, [0, 408, 425, 429, 500, 502, 503, 504], true)) {
                    $code = $this->xmlValue($normalized['body'], 'Code');
                    $last = new S3StorageException("S3 {$method} request failed with status {$status}.", $method, $status, $code);
                    if ($attempt >= $attempts) {
                        break;
                    }
                    usleep(min(1_000_000, 100_000 * (2 ** ($attempt - 1))));
                    continue;
                }
                return $normalized;
            } catch (\Throwable $e) {
                $last = $e;
                if ($attempt >= $attempts) {
                    break;
                }
                if ($e instanceof S3StorageException && !$e->retryable()) {
                    throw $e;
                }
                usleep(min(1_000_000, 100_000 * (2 ** ($attempt - 1))));
            }
        }
        if ($last instanceof S3StorageException) {
            throw $last;
        }
        throw new S3StorageException('S3 request failed after retries: ' . ($last?->getMessage() ?? 'transport error'), $method, 0, null, $last);
    }

    /** @return array<string,string> */
    private function signedHeaders(string $method, string $uri, array $headers, ?string $body): array
    {
        $this->assertSigningConfigured();
        $time = $this->timestamp();
        $date = substr($time, 0, 8);
        $host = $this->host();
        $headers['Host'] = $host;
        $headers['x-amz-date'] = $time;
        $headers['x-amz-content-sha256'] = hash('sha256', $body ?? '');
        if ($this->sessionToken !== null && $this->sessionToken !== '') {
            $headers['x-amz-security-token'] = $this->sessionToken;
        }

        $normalized = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower(trim($name));
            $normalized[$lower] = preg_replace('/\s+/', ' ', trim((string) $value)) ?? trim((string) $value);
        }
        ksort($normalized, SORT_STRING);
        $canonicalHeaders = '';
        foreach ($normalized as $name => $value) {
            $canonicalHeaders .= $name . ':' . $value . "\n";
        }
        $signedHeaders = implode(';', array_keys($normalized));
        $parts = parse_url($uri);
        if ($parts === false) {
            throw new \InvalidArgumentException('Invalid S3 request URI.');
        }
        $canonicalQuery = isset($parts['query']) ? $this->canonicalRawQuery($parts['query']) : '';
        $canonicalRequest = strtoupper($method) . "\n"
            . ($parts['path'] ?? '/') . "\n"
            . $canonicalQuery . "\n"
            . $canonicalHeaders
            . "\n" . $signedHeaders . "\n"
            . $headers['x-amz-content-sha256'];
        $scope = $date . '/' . $this->region . '/s3/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n{$time}\n{$scope}\n" . hash('sha256', $canonicalRequest);
        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($date));
        $headers['Authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $this->accessKey . '/' . $scope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;
        return $headers;
    }

    private function signingKey(string $date): string
    {
        $dateKey = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $regionKey = hash_hmac('sha256', $this->region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', 's3', $regionKey, true);
        return hash_hmac('sha256', 'aws4_request', $serviceKey, true);
    }

    private function host(): string
    {
        $uri = $this->uriHostBase();
        return $uri;
    }

    private function uriHostBase(): string
    {
        $url = $this->pathStyle ? $this->endpoint : $this->uriHostEndpoint();
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            throw new \InvalidArgumentException('S3 endpoint must be a valid absolute URL.');
        }
        $host = strtolower($parts['host']);
        if (!$this->pathStyle && isset($parts['port']) && !str_starts_with($host, strtolower($this->bucket) . '.')) {
            $host = $this->bucket . '.' . $host;
        }
        return $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private function uriHostEndpoint(): string
    {
        $parts = parse_url($this->endpoint);
        if ($parts === false || !isset($parts['host'])) {
            throw new \InvalidArgumentException('S3 endpoint must be a valid absolute URL.');
        }
        $scheme = $parts['scheme'] ?? 'https';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $host = strtolower((string) $parts['host']);
        $bucketHost = str_starts_with($host, strtolower($this->bucket) . '.') ? $host : $this->bucket . '.' . $host;
        return $scheme . '://' . $bucketHost . $port;
    }

    private function timestamp(?DateTimeInterface $now = null): string
    {
        $time = $now ?? $this->clock ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return DateTimeImmutable::createFromInterface($time)->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    private function validateObject(string $path, int $size, string $contentType): void
    {
        $this->validatePath($path);
        $this->validateSize($size);
        if ($contentType === '' || preg_match('/[\r\n]/', $contentType)) {
            throw new \InvalidArgumentException('S3 content type must be a non-empty header-safe value.');
        }
        if ($this->allowedContentTypes !== [] && !$this->contentTypeAllowed($contentType)) {
            throw new \InvalidArgumentException("S3 content type [{$contentType}] is not allowed.");
        }
    }

    private function contentTypeAllowed(string $contentType): bool
    {
        $contentType = strtolower(trim($contentType));
        foreach ($this->allowedContentTypes as $allowed) {
            if ($allowed === $contentType || ($allowed !== '' && str_ends_with($allowed, '/*') && str_starts_with($contentType, substr($allowed, 0, -1)))) {
                return true;
            }
        }
        return false;
    }

    private function validateSize(int $size): void
    {
        if ($this->maxObjectSize > 0 && $size > $this->maxObjectSize) {
            throw new \InvalidArgumentException(sprintf('S3 object size [%d] exceeds configured maximum [%d].', $size, $this->maxObjectSize));
        }
    }

    private function validatePath(string $path): void
    {
        $decodedPath = rawurldecode(str_replace('\\', '/', $path));
        if ($path === '' || str_contains($decodedPath, "\0") || preg_match('/[\r\n]/', $decodedPath)) {
            throw new \InvalidArgumentException('S3 object key must be non-empty and contain no control characters.');
        }
        foreach (explode('/', $decodedPath) as $segment) {
            if ($segment === '..') {
                throw new \InvalidArgumentException('S3 object key traversal is not allowed.');
            }
        }
    }

    private function assertMultipartPath(string $path, string $uploadId): void
    {
        if (isset($this->multipartUploads[$uploadId]) && $this->multipartUploads[$uploadId]['path'] !== $path) {
            throw new \InvalidArgumentException('Multipart upload ID does not belong to the given object key.');
        }
    }

    private function validateHeaderValue(string $value, string $name): void
    {
        if (preg_match('/[\r\n]/', $value)) {
            throw new \InvalidArgumentException("S3 header [{$name}] contains invalid line breaks.");
        }
    }

    private function assertSigningConfigured(): void
    {
        if ($this->accessKey === '' || $this->secretKey === '') {
            throw new RuntimeException('S3 signing requires both access key and secret key.');
        }
    }

    private function assertSuccess(string $operation, string $path, array $response): void
    {
        $status = $response['status'];
        if ($status < 200 || $status >= 300) {
            $code = $this->xmlValue($response['body'], 'Code');
            $message = $this->xmlValue($response['body'], 'Message');
            $detail = $code !== null ? " {$code}" : '';
            if ($message !== null) {
                $detail .= ': ' . $message;
            }
            $message = "S3 {$operation} failed for [{$path}] with status {$status}{$detail}.";
            throw new S3StorageException($message, $operation, $status, $code);
        }
    }

    /** @return array<string,string> */
    private function metadataFromResponse(array $response): array
    {
        $metadata = [];
        foreach ((array) ($response['headers'] ?? []) as $name => $value) {
            $lower = strtolower((string) $name);
            if (in_array($lower, ['etag', 'x-amz-version-id', 'cache-control', 'content-type', 'content-length', 'last-modified'], true) || str_starts_with($lower, 'x-amz-meta-')) {
                $metadata[$lower] = (string) $value;
            }
        }
        return $metadata;
    }

    private function header(array $response, string $name): ?string
    {
        foreach ((array) ($response['headers'] ?? []) as $key => $value) {
            if (strtolower((string) $key) === strtolower($name)) {
                return (string) $value;
            }
        }
        return null;
    }

    private function xmlValue(string $xml, string $name): ?string
    {
        if (preg_match('/<' . preg_quote($name, '/') . '>(.*?)<\/' . preg_quote($name, '/') . '>/s', $xml, $match)) {
            return html_entity_decode($match[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        return null;
    }

    private function normalizeBody(string $body): string
    {
        return substr(trim(strip_tags($body)), 0, 500);
    }

    private function canonicalRawQuery(string $query): string
    {
        $params = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $params[rawurldecode($key)] = rawurldecode($value);
        }
        return $this->canonicalQuery($params);
    }

    private function canonicalBasePath(): string
    {
        $path = (string) (parse_url($this->endpoint, PHP_URL_PATH) ?: '');
        if ($path === '' || $path === '/') {
            return '';
        }
        return '/' . implode('/', array_map(
            static fn (string $segment): string => rawurlencode(rawurldecode($segment)),
            explode('/', trim($path, '/')),
        ));
    }
}
