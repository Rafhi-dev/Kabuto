<?php

declare(strict_types=1);

namespace Kabuto\Tests\Storage;

use Kabuto\Application;
use Kabuto\Console\Commands\StorageLinkCommand;
use Kabuto\Console\Console;
use Kabuto\Http\Request;
use Kabuto\Storage\Storage;
use Kabuto\Tests\TestCase;

final class StorageTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/kabuto-storage-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);
        Storage::setBasePath($this->tempDir);
    }

    protected function tearDown(): void
    {
        Storage::reset();
        $this->removeDir($this->tempDir);
    }

    public function testStorageDisksAndOperations(): void
    {
        $local = Storage::disk('local');
        $public = Storage::disk('public');

        self::assertTrue($local->put('documents/note.txt', 'Secret content'));
        self::assertTrue($local->exists('documents/note.txt'));
        self::assertSame('Secret content', $local->get('documents/note.txt'));
        self::assertSame(14, $local->size('documents/note.txt'));
        self::assertTrue($local->lastModified('documents/note.txt') > 0);
        self::assertSame('text/plain', $local->mime('documents/note.txt'));
        self::assertTrue($local->delete('documents/note.txt'));
        self::assertFalse($local->exists('documents/note.txt'));

        // Default facade forwarding (local disk)
        self::assertTrue(Storage::put('default.txt', 'Default disk text'));
        self::assertTrue(Storage::exists('default.txt'));
        self::assertSame('Default disk text', Storage::get('default.txt'));
        self::assertTrue(Storage::delete('default.txt'));

        // Helper function
        self::assertTrue(storage('public')->put('hello.txt', 'Hello Public'));
        self::assertSame('/storage/hello.txt', storage('public')->url('hello.txt'));
        self::assertSame('Hello Public', storage('public')->get('hello.txt'));
    }

    public function testPutFileAndPutFileAs(): void
    {
        $tmp = $this->tempDir . '/temp-upload.json';
        file_put_contents($tmp, '{"status":"ok"}');

        $uploaded = new \Kabuto\Http\UploadedFile($tmp, 'data.json', 'application/json');

        $path = Storage::disk('local')->putFile('uploads', $uploaded);
        self::assertTrue(str_starts_with($path, 'uploads/'));
        self::assertTrue(str_ends_with($path, '.json'));
        self::assertTrue(Storage::disk('local')->exists($path));
        self::assertSame('{"status":"ok"}', Storage::disk('local')->get($path));

        file_put_contents($tmp, '{"custom":true}');
        $uploaded2 = new \Kabuto\Http\UploadedFile($tmp, 'custom.json', 'application/json');
        $customPath = Storage::disk('public')->putFileAs('configs', $uploaded2, 'app-config.json');
        self::assertSame('configs/app-config.json', $customPath);
        self::assertTrue(Storage::disk('public')->exists('configs/app-config.json'));
        self::assertSame('{"custom":true}', Storage::disk('public')->get('configs/app-config.json'));
    }

    public function testStorageLinkCommand(): void
    {
        mkdir($this->tempDir . '/public', 0777, true);
        mkdir($this->tempDir . '/storage/app/public', 0777, true);

        $console = new Console($this->tempDir, new Application(basePath: $this->tempDir));
        $cmd = new StorageLinkCommand($console, []);

        ob_start();
        $status = $cmd->handle();
        $output = ob_get_clean();

        self::assertSame(0, $status);
        self::assertTrue(file_exists($this->tempDir . '/public/storage'));

        // Running again should handle existing link gracefully
        ob_start();
        $status2 = $cmd->handle();
        $output2 = ob_get_clean();

        self::assertSame(0, $status2);
    }

    public function testApplicationStorageStaticRoute(): void
    {
        $app = new Application(basePath: $this->tempDir);
        $app->boot();

        Storage::disk('public')->put('images/test.txt', 'MachFrame Static File Content');

        // Requesting existing static file
        $request = new Request(method: 'GET', path: '/storage/images/test.txt');
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatus());
        self::assertSame('MachFrame Static File Content', $response->getBody());
        self::assertSame('text/plain', $response->headers()['Content-Type']);

        Storage::disk('public')->put('images/avatar.png', 'fake-png-bytes');
        $pngReq = new Request(method: 'GET', path: '/storage/images/avatar.png');
        $pngRes = $app->handle($pngReq);
        self::assertSame(200, $pngRes->getStatus());
        self::assertSame('image/png', $pngRes->headers()['Content-Type']);
        self::assertSame('fake-png-bytes', $pngRes->getBody());

        // Requesting non-existent file returns 404
        $req404 = new Request(method: 'GET', path: '/storage/images/not-found.txt');
        $res404 = $app->handle($req404);
        self::assertSame(404, $res404->getStatus());

        // Path traversal returns 404
        $reqTraversal = new Request(method: 'GET', path: '/storage/../../secret.txt');
        $resTraversal = $app->handle($reqTraversal);
        self::assertSame(404, $resTraversal->getStatus());
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows' && is_dir($path . '/public/storage')) {
            @exec(sprintf('rmdir "%s"', str_replace('/', '\\', $path . '/public/storage')));
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}
