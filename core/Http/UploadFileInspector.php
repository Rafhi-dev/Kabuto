<?php

declare(strict_types=1);

namespace Kabuto\Http;

use RuntimeException;

final class UploadFileInspector
{
    private const EXTENSION_MIMES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'pdf' => 'application/pdf', 'zip' => 'application/zip',
        'txt' => 'text/plain', 'csv' => 'text/csv', 'json' => 'application/json',
    ];

    /** Checks known extension/content mismatches and executable/polyglot markers. */
    public static function validate(UploadedFile $file): void
    {
        if (!$file->isValid()) throw new RuntimeException('Uploaded file is invalid.');
        $path = $file->tempPath();
        $sample = self::sample($path);
        $extension = strtolower($file->extension());
        $expected = self::EXTENSION_MIMES[$extension] ?? null;
        $signatureMime = self::signatureMime($sample['head']);
        $detectedMime = strtolower($file->mime());

        if (self::isExecutable($sample['head'])) {
            throw new RuntimeException('Executable content is not allowed in uploaded files.');
        }
        if (self::isPolyglot($sample['head'], $sample['tail'], $signatureMime)) {
            throw new RuntimeException('Polyglot or active-content file uploads are not allowed.');
        }
        if ($expected !== null) {
            $actual = $signatureMime ?? $detectedMime;
            if ($actual !== $expected && !($expected === 'text/plain' && self::isPlainText($sample['head']))) {
                throw new RuntimeException(sprintf('File extension [%s] does not match detected content type [%s].', $extension, $actual));
            }
        }
        if ($signatureMime !== null && $detectedMime !== 'application/octet-stream' && $detectedMime !== $signatureMime) {
            throw new RuntimeException(sprintf('File signature [%s] does not match detected content type [%s].', $signatureMime, $detectedMime));
        }
    }

    /** @return array{head:string,tail:string} */
    private static function sample(string $path): array
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) throw new RuntimeException('Cannot inspect uploaded file content.');
        $head = (string) fread($stream, 65536);
        $size = @filesize($path);
        $tail = '';
        if (is_int($size) && $size > 65536 && fseek($stream, max(0, $size - 65536)) === 0) $tail = (string) fread($stream, 65536);
        fclose($stream);
        return ['head' => $head, 'tail' => $tail];
    }

    private static function signatureMime(string $head): ?string
    {
        if (str_starts_with($head, "\xFF\xD8\xFF")) return 'image/jpeg';
        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) return 'image/png';
        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) return 'image/gif';
        if (strlen($head) >= 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') return 'image/webp';
        if (str_starts_with($head, '%PDF-')) return 'application/pdf';
        if (str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "PK\x05\x06") || str_starts_with($head, "PK\x07\x08")) return 'application/zip';
        return null;
    }

    private static function isExecutable(string $head): bool
    {
        return str_starts_with($head, 'MZ')
            || str_starts_with($head, "\x7FELF")
            || str_starts_with($head, '#!')
            || preg_match('/<\?(?:php|=)/i', $head) === 1;
    }

    private static function isPolyglot(string $head, string $tail, ?string $primaryMime): bool
    {
        $sample = $head . $tail;
        if (preg_match('/<\?(?:php|=)|<script\b|<html\b|<!doctype\s+html/i', $sample) === 1) return true;
        if ($primaryMime !== 'application/zip' && (str_contains(substr($head, 4), "PK\x03\x04") || str_contains($tail, "PK\x03\x04"))) return true;
        if (in_array($primaryMime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)
            && (str_contains($tail, '%PDF-') || str_contains($tail, "\x7FELF") || str_contains($tail, 'MZ'))) return true;
        return false;
    }

    private static function isPlainText(string $head): bool
    {
        return !str_contains($head, "\0") && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $head) !== 1;
    }
}
