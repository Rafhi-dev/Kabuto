<?php

declare(strict_types=1);

namespace Kabuto\Tests\Console;

use Kabuto\Application;
use Kabuto\Console\Commands\MakeDtoCommand;
use Kabuto\Console\Commands\MakeMiddlewareCommand;
use Kabuto\Console\Commands\MakeModuleCommand;
use Kabuto\Console\Commands\MakeResourceCommand;
use Kabuto\Console\Console;
use Kabuto\Tests\TestCase;

final class GeneratorTest extends TestCase
{
    private string $basePath;

    /** @var list<string> */
    private array $created = [];

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/kabuto-test-' . uniqid();
        mkdir($this->basePath, 0777, true);
        ob_start();
    }

    public function testModuleSlashName(): void
    {
        $console = new Console($this->basePath, new Application());

        $status = (new MakeDtoCommand($console, ['User/CreateUser']))->handle();

        self::assertSame(0, $status);
        self::assertTrue(is_file($this->basePath . '/app/Modules/User/Dto/CreateUserDto.php'));

        $contents = file_get_contents($this->basePath . '/app/Modules/User/Dto/CreateUserDto.php');
        self::assertStringContains('namespace App\\Modules\\User\\Dto;', $contents);
        self::assertStringContains('class CreateUserDto', $contents);
    }

    public function testBackslashName(): void
    {
        $console = new Console($this->basePath, new Application());

        $status = (new MakeDtoCommand($console, ['User\\CreateUser']))->handle();

        self::assertSame(0, $status);
        self::assertTrue(is_file($this->basePath . '/app/Modules/User/Dto/CreateUserDto.php'));
    }

    public function testSingleNameKeepsLegacyBehavior(): void
    {
        $console = new Console($this->basePath, new Application());

        $status = (new MakeMiddlewareCommand($console, ['Auth']))->handle();

        self::assertSame(0, $status);
        self::assertTrue(is_file($this->basePath . '/app/Middleware/Auth.php'));
    }

    public function testExistingFileIsRejectedWithoutForce(): void
    {
        $console = new Console($this->basePath, new Application());

        self::assertSame(0, (new MakeMiddlewareCommand($console, ['Auth']))->handle());
        self::assertSame(1, (new MakeMiddlewareCommand($console, ['Auth']))->handle());
        self::assertSame(0, (new MakeMiddlewareCommand($console, ['Auth', '--force']))->handle());
    }

    public function testMissingNameThrows(): void
    {
        $console = new Console($this->basePath, new Application());

        self::assertThrows(
            \RuntimeException::class,
            static fn () => (new MakeMiddlewareCommand($console, []))->handle(),
        );
    }

    public function testMakeModuleEmitsModuleFileWithoutRoutes(): void
    {
        $console = new Console($this->basePath, new Application());

        $status = (new MakeModuleCommand($console, ['Order']))->handle();

        self::assertSame(0, $status);

        $base = $this->basePath . '/app/Modules/Order';
        self::assertTrue(is_file($base . '/OrderModule.php'));
        self::assertTrue(is_file($base . '/OrderController.php'));
        self::assertTrue(is_file($base . '/OrderService.php'));
        self::assertTrue(is_file($base . '/OrderRepository.php'));
        self::assertTrue(is_file($base . '/Dto/CreateOrderDto.php'));
        self::assertTrue(is_file($base . '/Dto/OrderDto.php'));
        self::assertFalse(is_file($base . '/routes.php'));

        $module = file_get_contents($base . '/OrderModule.php');
        self::assertStringContains('#[Module(', $module);
        self::assertStringContains('OrderController::class', $module);
        self::assertStringContains('OrderService::class', $module);

        $controller = file_get_contents($base . '/OrderController.php');
        self::assertStringContains("#[Controller('orders')]", $controller);
        self::assertStringContains('#[Get(', $controller);
        self::assertFalse(str_contains($controller, 'NotFoundException'));

        $service = file_get_contents($base . '/OrderService.php');
        self::assertStringContains('NotFoundException', $service);
    }

    public function testMakeResourceEmitsUpdateDto(): void
    {
        $console = new Console($this->basePath, new Application());

        $status = (new MakeResourceCommand($console, ['Category']))->handle();

        self::assertSame(0, $status);

        $base = $this->basePath . '/app/Modules/Category';
        self::assertTrue(is_file($base . '/CategoryModule.php'));
        self::assertTrue(is_file($base . '/Dto/UpdateCategoryDto.php'));

        $controller = file_get_contents($base . '/CategoryController.php');
        self::assertStringContains('UpdateCategoryDto', $controller);
        self::assertFalse(str_contains($controller, 'NotFoundException'));

        $dto = file_get_contents($base . '/Dto/UpdateCategoryDto.php');
        self::assertFalse(str_contains($dto, 'public function toArray'));

        $createDto = file_get_contents($base . '/Dto/CreateCategoryDto.php');
        self::assertFalse(str_contains($createDto, 'public function toArray'));

        $service = file_get_contents($base . '/CategoryService.php');
        self::assertStringContains('CategoryRepository', $service);
        self::assertStringContains('NotFoundException', $service);
    }

    protected function tearDown(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $this->remove($this->basePath);
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($path);
    }
}
