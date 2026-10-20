<?php

declare(strict_types=1);

namespace Kabuto\Tests\Storage;

use Kabuto\Storage\PrivateDownload;
use Kabuto\Storage\StorageDisk;
use Kabuto\Tests\TestCase;

final class PrivateDownloadTest extends TestCase
{
    public function testAuthorizedFileReturnsDownloadResponse(): void
    {
        $root = sys_get_temp_dir() . '/kabuto_private_' . bin2hex(random_bytes(3)); $disk = new StorageDisk($root);
        $disk->put('secret.txt', 'secret');
        $response = (new PrivateDownload($disk))->response('secret.txt', static fn () => true);
        self::assertSame(200, $response->getStatus()); self::assertSame('secret', $response->getBody());
    }
}
