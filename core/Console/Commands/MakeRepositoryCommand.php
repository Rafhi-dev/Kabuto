<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\GeneratorCommand;

final class MakeRepositoryCommand extends GeneratorCommand
{
    public function name(): string
    {
        return 'make:repository';
    }

    public function description(): string
    {
        return 'Create a repository for a module';
    }

    protected function targetDirectory(): string
    {
        return 'app/Modules/' . $this->module;
    }

    protected function suffix(): string
    {
        return 'Repository';
    }

    protected function stub(): string
    {
        return 'repository';
    }
}
