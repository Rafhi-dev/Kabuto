<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\GeneratorCommand;

final class MakeDtoCommand extends GeneratorCommand
{
    public function name(): string
    {
        return 'make:dto';
    }

    public function description(): string
    {
        return 'Create a DTO inside a module';
    }

    protected function targetDirectory(): string
    {
        return 'app/Modules/' . $this->module . '/Dto';
    }

    protected function suffix(): string
    {
        return 'Dto';
    }

    protected function stub(): string
    {
        return 'dto';
    }
}
