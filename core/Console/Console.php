<?php

declare(strict_types=1);

namespace Kabuto\Console;

use Kabuto\Application;
use Throwable;

/**
 * Minimal console application: registers commands, parses argv and dispatches.
 */
final class Console
{
    /** @var array<string,class-string<Command>> */
    private array $commands = [];

    /** @var array<string,string> */
    private array $aliases = [];

    public function __construct(
        private readonly string $basePath,
        private readonly Application $app,
        private readonly ?Throwable $bootError = null,
    ) {
    }

    /**
     * @param class-string<Command> $command
     * @param list<string> $aliases
     */
    public function add(string $command, array $aliases = []): self
    {
        /** @var Command $instance */
        $instance = new $command($this);
        $this->commands[$instance->name()] = $command;

        foreach ($aliases as $alias) {
            $this->aliases[$alias] = $instance->name();
        }

        return $this;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function app(): Application
    {
        return $this->app;
    }

    public function writeln(string $message = ''): void
    {
        if ($this->quiet) return;
        echo $message . PHP_EOL;
    }

    private string $binary = 'letsgo';
    private bool $quiet = false;
    private bool $json = false;

    /**
     * @param list<string> $argv raw argv including the script name
     */
    public function run(array $argv): int
    {
        $script = $argv[0] ?? 'letsgo';
        $this->binary = basename($script) ?: 'letsgo';

        array_shift($argv);

        $this->quiet = in_array('--quiet', $argv, true) || in_array('-q', $argv, true);
        $this->json = in_array('--json', $argv, true);
        $argv = array_values(array_filter($argv, static fn (string $arg): bool => !in_array($arg, ['--quiet', '-q', '--json'], true)));

        $name = array_shift($argv) ?? 'list';
        $name = $this->aliases[$name] ?? $name;

        if ($name === 'list') {
            $this->listCommands();
            return 0;
        }

        if ($name === 'help') {
            $target = $argv[0] ?? null;
            $this->showHelp($target);
            return 0;
        }

        if (!isset($this->commands[$name])) {
            $this->writeln(sprintf('Unknown command "%s".', $name));
            $this->listCommands();
            return 1;
        }

        // Commands that work without a booted application (e.g. key:generate
        // on a fresh clone whose .env is still empty).
        if ($this->bootError !== null && !in_array($name, ['key:generate', 'doctor'], true)) {
            $this->writeln(sprintf('Error: %s', $this->bootError->getMessage()));
            return 1;
        }

        $commandClass = $this->commands[$name];
        $command = new $commandClass($this, $argv);

        try {
            return $command->handle();
        } catch (Throwable $e) {
            $this->writeln(sprintf('Error: %s', $e->getMessage()));
            return 1;
        }
    }

    private function listCommands(): void
    {
        if ($this->json) {
            $items = [];
            foreach ($this->commands as $name => $class) {
                $instance = new $class($this);
                $aliases = [];
                foreach ($this->aliases as $alias => $target) if ($target === $name) $aliases[] = $alias;
                $items[] = ['name' => $name, 'description' => $instance->description(), 'aliases' => $aliases];
            }
            usort($items, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);
            echo json_encode(['application' => 'Kabuto', 'binary' => $this->binary, 'commands' => $items], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
            return;
        }

        $this->writeln('Kabuto console');
        $this->writeln('');
        $this->writeln(sprintf('Usage: php %s <command> [arguments] [--flags]', $this->binary));
        $this->writeln('');
        $this->writeln('Available commands:');

        $names = array_keys($this->commands);
        sort($names);
        $width = max(array_map('strlen', $names ?: ['']));

        foreach ($names as $name) {
            /** @var Command $instance */
            $instance = new $this->commands[$name]($this);
            $this->writeln(sprintf('  %-' . ($width + 2) . 's %s', $name, $instance->description()));
        }
    }

    private function showHelp(?string $name): void
    {
        if ($name === null || !isset($this->commands[$name])) {
            $this->listCommands();
            return;
        }

        /** @var Command $instance */
        $instance = new $this->commands[$name]($this);
        if ($this->json) {
            echo json_encode(['name' => $instance->name(), 'description' => $instance->description()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
            return;
        }
        $this->writeln($instance->name());
        $this->writeln('');
        $this->writeln($instance->description());
    }
}
