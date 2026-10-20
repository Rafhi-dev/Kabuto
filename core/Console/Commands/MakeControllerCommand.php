<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\GeneratorCommand;

final class MakeControllerCommand extends GeneratorCommand
{
    public function name(): string
    {
        return 'make:controller';
    }

    public function description(): string
    {
        return 'Create a controller for a module';
    }

    protected function targetDirectory(): string
    {
        return 'app/Modules/' . $this->module;
    }

    protected function suffix(): string
    {
        return 'Controller';
    }

    protected function stub(): string
    {
        return 'controller';
    }
}
