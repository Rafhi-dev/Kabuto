<?php

declare(strict_types=1);

namespace Kabuto\Storage;

use RuntimeException;
use Kabuto\Http\Response;

/**
 * Private download service dengan signed URL dan authorization.
 */
final class PrivateDownloadService
{
    public function __construct(
        private readonly StorageDisk $disk,
        private readonly string $signingKey,
        private readonly int $defaultTtl = 3600,
    ) {
    }

    /**
     * Generate signed URL untuk akses private file.
     *
     * @param string $path Path file relatif
     * @param int $ttl Waktu hidup URL dalam detik (0 = unlimited)
     * @param array<string,mixed> $context Data tambahan (misalnya user_id untuk audit)
     * @return string Signed token
     */
    public function signUrl(string $path, ?int $ttl = null, array $context = []): string
    {
        $ttl ??= $this->defaultTtl;
        if ($ttl <= 0) throw new RuntimeException('Private download token TTL must be positive.');
        $this->disk->path($path);
        $expires = time() + $ttl;

        $payload = [
            'path' => $path,
            'expires' => $expires,
            'context' => $context,
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $encoded = base64_encode($json);
        $signature = hash_hmac('sha256', $encoded, $this->signingKey);

        return $encoded . '.' . $signature;
    }

    /**
     * Verify signed token dan return payload.
     *
     * @return array{path: string, expires: int, context: array<string,mixed>}
     * @throws RuntimeException
     */
    public function verifyToken(string $token): array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            throw new RuntimeException('Invalid token format.');
        }

        [$encoded, $signature] = $parts;

        $expectedSignature = hash_hmac('sha256', $encoded, $this->signingKey);
        if (!hash_equals($expectedSignature, $signature)) {
            throw new RuntimeException('Invalid token signature.');
        }

        $json = base64_decode($encoded, true);
        if ($json === false) {
            throw new RuntimeException('Invalid token encoding.');
        }

        $payload = json_decode($json, true);
        if (!is_array($payload) || !isset($payload['path'])) {
            throw new RuntimeException('Invalid token payload.');
        }

        $expires = (int) ($payload['expires'] ?? 0);
        if ($expires > 0 && time() >= $expires) {
            throw new RuntimeException('Token has expired.');
        }

        return [
            'path' => (string) $payload['path'],
            'expires' => $expires,
            'context' => (array) ($payload['context'] ?? []),
        ];
    }

    /**
     * Serve file via signed token dengan authorization callback.
     *
     * @param callable|null $authorize fn(string $path, array $context): bool
     */
    public function response(string $token, callable $authorize): Response
    {
        try {
            $payload = $this->verifyToken($token);
            $fullPath = $this->disk->path($payload['path']);
        } catch (RuntimeException) {
            return (new Response())->status(404)->send('Not Found');
        }
        if (!$authorize($payload['path'], $payload['context'])) return (new Response())->status(403)->send('Forbidden');
        $body = $this->disk->get($payload['path']);
        if ($body === null) return (new Response())->status(404)->send('Not Found');
        return (new Response())
            ->header('Content-Type', $this->disk->mime($payload['path']) ?? 'application/octet-stream')
            ->header('Content-Length', (string) strlen($body))
            ->header('Content-Disposition', 'attachment; filename="' . basename($payload['path']) . '"')
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff')
            ->send($body);
    }
}
