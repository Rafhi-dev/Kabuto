<?php

declare(strict_types=1);

namespace Kabuto\Realtime;

use Kabuto\Common\WebSocketGateway;
use ReflectionClass;

/**
 * Discovers #[WebSocketGateway] classes under a directory and registers them
 * into a GatewayRegistry.
 */
final class GatewayScanner
{
    public function __construct(private readonly GatewayRegistry $registry)
    {
    }

    /**
     * @return list<class-string>
     */
    public function discover(string $directory, string $namespace = 'App\\Gateways'): array
    {
        $directory = rtrim($directory, '/\\');
        $namespace = trim($namespace, '\\');

        if (!is_dir($directory)) {
            return [];
        }

        $classes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            if (!str_ends_with($file->getBasename('.php'), 'Gateway')) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($directory) + 1);
            $relative = str_replace(['/', '\\'], '\\', $relative);
            $class = $namespace . '\\' . substr($relative, 0, -4);

            if (class_exists($class) && $this->isGateway($class)) {
                $this->registry->register($class);
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    /**
     * @param class-string $class
     */
    public function isGateway(string $class): bool
    {
        return (new ReflectionClass($class))->getAttributes(WebSocketGateway::class) !== [];
    }
}
