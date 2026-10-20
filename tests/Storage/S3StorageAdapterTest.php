<?php

declare(strict_types=1);

namespace Kabuto\Tests\Storage;

use DateTimeImmutable;
use Kabuto\Storage\S3StorageAdapter;
use Kabuto\Storage\S3StorageException;
use Kabuto\Tests\TestCase;

final class S3StorageAdapterTest extends TestCase
{
    public function testAdapterSignsOperationsAndNormalizesMetadata(): void
    {
        $calls = [];
        $adapter = $this->adapter(function (string $method, string $uri, array $headers, ?string $body, float $timeout) use (&$calls): array {
            $calls[] = compact('method', 'uri', 'headers', 'body', 'timeout');
            return ['status' => $method === 'GET' ? 200 : 204, 'body' => $method === 'GET' ? 'payload' : '', 'headers' => ['ETag' => '"etag"', 'Cache-Control' => 'max-age=10']];
        });

        self::assertTrue($adapter->put('folder/a file.txt', 'payload', 'text/plain'));
        self::assertSame('payload', $adapter->get('folder/a file.txt'));
        self::assertTrue($adapter->exists('folder/a file.txt'));
        self::assertTrue($adapter->delete('folder/a file.txt'));
        self::assertStringContains('/bucket/folder/a%20file.txt', $adapter->url('folder/a file.txt'));
        self::assertCount(4, $calls);
        self::assertStringContains('AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/', $calls[0]['headers']['Authorization']);
        self::assertSame(15.0, $calls[0]['timeout']);
        self::assertSame('"etag"', $adapter->metadata()['etag']);
        self::assertSame('max-age=10', $adapter->metadata()['cache-control']);
    }

    public function testPresignedGetMatchesAwsSigV4Vector(): void
    {
        // AWS S3 SigV4 reference example: GET /test.txt, us-east-1, 2013-05-24.
        $adapter = new S3StorageAdapter(
            bucket: 'examplebucket',
            endpoint: 'https://examplebucket.s3.amazonaws.com',
            request: static fn (): array => ['status' => 200, 'body' => ''],
            region: 'us-east-1',
            accessKey: 'AKIAIOSFODNN7EXAMPLE',
            secretKey: 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
            pathStyle: false,
            clock: new DateTimeImmutable('2013-05-24T00:00:00Z'),
        );

        $url = $adapter->presignedGetUrl('test.txt', 86400);
        self::assertStringContains('X-Amz-Algorithm=AWS4-HMAC-SHA256', $url);
        self::assertStringContains('X-Amz-Credential=AKIAIOSFODNN7EXAMPLE%2F20130524%2Fus-east-1%2Fs3%2Faws4_request', $url);
        self::assertStringContains('X-Amz-SignedHeaders=host', $url);
        self::assertStringContains('X-Amz-Signature=', $url);
        self::assertStringContains('https://examplebucket.s3.amazonaws.com/test.txt?', $url);
    }

    public function testPresignedUrlValidationAndSessionToken(): void
    {
        $adapter = new S3StorageAdapter(
            bucket: 'bucket', endpoint: 'https://s3.example.test',
            request: static fn (): array => ['status' => 200, 'body' => ''],
            accessKey: 'key', secretKey: 'secret', sessionToken: 'session token',
        );

        self::assertStringContains('X-Amz-Security-Token=session%20token', $adapter->presignedUrl('a.txt'));
        self::assertThrows(\InvalidArgumentException::class, fn () => $adapter->presignedUrl('a', 'POST'));
        self::assertThrows(\InvalidArgumentException::class, fn () => $adapter->presignedUrl('a', 'GET', 604801));
        self::assertThrows(\InvalidArgumentException::class, fn () => $adapter->presignedUrl('a', 'GET', 900, ['X-Amz-Date' => 'spoof']));
    }

