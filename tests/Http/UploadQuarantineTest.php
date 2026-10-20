<?php

declare(strict_types=1);

namespace Kabuto\Tests\Http;

use Kabuto\Http\MalwareScanner;
use Kabuto\Http\UploadQuarantine;
use Kabuto\Http\UploadedFile;
use Kabuto\Tests\TestCase;

final class UploadQuarantineTest extends TestCase
{
    public function testRejectedUploadIsRemoved(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'kabuto_upload_'); file_put_contents($source, 'bad');
        $scanner = new class implements MalwareScanner { public function scan(string $path): bool { return false; } };
        $quarantine = new UploadQuarantine(sys_get_temp_dir() . '/kabuto_quarantine_' . bin2hex(random_bytes(3)), $scanner);
        self::assertThrows(\RuntimeException::class, fn () => $quarantine->accept(new UploadedFile($source, 'bad.txt')));
    }
}
