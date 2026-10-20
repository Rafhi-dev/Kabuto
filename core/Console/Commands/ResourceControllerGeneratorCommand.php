<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\GeneratorCommand;

/**
 * Generates the resource controller variant that updates through an
 * Update DTO (used by make:resource).
 */
final class ResourceControllerGeneratorCommand extends GeneratorCommand
{
    public function name(): string
    {
        return 'make:resource-controller';
    }

    public function description(): string
    {
        return 'Create a resource controller with an Update DTO';
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
        return 'controller.resource';
    }
}
