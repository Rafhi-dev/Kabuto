<?php

declare(strict_types=1);

namespace Kabuto\Http;

use Kabuto\Storage\Storage;
use RuntimeException;

final class UploadedFile
{
    public function __construct(
        private readonly string $tempPath,
        private readonly string $clientFilename,
        private readonly ?string $clientMediaType = null,
        private readonly int $size = 0,
        private readonly int $error = UPLOAD_ERR_OK,
    ) {
    }

    public function name(): string
    {
        return $this->clientFilename;
    }

    public function clientMime(): string
    {
        return $this->clientMediaType ?? 'application/octet-stream';
    }

    public function mime(): string
    {
        if (is_file($this->tempPath) && extension_loaded('fileinfo')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $this->tempPath);
                finfo_close($finfo);
                if (is_string($detected) && $detected !== '') {
                    return $detected;
                }
            }
        }

        return $this->clientMime();
    }

    public function extension(): string
    {
        $ext = pathinfo($this->clientFilename, PATHINFO_EXTENSION);

        return strtolower($ext);
    }

    public function size(): int
    {
        if ($this->size > 0) {
            return $this->size;
        }

        return is_file($this->tempPath) ? (filesize($this->tempPath) ?: 0) : 0;
    }

    public function tempPath(): string
    {
        return $this->tempPath;
    }

    public function error(): int
    {
        return $this->error;
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && is_file($this->tempPath);
    }

    public function store(string $directory = '', string $disk = 'local'): string
    {
        return Storage::disk($disk)->putFile($directory, $this);
    }

    public function storeAs(string $directory, string $filename, string $disk = 'local'): string
    {
        return Storage::disk($disk)->putFileAs($directory, $this, $filename);
    }

    public function moveTo(string $targetPath): bool
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(sprintf('Cannot move uploaded file due to upload error code [%d].', $this->error));
        }

        if (is_uploaded_file($this->tempPath)) {
            if (!move_uploaded_file($this->tempPath, $targetPath)) {
                throw new RuntimeException(sprintf('Failed to move uploaded file [%s] to [%s].', $this->tempPath, $targetPath));
            }
            return true;
        }

        if (is_file($this->tempPath)) {
            if (!rename($this->tempPath, $targetPath)) {
                if (!copy($this->tempPath, $targetPath)) {
                    throw new RuntimeException(sprintf('Failed to copy file [%s] to [%s].', $this->tempPath, $targetPath));
                }
                unlink($this->tempPath);
            }
            return true;
        }

        throw new RuntimeException(sprintf('Source file [%s] does not exist.', $this->tempPath));
    }

    public function getContent(): string
    {
        if (!is_file($this->tempPath)) {
            return '';
        }

        $content = file_get_contents($this->tempPath);

        return $content === false ? '' : $content;
    }
}