    public function testPresignedPutCanBindContentTypeAndLength(): void
    {
        $adapter = new S3StorageAdapter(
            bucket: 'bucket', endpoint: 'https://s3.example.test',
            request: static fn (): array => ['status' => 200, 'body' => ''],
            accessKey: 'key', secretKey: 'secret', allowedContentTypes: ['image/*'], maxObjectSize: 1024,
        );

        $url = $adapter->presignedPutUrl('photo.png', contentType: 'image/png', contentLength: 12);
        self::assertStringContains('X-Amz-SignedHeaders=content-length%3Bcontent-type%3Bhost', $url);
        self::assertThrows(\InvalidArgumentException::class, fn () => $adapter->presignedPutUrl('file.bin', contentType: 'application/octet-stream'));
        self::assertThrows(\InvalidArgumentException::class, fn () => $adapter->presignedPutUrl('photo.png', contentLength: 2048));
    }

    public function testContentTypeAndObjectSizeRestrictionsRunBeforeTransport(): void
    {
        $calls = 0;
        $adapter = new S3StorageAdapter(
            'bucket', 'https://s3.example.test',
            function () use (&$calls): array { $calls++; return ['status' => 200, 'body' => '']; },
            maxObjectSize: 4,
            allowedContentTypes: ['text/*'],
        );

        self::assertThrows(\InvalidArgumentException::class, fn () => $adapter->put('ok.txt', '12345', 'text/plain'));
        self::assertThrows(\InvalidArgumentException::class, fn () => $adapter->put('bad.bin', 'a', 'application/octet-stream'));
        self::assertSame(0, $calls);
    }

    public function testKeyAndHeaderInjectionAreRejected(): void
    {
        $adapter = $this->adapter(static fn (): array => ['status' => 200, 'body' => '']);
        self::assertThrows(\InvalidArgumentException::class, fn () => $adapter->put('../escape', 'x'));
        self::assertThrows(\InvalidArgumentException::class, fn () => $adapter->put('%2e%2e/escape', 'x'));
        self::assertThrows(\InvalidArgumentException::class, fn () => $adapter->put("bad\r\nkey", 'x'));
        self::assertThrows(\InvalidArgumentException::class, fn () => $adapter->putWithOptions('a', 'x', 'text/plain', ['cache_control' => "ok\r\nInjected: true"]));
    }

    public function testPublicAndPrivateUrlBehavior(): void
    {
        $private = $this->adapter(static fn (): array => ['status' => 200, 'body' => '']);
        self::assertThrows(\RuntimeException::class, fn () => $private->publicUrl('a.txt'));

        $public = new S3StorageAdapter('bucket', 'https://s3.example.test', static fn (): array => ['status' => 200, 'body' => ''], public: true);
        self::assertSame($public->url('a.txt'), $public->publicUrl('a.txt'));
    }

    public function testPutMetadataAndCacheControlAreSignedAndExposed(): void
    {
        $captured = null;
        $adapter = $this->adapter(function (string $method, string $uri, array $headers, ?string $body) use (&$captured): array {
            $captured = $headers;
            return ['status' => 200, 'body' => '', 'headers' => ['ETag' => 'v1', 'x-amz-version-id' => 'version', 'x-amz-meta-color' => 'blue']];
        });

        self::assertTrue($adapter->putWithOptions('a.txt', 'data', 'text/plain', ['cache_control' => 'max-age=60', 'metadata.color' => 'blue']));
        self::assertSame('max-age=60', $captured['Cache-Control']);
        self::assertSame('blue', $captured['x-amz-meta-color']);
        self::assertStringContains('cache-control', strtolower($captured['Authorization']));
        self::assertSame('v1', $adapter->metadata()['etag']);
        self::assertSame('version', $adapter->metadata()['x-amz-version-id']);
        self::assertSame('blue', $adapter->metadata()['x-amz-meta-color']);
        self::assertThrows(\InvalidArgumentException::class, fn () => $adapter->putWithOptions('a', 'x', 'text/plain', ['metadata.bad name' => 'x']));
    }

