<?php

declare(strict_types=1);

namespace Kabuto\Storage;

use Kabuto\Http\UploadedFile;
use RuntimeException;

final class StorageDisk
{
    private string $root;
    private ?MalwareScanner $scanner = null;
    private ?Quarantine $quarantine = null;
    private ?ImageSanitizer $imageSanitizer = null;

    public function __construct(
        string $root,
        private readonly string $urlPrefix = '',
        private readonly int $maxFileSize = 10_485_760,
    )
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    public function withScanner(?MalwareScanner $scanner): self
    {
        $this->scanner = $scanner;
        return $this;
    }

    public function withQuarantine(?Quarantine $quarantine): self
    {
        $this->quarantine = $quarantine;
        return $this;
    }

    public function withImageSanitizer(?ImageSanitizer $sanitizer): self
    {
        $this->imageSanitizer = $sanitizer;
        return $this;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function path(string $path = ''): string
    {
        $clean = ltrim(str_replace('\\', '/', $path), '/');

        if ($this->containsTraversal($clean)) {
            throw new RuntimeException('Storage path traversal is not allowed.');
        }

        return $clean === '' ? $this->root : $this->root . '/' . $clean;
    }

    public function put(string $path, string $contents): bool
    {
        $fullPath = $this->path($path);
        $directory = dirname($fullPath);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Directory [%s] could not be created.', $directory));
        }

        return file_put_contents($fullPath, $contents) !== false;
    }

    public function putFile(string $directory, UploadedFile $file): string
    {
        $extension = $file->extension();
        $randomName = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');

        return $this->putFileAs($directory, $file, $randomName);
    }

    public function putFileAs(string $directory, UploadedFile $file, string $name): string
    {
        if (!$file->isValid()) {
            throw new RuntimeException('Cannot store an invalid uploaded file.');
        }
        \Kabuto\Http\UploadFileInspector::validate($file);
        if ($this->maxFileSize > 0 && $file->size() > $this->maxFileSize) {
            throw new RuntimeException('Uploaded file exceeds the configured size limit.');
        }

        $blockedExtensions = ['php', 'php3', 'php4', 'php5', 'phtml', 'phar', 'cgi', 'pl', 'py', 'sh', 'htaccess'];
        if (in_array(strtolower($file->extension()), $blockedExtensions, true)) {
            throw new RuntimeException('Executable file uploads are not allowed.');
        }

        $relPath = trim(str_replace('\\', '/', $directory), '/');
        $name = basename(str_replace('\\', '/', $name));
        if ($name === '' || $name === '.' || $name === '..' || $this->containsTraversal($name)) {
            throw new RuntimeException('Invalid storage filename.');
        }
        $relPath = $relPath === '' ? $name : $relPath . '/' . $name;

        $fullPath = $this->path($relPath);
        $targetDir = dirname($fullPath);

        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new RuntimeException(sprintf('Directory [%s] could not be created.', $targetDir));
        }

        $stagePath = tempnam(sys_get_temp_dir(), 'kabuto-upload-');
        if ($stagePath === false) throw new RuntimeException('Cannot create a temporary upload staging file.');
        @unlink($stagePath);
        try {
            $file->moveTo($stagePath);
            if ($this->scanner !== null) {
                try {
                    $result = $this->scanner->scan($stagePath);
                } catch (ScanException $e) {
                    if ($this->quarantine !== null) $this->quarantine->quarantine($stagePath, 'scan-error');
                    throw new RuntimeException('Malware scan failed: ' . $e->getMessage(), 0, $e);
                }
                if (!$result->clean) {
                    if ($this->quarantine !== null) $this->quarantine->quarantine($stagePath, $result->threat ?? 'unknown');
                    throw new RuntimeException(sprintf('File rejected by malware scanner: %s', $result->threat ?? 'unknown'));
                }
            }
            if ($this->imageSanitizer !== null && $this->isImage($stagePath)) {
                $this->imageSanitizer->sanitize($stagePath, $stagePath);
            }
            if (!@rename($stagePath, $fullPath)) {
                if (!@copy($stagePath, $fullPath)) throw new RuntimeException(sprintf('Cannot commit staged upload to [%s].', $fullPath));
                @unlink($stagePath);
            }
        } catch (\Throwable $e) {
            if (is_file($stagePath)) @unlink($stagePath);
            if ($e instanceof RuntimeException) throw $e;
            throw new RuntimeException('Upload processing failed.', 0, $e);
        }

        return $relPath;
    }

    private function isImage(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }
        $imageType = @exif_imagetype($path);
        return $imageType !== false && in_array($imageType, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true);
    }

    public function get(string $path): ?string
    {
        $fullPath = $this->path($path);

        if (!is_file($fullPath)) {
            return null;
        }

        $content = file_get_contents($fullPath);

        return $content === false ? null : $content;
    }

    public function exists(string $path): bool
    {
        return file_exists($this->path($path));
    }

    public function delete(string $path): bool
    {
        $fullPath = $this->path($path);

        if (is_file($fullPath)) {
            return unlink($fullPath);
        }

        return false;
    }

    public function size(string $path): int
    {
        $fullPath = $this->path($path);

        return is_file($fullPath) ? (filesize($fullPath) ?: 0) : 0;
    }

    public function lastModified(string $path): int
    {
        $fullPath = $this->path($path);

        return is_file($fullPath) ? (filemtime($fullPath) ?: 0) : 0;
    }

    private const EXTENSION_MIMES = [
        'txt' => 'text/plain',
        'html' => 'text/html',
        'htm' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
        'csv' => 'text/csv',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
    ];

    public function mime(string $path): ?string
    {
        $fullPath = $this->path($path);

        if (!is_file($fullPath)) {
            return null;
        }

        if (extension_loaded('fileinfo')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $fullPath);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::EXTENSION_MIMES[$ext] ?? 'application/octet-stream';
    }

    public function url(string $path): string
    {
        $clean = ltrim(str_replace('\\', '/', $path), '/');

        if ($this->containsTraversal($clean)) {
            throw new RuntimeException('Storage path traversal is not allowed.');
        }

        return rtrim($this->urlPrefix, '/') . '/' . $clean;
    }

    public function temporaryUrl(string $path, int $expiresIn = 300, string $method = 'GET'): string
    {
        if ($expiresIn <= 0) throw new RuntimeException('Signed URL expiry must be positive.');
        $clean = ltrim(str_replace('\\', '/', $path), '/');
        $this->path($clean);
        $signed = SignedUrl::create($clean, time() + $expiresIn, strtoupper($method));
        return rtrim($this->urlPrefix, '/') . '/' . $signed;
    }

    public function verifyTemporaryUrl(string $path, int $expiresAt, string $signature, string $method = 'GET'): bool
    {
        $clean = ltrim(str_replace('\\', '/', $path), '/');
        if (!$this->exists($clean)) return false;
        return SignedUrl::verify($clean, $expiresAt, $signature, strtoupper($method));
    }

    private function containsTraversal(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' || $segment === '') {
                if ($segment === '..') {
                    return true;
                }
            }
        }

        return str_contains($path, "\0");
    }
}
