<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\Command;
use Kabuto\Console\GeneratorCommand;
use RuntimeException;

final class MakeEventCommand extends Command
{
    public function name(): string { return 'make:event'; }
    public function description(): string { return 'Create an event class'; }
    public function handle(): int
    {
        $name = $this->argument(0);
        if ($name === null || trim($name) === '') throw new RuntimeException('Usage: php kabuto make:event <Name>');
        $class = GeneratorCommand::studly($name);
        $path = $this->console->basePath() . '/app/Events/' . $class . '.php';
        if (is_file($path) && !$this->flag('force')) { $this->error('Already exists: app/Events/' . $class . '.php'); return 1; }
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
        file_put_contents($path, "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Events;\n\nuse Kabuto\\Events\\Event;\n\nfinal readonly class {$class} implements Event\n{\n}\n");
        $this->success('Created app/Events/' . $class . '.php');
        return 0;
    }
}
