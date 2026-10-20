<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\Command;
use Kabuto\Console\GeneratorCommand;
use RuntimeException;

/**
 * Scaffolds a full CRUD resource ala `nest g resource`: module file,
 * controller (with Update DTO), service, repository, create DTO,
 * update DTO and output DTO.
 *
 *   php bin/console make:resource Product
 */
final class MakeResourceCommand extends Command
{
    public function name(): string
    {
        return 'make:resource';
    }

    public function description(): string
    {
        return 'Scaffold a full CRUD resource (module, controller, service, repository, DTOs)';
    }

    public function handle(): int
    {
        $module = $this->argument(0);

        if ($module === null || trim($module) === '') {
            throw new RuntimeException('Missing resource name. Usage: php bin/console make:resource <Name>');
        }

        $module = GeneratorCommand::studly($module);
        $force = $this->flag('force') ? ['--force'] : [];
        $status = 0;

        $status = max($status, $this->run(MakeRepositoryCommand::class, $module, $force));
        $status = max($status, $this->run(MakeServiceCommand::class, $module, $force));
        $status = max($status, $this->runResourceController($module, $force));
        $status = max($status, $this->generate('dto', 'Create' . $module . 'Dto', $module, $force));
        $status = max($status, $this->generate('update-dto', 'Update' . $module . 'Dto', $module, $force));
        $status = max($status, $this->generate('model-dto', $module . 'Dto', $module, $force));
        $status = max($status, $this->run(ModuleFileGeneratorCommand::class, $module, $force));

        return $status;
    }

    /**
     * @param class-string<Command> $command
     * @param list<string> $force
     */
    private function run(string $command, string $module, array $force): int
    {
        return (new $command($this->console, array_merge([$module], $force)))->handle();
    }

    /**
     * @param list<string> $force
     */
    private function runResourceController(string $module, array $force): int
    {
        return (new ResourceControllerGeneratorCommand(
            $this->console,
            array_merge([$module], $force),
        ))->handle();
    }

    /**
     * @param list<string> $force
     */
    private function generate(string $stub, string $class, string $module, array $force): int
    {
        $command = new GenericGeneratorCommand(
            $this->console,
            array_merge([$module], $force),
            $stub,
            $class,
            $module,
        );

        return $command->handle();
    }
}
