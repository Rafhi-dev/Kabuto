<?php

declare(strict_types=1);

namespace Kabuto\Storage;

interface ObjectStorageAdapter
{
    public function put(string $path, string $contents, string $contentType = 'application/octet-stream'): bool;
    public function get(string $path): ?string;
    public function exists(string $path): bool;
    public function delete(string $path): bool;
    public function url(string $path): string;
}
