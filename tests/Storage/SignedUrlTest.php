<?php

declare(strict_types=1);

namespace Kabuto\Tests\Storage;

use Kabuto\Storage\Storage;
use Kabuto\Tests\TestCase;

final class SignedUrlTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kabuto-signed-' . uniqid();
        mkdir($this->directory, 0777, true);
        Storage::setBasePath($this->directory);
    }

    protected function tearDown(): void
    {
        Storage::reset();
        @unlink($this->directory . '/storage/app/private/docs/file.txt');
        @rmdir($this->directory . '/storage/app/private/docs');
        @rmdir($this->directory . '/storage/app/private');
        @rmdir($this->directory . '/storage/app');
        @rmdir($this->directory . '/storage');
        @rmdir($this->directory);
    }

    public function testPrivateDiskCreatesVerifiableTemporaryUrl(): void
    {
        $disk = Storage::disk('private');
        $disk->put('docs/file.txt', 'secret');
        $url = $disk->temporaryUrl('docs/file.txt', 60);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $path = (string) parse_url($url, PHP_URL_PATH);

        self::assertSame('/docs/file.txt', $path);
        self::assertTrue($disk->verifyTemporaryUrl('docs/file.txt', (int) $query['expires'], (string) $query['signature']));
        self::assertFalse($disk->verifyTemporaryUrl('docs/missing.txt', (int) $query['expires'], (string) $query['signature']));
    }
}
