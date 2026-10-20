<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\Command;
use RuntimeException;

/**
 * Completes the local setup of an existing Kabuto project.
 */
final class InstallCommand extends Command
{
    public function name(): string
    {
        return 'install';
    }

    public function description(): string
    {
        return 'Prepare environment, storage, and database directories';
    }

    public function handle(): int
    {
        $base = rtrim($this->console->basePath(), '/\\');
        $env = $base . '/.env';
        $example = $base . '/.env.example';

        if (!is_file($env)) {
            if (!is_file($example)) {
                throw new RuntimeException('Cannot install: .env.example does not exist.');
            }
            if (!copy($example, $env)) {
                throw new RuntimeException('Cannot create .env from .env.example.');
            }
            $this->info('Created .env from .env.example.');
        }

        $this->ensureKey($env);

        foreach (['storage', 'storage/app', 'storage/app/public', 'storage/app/private', 'storage/logs', 'database/migrations', 'public', 'app/Modules'] as $directory) {
            $path = $base . '/' . $directory;
            if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
                throw new RuntimeException('Cannot create directory: ' . $path);
            }
        }

        $this->success('Kabuto project installed.');
        $this->line('Next steps:');
        $this->line('  php kabuto doctor');
        $this->line('  php kabuto serve');

        return 0;
    }

    private function ensureKey(string $path): void
    {
        $contents = file_get_contents($path);
        if ($contents === false) throw new RuntimeException('Cannot read .env.');
        if (preg_match('/^APP_KEY\s*=\s*(\S*)/m', $contents, $matches) === 1 && trim($matches[1]) !== '') return;

        $key = 'base64:' . base64_encode(random_bytes(32));
        if (preg_match('/^APP_KEY\s*=.*$/m', $contents) === 1) {
            $contents = preg_replace('/^APP_KEY\s*=.*$/m', 'APP_KEY=' . $key, $contents) ?? $contents;
        } else {
            $contents = rtrim($contents) . PHP_EOL . 'APP_KEY=' . $key . PHP_EOL;
        }
        if (file_put_contents($path, $contents) === false) throw new RuntimeException('Cannot write APP_KEY to .env.');
        $this->info('Generated APP_KEY.');
    }
}
