<?php

declare(strict_types=1);

namespace Kabuto\Storage;

use Kabuto\Application;
use InvalidArgumentException;

final class Storage
{
    /** @var array<string,StorageDisk> */
    private static array $disks = [];

    private static ?string $basePath = null;

    private static ?MalwareScanner $scanner = null;
    private static ?Quarantine $quarantine = null;
    private static ?ImageSanitizer $imageSanitizer = null;
    private static int $maxFileSize = 10_485_760;

    public static function configureUploads(int $maxFileSize = 10_485_760, ?MalwareScanner $scanner = null, ?Quarantine $quarantine = null, ?ImageSanitizer $sanitizer = null): void
    {
        self::$maxFileSize = max(0, $maxFileSize);
        self::$scanner = $scanner;
        self::$quarantine = $quarantine;
        self::$imageSanitizer = $sanitizer;
        self::$disks = [];
    }

    public static function setBasePath(string $basePath): void
    {
        self::$basePath = rtrim(str_replace('\\', '/', $basePath), '/');
        self::$disks = [];
    }

    private static function resolveBasePath(): string
    {
        if (self::$basePath !== null) {
            return self::$basePath;
        }

        if (class_exists(Application::class) && Application::current() !== null) {
            return Application::current()->basePath();
        }

        if (function_exists('base_path')) {
            return base_path();
        }

        return getcwd() ?: '.';
    }

    public static function disk(?string $name = null): StorageDisk
    {
        $diskName = $name ?? 'local';

        if (isset(self::$disks[$diskName])) {
            return self::$disks[$diskName];
        }

        $base = self::resolveBasePath();

        $disk = match ($diskName) {
            'local' => new StorageDisk($base . '/storage/app', '', self::$maxFileSize),
            'public' => new StorageDisk($base . '/storage/app/public', '/storage', self::$maxFileSize),
            'private' => new StorageDisk($base . '/storage/app/private', '', self::$maxFileSize),
            default => throw new InvalidArgumentException(sprintf('Unknown storage disk [%s]. Available disks: local, public, private.', $diskName)),
        };
        $disk->withScanner(self::$scanner)->withQuarantine(self::$quarantine)->withImageSanitizer(self::$imageSanitizer);
        return self::$disks[$diskName] = $disk;
    }

    public static function setDisk(string $name, StorageDisk $disk): void
    {
        self::$disks[$name] = $disk;
    }

    public static function put(string $path, string $contents): bool
    {
        return self::disk()->put($path, $contents);
    }

    public static function get(string $path): ?string
    {
        return self::disk()->get($path);
    }

    public static function exists(string $path): bool
    {
        return self::disk()->exists($path);
    }

    public static function delete(string $path): bool
    {
        return self::disk()->delete($path);
    }

    public static function size(string $path): int
    {
        return self::disk()->size($path);
    }

    public static function lastModified(string $path): int
    {
        return self::disk()->lastModified($path);
    }

    public static function mime(string $path): ?string
    {
        return self::disk()->mime($path);
    }

    public static function url(string $path): string
    {
        return self::disk('public')->url($path);
    }

    public static function temporaryUrl(string $path, int $expiresIn = 300): string
    {
        return self::disk('private')->temporaryUrl($path, $expiresIn);
    }

    public static function path(string $path = ''): string
    {
        return self::disk()->path($path);
    }

    /**
     * Clear loaded disks instance cache (useful for testing).
     */
    public static function reset(): void
    {
        self::$disks = [];
        self::$basePath = null;
        self::$scanner = null;
        self::$quarantine = null;
        self::$imageSanitizer = null;
        self::$maxFileSize = 10_485_760;
    }
}
