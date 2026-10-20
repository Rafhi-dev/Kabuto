<?php

declare(strict_types=1);

namespace Kabuto\Storage;

use RuntimeException;

/**
 * Quarantine system untuk file yang gagal scan atau suspicious.
 */
final class Quarantine
{
    public function __construct(
        private readonly string $quarantinePath,
    ) {
        if (!is_dir($this->quarantinePath)) {
            if (!mkdir($this->quarantinePath, 0700, true) && !is_dir($this->quarantinePath)) {
                throw new RuntimeException(sprintf('Quarantine directory [%s] could not be created.', $this->quarantinePath));
            }
        }
    }

    /**
     * Pindahkan file ke quarantine dengan metadata threat.
     *
     * @return string Quarantine ID (basename file dalam quarantine)
     */
    public function quarantine(string $filePath, string $threat = 'unknown'): string
    {
        if (!is_file($filePath)) {
            throw new RuntimeException(sprintf('File [%s] does not exist.', $filePath));
        }

        $id = date('Ymd_His') . '_' . bin2hex(random_bytes(8));
        $targetPath = $this->quarantinePath . DIRECTORY_SEPARATOR . $id;
        $metaPath = $targetPath . '.meta';

        if (!rename($filePath, $targetPath)) {
            throw new RuntimeException(sprintf('Failed to move file [%s] to quarantine.', $filePath));
        }

        $meta = json_encode([
            'threat' => $threat,
            'original_path' => $filePath,
            'quarantined_at' => time(),
        ], JSON_THROW_ON_ERROR);

        file_put_contents($metaPath, $meta);

        return $id;
    }

    /**
     * Hapus file dari quarantine.
     */
    public function delete(string $id): bool
    {
        $targetPath = $this->quarantinePath . DIRECTORY_SEPARATOR . $id;
        $metaPath = $targetPath . '.meta';

        $deleted = false;
        if (is_file($targetPath)) {
            $deleted = unlink($targetPath);
        }
        if (is_file($metaPath)) {
            @unlink($metaPath);
        }

        return $deleted;
    }

    /**
     * List semua file dalam quarantine.
     *
     * @return list<array{id: string, threat: string, original_path: string, quarantined_at: int}>
     */
    public function list(): array
    {
        $items = [];

        foreach (glob($this->quarantinePath . DIRECTORY_SEPARATOR . '*.meta') ?: [] as $metaPath) {
            $id = basename($metaPath, '.meta');
            $meta = json_decode(file_get_contents($metaPath) ?: '{}', true);

            $items[] = [
                'id' => $id,
                'threat' => $meta['threat'] ?? 'unknown',
                'original_path' => $meta['original_path'] ?? '',
                'quarantined_at' => $meta['quarantined_at'] ?? 0,
            ];
        }

        return $items;
    }
}
