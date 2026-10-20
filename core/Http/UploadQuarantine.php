<?php

declare(strict_types=1);

namespace Kabuto\Http;

use RuntimeException;

final class UploadQuarantine
{
    public function __construct(private readonly string $directory, private readonly MalwareScanner $scanner = new NullMalwareScanner())
    {
        if (!is_dir($directory)) mkdir($directory, 0700, true);
    }

    public function accept(UploadedFile $file): string
    {
        if (!$file->isValid()) throw new RuntimeException('Cannot quarantine an invalid upload.');
        $target = rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16));
        $file->moveTo($target);
        try {
            if (!$this->scanner->scan($target)) throw new RuntimeException('Upload failed malware scan.');
            return $target;
        } catch (\Throwable $e) { @unlink($target); throw $e; }
    }
}
