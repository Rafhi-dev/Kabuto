<?php

declare(strict_types=1);

namespace Kabuto\Auth;

final class FileSessionStore implements SessionStore
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
    }

    public function read(string $id): ?array
    {
        $path = $this->path($id);
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || (int) ($data['_expires'] ?? 0) <= time()) {
            @unlink($path);
            return null;
        }
        unset($data['_expires']);
        return $data;
    }

    public function write(string $id, array $data, int $expiresAt): void
    {
        $payload = $data;
        $payload['_expires'] = $expiresAt;
        file_put_contents($this->path($id), json_encode($payload, JSON_THROW_ON_ERROR), LOCK_EX);
        @chmod($this->path($id), 0600);
    }

    public function delete(string $id): void
    {
        @unlink($this->path($id));
    }

    private function path(string $id): string
    {
        return rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . hash('sha256', $id) . '.json';
    }
}
