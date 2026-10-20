<?php

declare(strict_types=1);

namespace Kabuto\Tests\Http;

use Kabuto\Application;
use Kabuto\Common\Controller;
use Kabuto\Common\Post;
use Kabuto\Common\UploadedFile as UploadedFileAttribute;
use Kabuto\Exceptions\ValidationException;
use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Http\UploadedFile;
use Kabuto\Storage\Storage;
use Kabuto\Tests\TestCase;

final class UploadedFileTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/kabuto-upload-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);
        Storage::setBasePath($this->tempDir);
    }

    protected function tearDown(): void
    {
        Storage::reset();
        $this->removeDir($this->tempDir);
    }

    public function testUploadedFileProperties(): void
    {
        $tmpFile = $this->tempDir . '/test.txt';
        file_put_contents($tmpFile, 'Hello MachFrame Storage!');

        $uploaded = new UploadedFile(
            tempPath: $tmpFile,
            clientFilename: 'sample-document.TXT',
            clientMediaType: 'text/plain',
            size: strlen('Hello MachFrame Storage!'),
        );

        self::assertSame('sample-document.TXT', $uploaded->name());
        self::assertSame('txt', $uploaded->extension());
        self::assertSame('text/plain', $uploaded->clientMime());
        self::assertSame(24, $uploaded->size());
        self::assertTrue($uploaded->isValid());
        self::assertSame('Hello MachFrame Storage!', $uploaded->getContent());
    }

    public function testStoreAndStoreAs(): void
    {
        $tmpFile = $this->tempDir . '/pic.png';
        file_put_contents($tmpFile, 'fake-png-data');

        $uploaded = new UploadedFile(
            tempPath: $tmpFile,
            clientFilename: 'my-avatar.png',
            clientMediaType: 'image/png',
        );

        $storedPath = $uploaded->store('avatars', 'public');
        self::assertTrue(str_starts_with($storedPath, 'avatars/'));
        self::assertTrue(str_ends_with($storedPath, '.png'));
        self::assertTrue(Storage::disk('public')->exists($storedPath));
        self::assertSame('fake-png-data', Storage::disk('public')->get($storedPath));

        // Recreate temp file for second store
        file_put_contents($tmpFile, 'second-png-data');
        $uploaded2 = new UploadedFile($tmpFile, 'custom.png', 'image/png');
        $customPath = $uploaded2->storeAs('photos', 'exact-name.png', 'local');

        self::assertSame('photos/exact-name.png', $customPath);
        self::assertTrue(Storage::disk('local')->exists('photos/exact-name.png'));
        self::assertSame('second-png-data', Storage::disk('local')->get('photos/exact-name.png'));
    }

    public function testControllerUploadedFileAttributeSuccess(): void
    {
        $tmpFile = $this->tempDir . '/avatar.jpg';
        file_put_contents($tmpFile, 'image-binary-data');

        $app = new Application(basePath: $this->tempDir);
        $app->boot();

        $controller = new class {
            #[Post('/upload')]
            public function upload(
                #[UploadedFileAttribute('avatar', maxSize: '1MB', mimes: ['image/jpeg', 'jpg'])] UploadedFile $file
            ): array {
                return [
                    'name' => $file->name(),
                    'ext' => $file->extension(),
                ];
            }
        };

        $app->router()->post('/upload', [$controller, 'upload']);

        $request = new Request(
            method: 'POST',
            path: '/upload',
            files: [
                'avatar' => new UploadedFile($tmpFile, 'avatar.jpg', 'image/jpeg', filesize($tmpFile)),
            ],
        );

        $response = $app->handle($request);

        self::assertSame(200, $response->getStatus());
        self::assertSame(
            json_encode(['name' => 'avatar.jpg', 'ext' => 'jpg']),
            $response->getBody(),
        );
    }

    public function testControllerUploadedFileMaxSizeExceededThrowsValidationException(): void
    {
        $tmpFile = $this->tempDir . '/large.jpg';
        file_put_contents($tmpFile, str_repeat('A', 2048)); // 2 KB

        $app = new Application(basePath: $this->tempDir);
        $app->boot();

        $controller = new class {
            #[Post('/upload-small')]
            public function upload(
                #[UploadedFileAttribute('avatar', maxSize: '1KB')] UploadedFile $file
            ): array {
                return ['ok' => true];
            }
        };

        $app->router()->post('/upload-small', [$controller, 'upload']);

        $request = new Request(
            method: 'POST',
            path: '/upload-small',
            files: [
                'avatar' => new UploadedFile($tmpFile, 'large.jpg', 'image/jpeg', 2048),
            ],
        );

        $response = $app->handle($request);

        self::assertSame(422, $response->getStatus());
        self::assertStringContains('must not be greater than 1KB', $response->getBody());
    }

    public function testControllerUploadedFileInvalidMimeThrowsValidationException(): void
    {
        $tmpFile = $this->tempDir . '/doc.txt';
        file_put_contents($tmpFile, 'sample text');

        $app = new Application(basePath: $this->tempDir);
        $app->boot();

        $controller = new class {
            #[Post('/upload-image-only')]
            public function upload(
                #[UploadedFileAttribute('avatar', mimes: ['image/jpeg', 'image/png'])] UploadedFile $file
            ): array {
                return ['ok' => true];
            }
        };

        $app->router()->post('/upload-image-only', [$controller, 'upload']);

        $request = new Request(
            method: 'POST',
            path: '/upload-image-only',
            files: [
                'avatar' => new UploadedFile($tmpFile, 'doc.txt', 'text/plain', 11),
            ],
        );

        $response = $app->handle($request);

        self::assertSame(422, $response->getStatus());
        self::assertStringContains('must be of type', $response->getBody());
    }

    public function testControllerOptionalUploadedFileAllowsNull(): void
    {
        $app = new Application(basePath: $this->tempDir);
        $app->boot();

        $controller = new class {
            #[Post('/upload-optional')]
            public function upload(
                #[UploadedFileAttribute('avatar', required: false)] ?UploadedFile $file = null
            ): array {
                return ['has_file' => $file !== null];
            }
        };

        $app->router()->post('/upload-optional', [$controller, 'upload']);

        $request = new Request(
            method: 'POST',
            path: '/upload-optional',
            files: [],
        );

        $response = $app->handle($request);

        self::assertSame(200, $response->getStatus());
        self::assertSame(json_encode(['has_file' => false]), $response->getBody());
    }

    public function testInvalidMaxSizeConfigurationIsRejected(): void
    {
        $tmpFile = $this->tempDir . '/file.txt';
        file_put_contents($tmpFile, 'content');

        $app = new Application(basePath: $this->tempDir);
        $app->boot();
        $controller = new class {
            #[Post('/upload-invalid-size')]
            public function upload(
                #[UploadedFileAttribute('file', maxSize: 'not-a-size')] UploadedFile $file,
            ): array {
                return ['ok' => true];
            }
        };
        $app->router()->post('/upload-invalid-size', [$controller, 'upload']);

        $response = $app->handle(new Request(
            method: 'POST',
            path: '/upload-invalid-size',
            files: ['file' => new UploadedFile($tmpFile, 'file.txt', 'text/plain')],
        ));

        self::assertSame(500, $response->getStatus());
        self::assertStringContains('INTERNAL_ERROR', $response->getBody());
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
