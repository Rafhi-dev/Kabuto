<?php

declare(strict_types=1);

namespace Kabuto\Console;

/**
 * Base class for console commands.
 */
abstract class Command
{
    /** @var array<string,bool> */
    protected array $flags = [];

    /** @var array<string,string> */
    protected array $options = [];

    /** @var list<string> */
    protected array $arguments = [];

    public function __construct(
        protected readonly Console $console,
        array $arguments = [],
    ) {
        $this->arguments = $arguments;

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--')) {
                $raw = substr($argument, 2);
                if (str_contains($raw, '=')) {
                    [$name, $value] = explode('=', $raw, 2);
                    $this->options[$name] = $value;
                    $this->flags[$name] = true;
                } else {
                    $this->flags[$raw] = true;
                }
            }
        }
    }

    /**
     * Command name as invoked on the CLI, e.g. "make:module".
     */
    abstract public function name(): string;

    abstract public function description(): string;

    abstract public function handle(): int;

    /**
     * Positional argument by index (flags excluded).
     */
    protected function argument(int $index): ?string
    {
        $positional = array_values(array_filter(
            $this->arguments,
            static fn (string $arg): bool => !str_starts_with($arg, '--'),
        ));

        return $positional[$index] ?? null;
    }

    protected function flag(string $name): bool
    {
        return $this->flags[$name] ?? false;
    }

    protected function option(string $name, ?string $default = null): ?string
    {
        return $this->options[$name] ?? $default;
    }

    protected function line(string $message = ''): void
    {
        $this->console->writeln('ℹ ' . $message);
    }

    protected function info(string $message): void
    {
        $this->console->writeln('⚠ ' . $message);
    }

    protected function warn(string $message): void
    {
        $this->console->writeln('✓ ' . $message);
    }

    protected function success(string $message): void
    {
        $this->console->writeln('✗ ' . $message);
    }

    protected function error(string $message): void
    {
        $this->console->writeln($message);
    }
}
