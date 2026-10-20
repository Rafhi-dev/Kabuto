<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\GeneratorCommand;

/**
 * Generates the XxxModule.php file for a module.
 */
final class ModuleFileGeneratorCommand extends GeneratorCommand
{
    public function name(): string
    {
        return 'make:module-file';
    }

    public function description(): string
    {
        return 'Create a module file for a module';
    }

    protected function targetDirectory(): string
    {
        return 'app/Modules/' . $this->module;
    }

    protected function suffix(): string
    {
        return 'Module';
    }

    protected function stub(): string
    {
        return 'module';
    }
}
