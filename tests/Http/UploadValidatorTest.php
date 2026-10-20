<?php

declare(strict_types=1);

namespace Kabuto\Tests\Http;

use Kabuto\Http\UploadValidator;
use Kabuto\Http\UploadedFile;
use Kabuto\Tests\TestCase;

final class UploadValidatorTest extends TestCase
{
    public function testMimeAndSizeAreValidatedFromContent(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kabuto_upload_');
        file_put_contents($path, 'hello');
        $file = new UploadedFile($path, 'hello.txt', 'image/png');
        $detectedMime = $file->mime();
        UploadValidator::validate($file, 100, [$detectedMime]);
        self::assertSame($detectedMime, $file->mime());
        self::assertThrows(\RuntimeException::class, fn () => UploadValidator::validate($file, 2));
        @unlink($path);
    }

    public function testRejectsExecutableContentDisguisedAsText(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kabuto-upload-exe-');
        file_put_contents($path, "MZ\x90\0fake executable");
        $file = new UploadedFile($path, 'document.txt', 'text/plain');
        self::assertThrows(\RuntimeException::class, static fn () => UploadValidator::validate($file));
        @unlink($path);
    }

    public function testRejectsExtensionAndSignatureMismatch(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kabuto-upload-mime-');
        file_put_contents($path, "\xFF\xD8\xFF\xE0" . str_repeat('x', 20));
        $file = new UploadedFile($path, 'image.png', 'image/png');
        self::assertThrows(\RuntimeException::class, static fn () => UploadValidator::validate($file));
        @unlink($path);
    }

    public function testRejectsImageAndEmbeddedScriptPolyglot(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kabuto-upload-polyglot-');
        file_put_contents($path, "\x89PNG\r\n\x1A\n" . str_repeat('x', 32) . '<?php echo 1; ?>');
        $file = new UploadedFile($path, 'image.png', 'image/png');
        self::assertThrows(\RuntimeException::class, static fn () => UploadValidator::validate($file));
        @unlink($path);
    }

    public function testBatchValidationEnforcesFileCountAndTotalBytes(): void
    {
        $firstPath = tempnam(sys_get_temp_dir(), 'kabuto-upload-batch-');
        $secondPath = tempnam(sys_get_temp_dir(), 'kabuto-upload-batch-');
        file_put_contents($firstPath, 'first');
        file_put_contents($secondPath, 'second');
        $files = [new UploadedFile($firstPath, 'first.txt'), new UploadedFile($secondPath, 'second.txt')];

        self::assertThrows(\RuntimeException::class, static fn () => UploadValidator::validateMany($files, maxFiles: 1));
        self::assertThrows(\RuntimeException::class, static fn () => UploadValidator::validateMany($files, maxTotalSize: 8));
        UploadValidator::validateMany($files, maxFiles: 2, maxTotalSize: 20);

        @unlink($firstPath);
        @unlink($secondPath);
    }
}
