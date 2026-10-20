<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\GeneratorCommand;

final class MakeServiceCommand extends GeneratorCommand
{
    public function name(): string
    {
        return 'make:service';
    }

    public function description(): string
    {
        return 'Create a service for a module';
    }

    protected function targetDirectory(): string
    {
        return 'app/Modules/' . $this->module;
    }

    protected function suffix(): string
    {
        return 'Service';
    }

    protected function stub(): string
    {
        return 'service';
    }
}
