<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\Console;
use Kabuto\Console\GeneratorCommand;

/**
 * Internal generator used by make:module to emit auxiliary files (DTOs, enums)
 * with an explicit stub and class name.
 */
final class GenericGeneratorCommand extends GeneratorCommand
{
    public function __construct(
        Console $console,
        array $arguments,
        private readonly string $stubName,
        private readonly string $explicitClass,
        string $module,
    ) {
        parent::__construct($console, $arguments);
        $this->module = $module;
        $this->className = $explicitClass;
    }

    public function name(): string
    {
        return 'make:generic';
    }

    public function description(): string
    {
        return 'Internal generator';
    }

    protected function parseName(): void
    {
        // Class name and module are provided explicitly.
    }

    protected function targetDirectory(): string
    {
        return str_contains($this->stubName, 'enum')
            ? 'app/Modules/' . $this->module . '/Types'
            : 'app/Modules/' . $this->module . '/Dto';
    }

    protected function stub(): string
    {
        return $this->stubName;
    }
}
