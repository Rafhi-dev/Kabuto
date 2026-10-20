<?php

declare(strict_types=1);

namespace Kabuto\Tests\Storage;

use Kabuto\Storage\{ImageSanitizer, MalwareScanner, Quarantine, ScanResult, ScanException, StubScanner, PrivateDownloadService, StorageDisk};
use Kabuto\Tests\TestCase;

final class UploadSecurityTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kabuto_upload_security_' . bin2hex(random_bytes(8));
        @mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveRemove($this->tempDir);
    }

    private function recursiveRemove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->recursiveRemove($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function testStubScannerReturnsCleanForNormalFile(): void
    {
        $scanner = new StubScanner();
        $filePath = $this->tempDir . DIRECTORY_SEPARATOR . 'clean.txt';
        file_put_contents($filePath, 'hello');

        $result = $scanner->scan($filePath);

        self::assertTrue($result->clean);
        self::assertNull($result->threat);
        self::assertSame('stub', $result->scannerVersion);
    }

    public function testStubScannerDetectsEicar(): void
    {
        $scanner = new StubScanner();
        $filePath = $this->tempDir . DIRECTORY_SEPARATOR . 'EICAR_test.txt';
        file_put_contents($filePath, 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*');

        $result = $scanner->scan($filePath);

        self::assertFalse($result->clean);
        self::assertSame('Test.EICAR', $result->threat);
    }

    public function testScannerThrowsForMissingFile(): void
    {
        $scanner = new StubScanner();

        self::assertThrows(ScanException::class, fn() => $scanner->scan($this->tempDir . DIRECTORY_SEPARATOR . 'missing.txt'));
    }

    public function testQuarantineMovesFile(): void
    {
        $qDir = $this->tempDir . DIRECTORY_SEPARATOR . 'quarantine';
        $quarantine = new Quarantine($qDir);
        $filePath = $this->tempDir . DIRECTORY_SEPARATOR . 'infected.exe';
        file_put_contents($filePath, 'malware');

        $id = $quarantine->quarantine($filePath, 'Win32.Trojan');

        self::assertFalse(file_exists($filePath));
        self::assertTrue(file_exists($qDir . DIRECTORY_SEPARATOR . $id));
        self::assertTrue(file_exists($qDir . DIRECTORY_SEPARATOR . $id . '.meta'));

        $meta = json_decode(file_get_contents($qDir . DIRECTORY_SEPARATOR . $id . '.meta'), true);
        self::assertSame('Win32.Trojan', $meta['threat']);
    }

    public function testQuarantineListReturnsAllItems(): void
    {
        $qDir = $this->tempDir . DIRECTORY_SEPARATOR . 'quarantine';
        $quarantine = new Quarantine($qDir);

        $file1 = $this->tempDir . DIRECTORY_SEPARATOR . 'bad1.exe';
        $file2 = $this->tempDir . DIRECTORY_SEPARATOR . 'bad2.dll';
        file_put_contents($file1, 'a');
        file_put_contents($file2, 'b');

        $quarantine->quarantine($file1, 'Trojan.A');
        $quarantine->quarantine($file2, 'Trojan.B');

        $list = $quarantine->list();

        self::assertCount(2, $list);
        usort($list, fn($a, $b) => strcmp($a['threat'], $b['threat']));
        self::assertSame('Trojan.A', $list[0]['threat']);
        self::assertSame('Trojan.B', $list[1]['threat']);
    }

    public function testQuarantineDeleteRemovesFile(): void
    {
        $qDir = $this->tempDir . DIRECTORY_SEPARATOR . 'quarantine';
        $quarantine = new Quarantine($qDir);
        $filePath = $this->tempDir . DIRECTORY_SEPARATOR . 'test.bin';
        file_put_contents($filePath, 'data');

        $id = $quarantine->quarantine($filePath, 'Test');
        self::assertCount(1, $quarantine->list());

        $deleted = $quarantine->delete($id);

        self::assertTrue($deleted);
        self::assertCount(0, $quarantine->list());
    }

    public function testImageSanitizerRemovesExifAndRotates(): void
    {
        if (!extension_loaded('gd')) {
            return;
        }

        $sanitizer = new ImageSanitizer();
        $sourcePath = $this->tempDir . DIRECTORY_SEPARATOR . 'photo.jpg';

        $img = imagecreatetruecolor(100, 50);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 0, 0));
        imagejpeg($img, $sourcePath);
        imagedestroy($img);

        $targetPath = $this->tempDir . DIRECTORY_SEPARATOR . 'sanitized.jpg';
        $sanitizer->sanitize($sourcePath, $targetPath, 90);

        self::assertTrue(file_exists($targetPath));
        self::assertTrue(filesize($targetPath) > 0);

        if (extension_loaded('exif')) {
            $exif = @exif_read_data($targetPath);
            self::assertFalse(is_array($exif) && isset($exif['Make']));
        }
    }

    public function testStorageDiskWithScannerRejectsInfectedFile(): void
    {
        $diskRoot = $this->tempDir . DIRECTORY_SEPARATOR . 'disk';
        $disk = new StorageDisk($diskRoot);

        $qDir = $this->tempDir . DIRECTORY_SEPARATOR . 'quarantine';
        $quarantine = new Quarantine($qDir);
        $scanner = new class implements MalwareScanner {
            public function scan(string $filePath): ScanResult { return ScanResult::infected('Test.EICAR', 'test'); }
        };

        $disk->withScanner($scanner)->withQuarantine($quarantine);

        $uploadPath = $this->tempDir . DIRECTORY_SEPARATOR . 'upload.txt';
        file_put_contents($uploadPath, 'eicar');

        $uploadedFile = $this->createUploadedFile($uploadPath, 'upload_EICAR.txt', 'text/plain');

        self::assertThrows(\RuntimeException::class, fn() => $disk->putFile('uploads', $uploadedFile));

        $list = $quarantine->list();
        self::assertCount(1, $list);
        $threat = $list[0]['threat'] ?? '';
        self::assertTrue(str_contains($threat, 'EICAR'));
    }

    public function testStorageDiskRejectsMismatchedSignatureBeforeWriting(): void
    {
        $disk = new StorageDisk($this->tempDir . DIRECTORY_SEPARATOR . 'disk');
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'fake.png';
        file_put_contents($path, "MZ\x90\0not an image");
        $upload = $this->createUploadedFile($path, 'fake.png', 'image/png');

        self::assertThrows(\RuntimeException::class, fn () => $disk->putFile('images', $upload));
        self::assertFalse(is_dir($this->tempDir . DIRECTORY_SEPARATOR . 'disk' . DIRECTORY_SEPARATOR . 'images'));
    }

    public function testStorageDiskWithSanitizerProcessesImages(): void
    {
        if (!extension_loaded('gd')) {
            return;
        }

        $diskRoot = $this->tempDir . DIRECTORY_SEPARATOR . 'disk';
        $disk = new StorageDisk($diskRoot);
        $sanitizer = new ImageSanitizer();
        $disk->withImageSanitizer($sanitizer);

        $uploadPath = $this->tempDir . DIRECTORY_SEPARATOR . 'upload.jpg';
        $img = imagecreatetruecolor(80, 40);
        imagejpeg($img, $uploadPath);
        imagedestroy($img);

        $uploadedFile = $this->createUploadedFile($uploadPath, 'upload.jpg', 'image/jpeg');

        $storedPath = $disk->putFile('images', $uploadedFile);

        self::assertTrue($disk->exists($storedPath));
        self::assertTrue(filesize($disk->path($storedPath)) > 0);
    }

    public function testPrivateDownloadServiceSignsAndVerifiesToken(): void
    {
        $disk = new StorageDisk($this->tempDir . DIRECTORY_SEPARATOR . 'disk');
        $service = new PrivateDownloadService($disk, 'secret-key-123', 300);

        $token = $service->signUrl('private/file.pdf', 3600, ['user_id' => 42]);

        $payload = $service->verifyToken($token);

        self::assertSame('private/file.pdf', $payload['path']);
        self::assertSame(42, $payload['context']['user_id']);
        self::assertTrue($payload['expires'] > time());
    }

    public function testPrivateDownloadServiceRejectsExpiredToken(): void
    {
        $disk = new StorageDisk($this->tempDir . DIRECTORY_SEPARATOR . 'disk');
        $service = new PrivateDownloadService($disk, 'secret-key-123');

        $token = $service->signUrl('file.txt', 1);
        $parts = explode('.', $token, 2);
        $payload = json_decode(base64_decode($parts[0]), true);
        $payload['expires'] = time() - 1;
        $encoded = base64_encode(json_encode($payload));
        $token = $encoded . '.' . hash_hmac('sha256', $encoded, 'secret-key-123');

        self::assertThrows(\RuntimeException::class, fn() => $service->verifyToken($token));
    }

    public function testPrivateDownloadServiceRejectsInvalidSignature(): void
    {
        $disk = new StorageDisk($this->tempDir . DIRECTORY_SEPARATOR . 'disk');
        $service = new PrivateDownloadService($disk, 'secret-key-123');

        $token = $service->signUrl('file.txt', 300);
        $tampered = substr($token, 0, -5) . 'XXXXX';

        self::assertThrows(\RuntimeException::class, fn() => $service->verifyToken($tampered));
    }

    public function testPrivateDownloadRequiresAuthorizationAndReturnsSafeResponse(): void
    {
        $disk = new StorageDisk($this->tempDir . DIRECTORY_SEPARATOR . 'disk');
        $disk->put('private.txt', 'private body');
        $service = new PrivateDownloadService($disk, 'secret-key-123');
        $token = $service->signUrl('private.txt', 300, ['user_id' => 7]);

        self::assertSame(403, $service->response($token, static fn (): bool => false)->getStatus());
        $response = $service->response($token, static fn (string $path, array $context): bool => $path === 'private.txt' && $context['user_id'] === 7);
        self::assertSame(200, $response->getStatus());
        self::assertSame('private body', $response->getBody());
        self::assertSame('private, no-store', $response->headers()['Cache-Control']);
    }

    private function createUploadedFile(string $path, string $clientName, string $mimeType): \Kabuto\Http\UploadedFile
    {
        $size = filesize($path);
        $error = UPLOAD_ERR_OK;
        $tmpName = $path;

        return new \Kabuto\Http\UploadedFile($tmpName, $clientName, $mimeType, $size, $error);
    }
}
