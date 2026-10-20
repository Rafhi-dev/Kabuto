<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\Command;
use Kabuto\Routing\Route;

final class RouteListCommand extends Command
{
    public function name(): string
    {
        return 'route:list';
    }

    public function description(): string
    {
        return 'List all registered routes (--verbose includes middleware)';
    }

    public function handle(): int
    {
        $routes = $this->console->app()->router()->routes();

        if ($routes === []) {
            $this->info('No routes registered.');
            return 0;
        }

        $verbose = $this->flag('verbose');
        $rows = array_map(
            static function (Route $route) use ($verbose): array {
                $row = [
                $route->method(),
                $route->pattern(),
                self::handlerLabel($route->handler()),
                self::sourceLabel($route->handler()),
                ];
                if ($verbose) {
                    $row[] = self::middlewareLabel($route->middleware());
                }
                return $row;
            },
            $routes,
        );

        $this->render($rows);

        return 0;
    }

    private static function handlerLabel(mixed $handler): string
    {
        if (is_string($handler)) {
            return $handler;
        }

        if (is_array($handler) && count($handler) === 2) {
            $target = is_string($handler[0]) ? $handler[0] : $handler[0]::class;
            return $target . '@' . $handler[1];
        }

        if ($handler instanceof \Closure) {
            return 'Closure';
        }

        return get_debug_type($handler);
    }

    private static function sourceLabel(mixed $handler): string
    {
        $class = null;

        if (is_string($handler) && str_contains($handler, '@')) {
            $class = explode('@', $handler, 2)[0];
        } elseif (is_array($handler) && count($handler) === 2) {
            $class = is_string($handler[0]) ? $handler[0] : $handler[0]::class;
        }

        if ($class !== null && class_exists($class)) {
            $attributes = (new \ReflectionClass($class))->getAttributes(\Kabuto\Common\Controller::class);

            if ($attributes !== []) {
                return 'module';
            }
        }

        return 'file';
    }

    /** @param list<callable|string> $middleware */
    private static function middlewareLabel(array $middleware): string
    {
        if ($middleware === []) return '-';
        return implode(', ', array_map(static function (mixed $layer): string {
            if (is_string($layer)) return $layer;
            if (is_array($layer) && count($layer) === 2) {
                $target = is_object($layer[0]) ? $layer[0]::class : (string) $layer[0];
                return $target . '@' . $layer[1];
            }
            return $layer instanceof \Closure ? 'Closure' : get_debug_type($layer);
        }, $middleware));
    }

    /**
     * @param list<array<int,string>> $rows
     */
    private function render(array $rows): void
    {
        $columns = count($rows[0] ?? []);
        $widths = array_fill(0, $columns, 0);
        foreach ($rows as $row) {
            foreach ($row as $i => $value) {
                $widths[$i] = max($widths[$i], strlen($value));
            }
        }

        $headers = ['METHOD', 'PATH', 'HANDLER', 'SOURCE'];
        if ($columns > 4) $headers[] = 'MIDDLEWARE';
        $this->line($this->formatRow($headers, $widths));

        foreach ($rows as $row) {
            $this->line($this->formatRow($row, $widths));
        }
    }

    /** @param list<string> $row @param list<int> $widths */
    private function formatRow(array $row, array $widths): string
    {
        $parts = [];
        foreach ($row as $index => $value) {
            $parts[] = str_pad($value, $widths[$index] ?? strlen($value));
        }
        return implode('  ', $parts);
    }
}
