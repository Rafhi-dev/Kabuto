<?php

declare(strict_types=1);

namespace Kabuto\Http;

use RuntimeException;

final class UploadValidator
{
    /** @param list<string> $mime */
    public static function validate(UploadedFile $file, int $maxSize = 0, array $mime = [], ?int $maxWidth = null, ?int $maxHeight = null): void
    {
        if (!$file->isValid()) throw new RuntimeException('Uploaded file is invalid.');
        UploadFileInspector::validate($file);
        if ($maxSize > 0 && $file->size() > $maxSize) throw new RuntimeException('Uploaded file exceeds the configured size limit.');
        if ($mime !== [] && !in_array(strtolower($file->mime()), array_map('strtolower', $mime), true)) throw new RuntimeException('Uploaded file MIME type is not allowed.');
        if (($maxWidth !== null || $maxHeight !== null) && function_exists('getimagesize')) {
            $dimensions = @getimagesize($file->tempPath());
            if (is_array($dimensions) && (($maxWidth !== null && $dimensions[0] > $maxWidth) || ($maxHeight !== null && $dimensions[1] > $maxHeight))) throw new RuntimeException('Uploaded image dimensions exceed the configured limit.');
        }
    }

    /** @param list<UploadedFile> $files */
    public static function validateMany(array $files, int $maxFiles = 10, int $maxTotalSize = 20_971_520, int $maxFileSize = 10_485_760, array $mime = []): void
    {
        if ($maxFiles > 0 && count($files) > $maxFiles) throw new RuntimeException('Uploaded file count exceeds the configured limit.');
        $total = 0;
        foreach ($files as $file) {
            self::validate($file, $maxFileSize, $mime);
            $total += $file->size();
        }
        if ($maxTotalSize > 0 && $total > $maxTotalSize) throw new RuntimeException('Total uploaded file size exceeds the configured limit.');
    }
}
