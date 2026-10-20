<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\Command;
use Kabuto\Console\GeneratorCommand;
use RuntimeException;

/**
 * Scaffolds a complete NestJS-style module: module file, controller,
 * service, repository, create DTO and output DTO. No routes.php —
 * routes come from controller attributes.
 *
 *   php bin/console make:module Product
 */
final class MakeModuleCommand extends Command
{
    private const GENERATORS = [
        MakeRepositoryCommand::class,
        MakeServiceCommand::class,
        MakeControllerCommand::class,
    ];

    public function name(): string
    {
        return 'make:module';
    }

    public function description(): string
    {
        return 'Scaffold a full NestJS-style module (module, controller, service, repository, DTOs)';
    }

    public function handle(): int
    {
        $module = $this->argument(0);

        if ($module === null || trim($module) === '') {
            throw new RuntimeException('Missing module name. Usage: php bin/console make:module <Name>');
        }

        $module = GeneratorCommand::studly($module);
        $force = $this->flag('force') ? ['--force'] : [];
        $status = 0;

        foreach (self::GENERATORS as $generator) {
            $command = new $generator($this->console, array_merge([$module], $force));
            $status = max($status, $command->handle());
        }

        $status = max($status, $this->writeDTOs($module, $force));
        $status = max($status, $this->writeModule($module, $force));

        return $status;
    }

    /**
     * @param list<string> $force
     */
    private function writeDTOs(string $module, array $force): int
    {
        $status = 0;

        $status = max($status, $this->generate('dto', 'Create' . $module . 'Dto', $module, $force));
        $status = max($status, $this->generate('model-dto', $module . 'Dto', $module, $force));

        return $status;
    }

    /**
     * @param list<string> $force
     */
    private function writeModule(string $module, array $force): int
    {
        $command = new ModuleFileGeneratorCommand(
            $this->console,
            array_merge([$module], $force),
        );

        return $command->handle();
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
