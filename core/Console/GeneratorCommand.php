<?php

declare(strict_types=1);

namespace Kabuto\Console;

use RuntimeException;

/**
 * Base class for "make:*" generator commands. Handles name parsing, target
 * path resolution, stub rendering and safe file writing.
 */
abstract class GeneratorCommand extends Command
{
    /**
     * Module name, e.g. "User". Empty for project-level generation.
     */
    protected string $module = '';

    /**
     * The primary class name, e.g. "UserController".
     */
    protected string $className = '';

    /**
     * Relative directory (under app/) where files are written.
     */
    abstract protected function targetDirectory(): string;

    /**
     * Stub file name, without extension, under core/Console/Stubs.
     */
    abstract protected function stub(): string;

    /**
     * Resolve the module + class name from the command argument.
     *
     * Accepted forms:
     *   "User"              -> module User, class User{Suffix}
     *   "User/CreateUser"   -> module User, class CreateUser{Suffix}
     *   "User\CreateUser"   -> same as above
     */
    protected function parseName(): void
    {
        $name = $this->argument(0);

        if ($name === null || trim($name) === '') {
            throw new RuntimeException(sprintf('Missing name. Usage: php kabuto %s <Name>', $this->name()));
        }

        $segments = preg_split('#[\\\\/]#', trim($name)) ?: [];
        $segments = array_values(array_filter($segments, static fn (string $s): bool => trim($s) !== ''));

        if ($segments === []) {
            throw new RuntimeException(sprintf('Missing name. Usage: php kabuto %s <Name>', $this->name()));
        }

        if (count($segments) === 1) {
            $this->module = self::studly($segments[0]);
            $this->className = $this->module . $this->suffix();
            return;
        }

        $this->module = self::studly(array_shift($segments));
        $this->className = self::studly(implode(' ', $segments)) . $this->suffix();
    }

    /**
     * Class-name suffix, e.g. "Controller". Empty for plain classes.
     */
    protected function suffix(): string
    {
        return '';
    }

    /**
     * Generate the file for this command.
     */
    public function handle(): int
    {
        $this->parseName();

        $directory = $this->console->basePath() . '/' . trim($this->targetDirectory(), '/\\');
        $path = $directory . '/' . $this->className . '.php';

        if (is_file($path) && !$this->flag('force')) {
            $this->error(sprintf('Already exists: %s (use --force to overwrite)', $this->relative($path)));
            return 1;
        }

        $this->ensureDirectory($directory);

        file_put_contents($path, $this->render());
        $this->success(sprintf('Created %s', $this->relative($path)));

        return 0;
    }

    protected function render(): string
    {
        $stub = file_get_contents($this->stubPath());

        if ($stub === false) {
            throw new RuntimeException(sprintf('Missing stub: %s', $this->stubPath()));
        }

        $replacements = [
            '{{ namespace }}' => $this->namespace(),
            '{{ class }}' => $this->className,
            '{{ module }}' => $this->module,
            '{{ moduleVar }}' => lcfirst($this->module),
            '{{ modulePlural }}' => self::plural($this->module),
            '{{ modulePluralVar }}' => lcfirst(self::plural($this->module)),
            '{{ route }}' => self::kebab(self::plural($this->module)),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $stub);
    }

    protected function namespace(): string
    {
        $relative = str_replace('\\', '/', trim($this->targetDirectory(), '/\\'));
        $relative = preg_replace('#^app/#', '', $relative) ?? $relative;

        return 'App\\' . str_replace('/', '\\', $relative);
    }

    protected function stubPath(): string
    {
        return __DIR__ . '/Stubs/' . $this->stub() . '.stub';
    }

    protected function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create directory: %s', $directory));
        }
    }

    protected function relative(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', $this->console->basePath()), '/');
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $base) ? ltrim(substr($path, strlen($base)), '/') : $path;
    }

    public static function studly(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9]+/', ' ', $value) ?? '';
        return str_replace(' ', '', ucwords(trim($value)));
    }

    public static function kebab(string $value): string
    {
        $value = preg_replace('/(?<!^)[A-Z]/', '-$0', $value) ?? $value;
        return strtolower($value);
    }

    public static function plural(string $value): string
    {
        if (preg_match('/(s|x|z|ch|sh)$/i', $value)) {
            return $value . 'es';
        }
        if (preg_match('/[^aeiou]y$/i', $value)) {
            return substr($value, 0, -1) . 'ies';
        }
        return $value . 's';
    }
}
