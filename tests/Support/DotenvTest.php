<?php

declare(strict_types=1);

namespace Kabuto\Tests\Support;

use Kabuto\Support\Dotenv;
use Kabuto\Tests\TestCase;

final class DotenvTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kabuto-dotenv-' . uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }

            rmdir($this->dir);
        }

        unset($_SERVER['DOTENV_TEST_A'], $_SERVER['DOTENV_TEST_B'], $_SERVER['DOTENV_TEST_C']);
        unset($_ENV['DOTENV_TEST_A'], $_ENV['DOTENV_TEST_B'], $_ENV['DOTENV_TEST_C']);
    }

    public function testParsesBasicSyntax(): void
    {
        file_put_contents($this->dir . '/.env', <<<'ENV'
            # comment
            export DOTENV_TEST_A=hello
            DOTENV_TEST_B="quoted value"
            DOTENV_TEST_C='single ${NOT_EXPANDED}'
            EMPTY_LINE_BELOW=

            INVALID LINE WITHOUT EQUALS
            ENV);

        $parsed = Dotenv::parse($this->dir . '/.env');

        self::assertSame('hello', $parsed['DOTENV_TEST_A']);
        self::assertSame('quoted value', $parsed['DOTENV_TEST_B']);
        self::assertSame('single ${NOT_EXPANDED}', $parsed['DOTENV_TEST_C']);
        self::assertSame('', $parsed['EMPTY_LINE_BELOW']);
        self::assertFalse(array_key_exists('INVALID LINE WITHOUT EQUALS', $parsed));
    }

    public function testInterpolatesVariables(): void
    {
        file_put_contents($this->dir . '/.env', "DOTENV_TEST_A=base\nDOTENV_TEST_B=\${DOTENV_TEST_A}/sub\n");

        $parsed = Dotenv::parse($this->dir . '/.env');

        self::assertSame('base/sub', $parsed['DOTENV_TEST_B']);
    }

    public function testLoadDoesNotOverwriteRealEnvironment(): void
    {
        $_SERVER['DOTENV_TEST_A'] = 'real';

        file_put_contents($this->dir . '/.env', "DOTENV_TEST_A=from-file\nDOTENV_TEST_B=from-file\n");
        Dotenv::load($this->dir);

        self::assertSame('real', $_SERVER['DOTENV_TEST_A']);
        self::assertSame('from-file', $_SERVER['DOTENV_TEST_B']);

        unset($_SERVER['DOTENV_TEST_B'], $_ENV['DOTENV_TEST_B']);
        putenv('DOTENV_TEST_B');
    }

    public function testLoadMissingFileIsSilent(): void
    {
        Dotenv::load($this->dir . '/does-not-exist');

        self::assertTrue(true);
    }

    public function testRequiredThrowsOnMissing(): void
    {
        self::assertThrows(
            \RuntimeException::class,
            static fn () => Dotenv::required(['DOTENV_DEFINITELY_MISSING_XYZ']),
        );
    }

    public function testRequiredPassesWhenPresent(): void
    {
        $_SERVER['DOTENV_TEST_A'] = 'x';

        Dotenv::required(['DOTENV_TEST_A']);

        self::assertTrue(true);
    }
}
