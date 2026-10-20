<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\Command;
use Kabuto\Console\GeneratorCommand;
use RuntimeException;

final class MakeListenerCommand extends Command
{
    public function name(): string { return 'make:listener'; }
    public function description(): string { return 'Create an event listener class'; }
    public function handle(): int
    {
        $name = $this->argument(0);
        if ($name === null || trim($name) === '') throw new RuntimeException('Usage: php kabuto make:listener <Name>');
        $class = GeneratorCommand::studly($name) . 'Listener';
        $path = $this->console->basePath() . '/app/Listeners/' . $class . '.php';
        if (is_file($path) && !$this->flag('force')) { $this->error('Already exists: app/Listeners/' . $class . '.php'); return 1; }
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
        file_put_contents($path, "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Listeners;\n\nuse Kabuto\\Events\\Event;\nuse Kabuto\\Events\\Listener;\n\nfinal class {$class} implements Listener\n{\n    public function handle(Event \$event): void\n    {\n    }\n}\n");
        $this->success('Created app/Listeners/' . $class . '.php');
        return 0;
    }
}
