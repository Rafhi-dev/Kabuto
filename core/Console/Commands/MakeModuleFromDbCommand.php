<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\Command;
use Kabuto\Console\GeneratorCommand;
use Kabuto\Database\FieldDefinition;
use Kabuto\Database\ModelDefinition;
use RuntimeException;

/**
 * Scaffolds a full ORM-backed module from a schema model:
 * module file, controller, service, DB repository, create DTO and output DTO.
 *
 *   php bin/console make:module-from-db User
 *   php bin/console make:module-from-db User --datasource=mysql --force
 */
final class MakeModuleFromDbCommand extends Command
{
    public function name(): string
    {
        return 'make:module-from-db';
    }

    public function description(): string
    {
        return 'Scaffold an ORM-backed module from a schema model';
    }

    public function handle(): int
    {
        $model = $this->argument(0);

        if ($model === null || trim($model) === '') {
            throw new RuntimeException('Missing model name. Usage: php bin/console make:module-from-db <Model>');
        }

        $module = GeneratorCommand::studly($model);
        $force = $this->flag('force') ? ['--force'] : [];

        $schema = DatabaseCommand::loadSchema($this->console);
        $definition = $schema->model($module);

        $status = 0;
        $status = max($status, $this->write('repository.db', $module . 'Repository', $module, $definition, $force));
        $status = max($status, $this->write('service.db', $module . 'Service', $module, $definition, $force));
        $status = max($status, $this->write('controller.db', $module . 'Controller', $module, $definition, $force));
        $status = max($status, $this->writeCreateDto($module, $definition, $force));
        $status = max($status, $this->writeOutputDto($module, $definition, $force));
        $status = max($status, $this->writeModule($module, $definition, $force));

        return $status;
    }

    /**
     * @param list<string> $force
     */
    private function write(string $stub, string $class, string $module, ModelDefinition $definition, array $force): int
    {
        $command = new DbGeneratorCommand(
            $this->console,
            array_merge([$module], $force),
            $stub,
            $class,
            $module,
            $definition,
        );

        return $command->handle();
    }

    /**
     * @param list<string> $force
     */
    private function writeCreateDto(string $module, ModelDefinition $definition, array $force): int
    {
        $command = new DbGeneratorCommand(
            $this->console,
            array_merge([$module], $force),
            'dto.db',
            'Create' . $module . 'Dto',
            $module,
            $definition,
            'app/Modules/' . $module . '/Dto',
        );

        return $command->handle();
    }

    /**
     * @param list<string> $force
     */
    private function writeOutputDto(string $module, ModelDefinition $definition, array $force): int
    {
        $command = new DbGeneratorCommand(
            $this->console,
            array_merge([$module], $force),
            'model.db',
            $module . 'Dto',
            $module,
            $definition,
            'app/Modules/' . $module . '/Dto',
        );

        return $command->handle();
    }

    /**
     * @param list<string> $force
     */
    private function writeModule(string $module, ModelDefinition $definition, array $force): int
    {
        $command = new DbGeneratorCommand(
            $this->console,
            array_merge([$module], $force),
            'module',
            $module . 'Module',
            $module,
            $definition,
        );

        return $command->handle();
    }
}