    public function testMultipartUploadLifecycleAndMinimumPartSize(): void
    {
        $calls = [];
        $adapter = $this->adapter(function (string $method, string $uri, array $headers, ?string $body) use (&$calls): array {
            $calls[] = [$method, $uri, $body];
            if (str_contains($uri, 'uploads=')) return ['status' => 200, 'body' => '<InitiateMultipartUploadResult><UploadId>upload-1</UploadId></InitiateMultipartUploadResult>'];
            if (str_contains($uri, 'partNumber=')) return ['status' => 200, 'body' => '', 'headers' => ['ETag' => '"part-etag"']];
            return ['status' => 200, 'body' => '<CompleteMultipartUploadResult/>'];
        });

        $uploadId = $adapter->createMultipartUpload('large.bin');
        self::assertSame('upload-1', $uploadId);
        self::assertThrows(\InvalidArgumentException::class, fn () => $adapter->completeMultipartUpload('large.bin', $uploadId, [1 => 'etag']));
        self::assertThrows(\InvalidArgumentException::class, fn () => $adapter->uploadPart('other.bin', $uploadId, 2, 'x'));

        $part = str_repeat('a', 5_242_880);
        $etag = $adapter->uploadPart('large.bin', $uploadId, 1, $part);
        self::assertSame('part-etag', $etag);
        self::assertTrue($adapter->completeMultipartUpload('large.bin', $uploadId, [1 => $etag]));
        self::assertCount(3, $calls);
    }

    public function testMultipartAbortAndUploadIdValidation(): void
    {
        $adapter = $this->adapter(static fn (): array => ['status' => 204, 'body' => '']);
        self::assertTrue($adapter->abortMultipartUpload('a', 'unknown-id'));
        self::assertThrows(\InvalidArgumentException::class, fn () => $adapter->uploadPart('a', '', 0, 'x'));
    }

    public function testRetriesTransientResponsesAndNormalizesErrors(): void
    {
        $calls = 0;
        $adapter = new S3StorageAdapter('bucket', 'https://s3.example.test', function () use (&$calls): array {
            $calls++;
            return $calls < 3 ? ['status' => 503, 'body' => '<Error><Code>SlowDown</Code></Error>'] : ['status' => 204, 'body' => ''];
        }, retries: 2);
        self::assertTrue($adapter->put('a', 'x'));
        self::assertSame(3, $calls);

        $failed = new S3StorageAdapter('bucket', 'https://s3.example.test', static fn (): array => ['status' => 403, 'body' => '<Error><Code>AccessDenied</Code><Message>denied</Message></Error>'], retries: 3);
        try {
            $failed->get('a');
            self::assertTrue(false, 'Expected normalized S3 exception.');
        } catch (S3StorageException $e) {
            self::assertSame(403, $e->status);
            self::assertSame('AccessDenied', $e->serviceCode);
            self::assertFalse($e->retryable());
        }
    }

    public function testTransportFailuresRetryAndTimeoutIsPassedThrough(): void
    {
        $calls = 0;
        $adapter = new S3StorageAdapter('bucket', 'https://s3.example.test', function (string $method, string $uri, array $headers, ?string $body, float $timeout) use (&$calls): array {
            $calls++;
            self::assertSame(2.5, $timeout);
            if ($calls === 1) throw new \RuntimeException('network reset');
            return ['status' => 204, 'body' => ''];
        }, timeout: 2.5, retries: 1);
        self::assertTrue($adapter->put('a', 'x'));
        self::assertSame(2, $calls);
    }

    public function testFromConfigBuildsAdapterWithoutExternalSdk(): void
    {
        $adapter = S3StorageAdapter::fromConfig([
            'bucket' => 'bucket',
            'endpoint' => 'https://s3.example.test',
            'access_key' => 'key',
            'secret_key' => 'secret',
            'region' => 'eu-west-1',
            'allowed_content_types' => ['text/plain'],
        ], static fn (): array => ['status' => 204, 'body' => '']);

        self::assertTrue($adapter->put('a.txt', 'text', 'text/plain'));
        self::assertThrows(\InvalidArgumentException::class, fn () => S3StorageAdapter::fromConfig([], static fn (): array => ['status' => 200, 'body' => '']));
    }

    private function adapter(callable $request): S3StorageAdapter
    {
        return new S3StorageAdapter(
            bucket: 'bucket',
            endpoint: 'https://s3.example.test',
            request: $request,
            region: 'us-east-1',
            accessKey: 'AKIDEXAMPLE',
            secretKey: 'secret-example',
            clock: new DateTimeImmutable('2024-01-02T03:04:05Z'),
        );
    }
}
