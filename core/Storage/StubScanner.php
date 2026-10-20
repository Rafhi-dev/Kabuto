<?php

declare(strict_types=1);

namespace Kabuto\Storage;

/**
 * Stub scanner untuk test dan environment tanpa scanner nyata.
 * Selalu mengembalikan clean kecuali nama file mengandung 'EICAR'.
 */
final class StubScanner implements MalwareScanner
{
    public function scan(string $filePath): ScanResult
    {
        $normalized = str_replace('/', DIRECTORY_SEPARATOR, $filePath);

        if (!is_file($normalized)) {
            throw new ScanException(sprintf('File [%s] does not exist.', $filePath));
        }

        $basename = basename($normalized);
        if (str_contains(strtoupper($basename), 'EICAR')) {
            return ScanResult::infected('Test.EICAR', 'stub');
        }

        return ScanResult::clean('stub');
    }
}
