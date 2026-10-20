<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\Command;
use Kabuto\Container\Container;
use Kabuto\Routing\Route;

final class ContainerCheckCommand extends Command
{
    public function name(): string { return 'container:check'; }

    public function description(): string { return 'Diagnose dependency injection bindings and circular dependencies'; }

    public function handle(): int
    {
        $ids = array_values(array_filter(array_map(static fn (string $arg): ?string => str_starts_with($arg, '--') ? null : $arg, $this->arguments)));
        if ($ids === []) $ids = $this->routeDependencies();
        if ($ids === []) $ids = $this->console->app()->container()->bindingIds();
        if ($ids === []) {
            $this->info('No container targets found. Pass a class name, for example: php kabuto container:check App\\Services\\UserService');
            return 0;
        }

        $errors = $this->console->app()->container()->diagnose($ids);
        $this->line('Kabuto Container Check');
        $this->line('Targets: ' . implode(', ', $ids));
        if ($errors === []) {
            $this->success('No dependency injection problems found.');
            return 0;
        }
        foreach ($errors as $error) $this->error('✗ ' . $error);
        $this->error(sprintf('Found %d dependency injection problem(s).', count($errors)));
        return 1;
    }

    /** @return list<string> */
    private function routeDependencies(): array
    {
        $ids = [];
        foreach ($this->console->app()->router()->routes() as $route) {
            $handler = $route->handler();
            if (is_string($handler)) {
                $ids[] = str_contains($handler, '@') ? explode('@', $handler, 2)[0] : $handler;
            } elseif (is_array($handler) && count($handler) === 2 && is_string($handler[0])) {
                $ids[] = $handler[0];
            }
            foreach ($route->middleware() as $middleware) if (is_string($middleware)) $ids[] = $middleware;
        }
        return array_values(array_unique($ids));
    }
}
