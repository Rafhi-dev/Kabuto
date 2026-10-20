<?php

declare(strict_types=1);

namespace Kabuto\Tests\Auth;

use Kabuto\Auth\FileTokenRevoker;
use Kabuto\Tests\TestCase;

final class FileTokenRevokerTest extends TestCase
{
    public function testRevocationSurvivesNewInstance(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kabuto_revoker_'); @unlink($path);
        $first = new FileTokenRevoker($path); $first->revoke('token-id', time() + 60);
        self::assertTrue((new FileTokenRevoker($path))->isRevoked('token-id'));
        @unlink($path);
    }
}
