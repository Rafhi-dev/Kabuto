<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\GeneratorCommand;

final class MakeMiddlewareCommand extends GeneratorCommand
{
    public function name(): string
    {
        return 'make:middleware';
    }

    public function description(): string
    {
        return 'Create a middleware class';
    }

    protected function targetDirectory(): string
    {
        return 'app/Middleware';
    }

    protected function suffix(): string
    {
        return '';
    }

    protected function stub(): string
    {
        return 'middleware';
    }
}
