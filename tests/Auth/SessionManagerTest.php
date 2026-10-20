<?php

declare(strict_types=1);

namespace Kabuto\Tests\Auth;

use Kabuto\Auth\FileSessionStore;
use Kabuto\Auth\SessionManager;
use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Tests\TestCase;

final class SessionManagerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kabuto-session-' . uniqid();
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) @unlink($file);
        @rmdir($this->directory);
    }

    public function testLoginRegeneratesAndPersistsSession(): void
    {
        $manager = new SessionManager(new FileSessionStore($this->directory));
        $manager->start(new Request('GET', '/'));
        $manager->login(['id' => 7]);
        $response = $manager->commit(new Response());
        $cookie = $response->getCookies()['kabuto_session'];

        self::assertSame(['id' => 7], $manager->user());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('Lax', $cookie->getSameSite());

        $restored = new SessionManager(new FileSessionStore($this->directory));
        $restored->start(new Request('GET', '/', [], [], [], [], ['kabuto_session' => $cookie->getValue()]));
        self::assertSame(['id' => 7], $restored->user());
    }
}
