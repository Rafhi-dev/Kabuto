<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\Command;
use RuntimeException;

/**
 * Generates a random APP_KEY and writes it to the .env file.
 *
 *   php bin/console key:generate
 */
final class KeyGenerateCommand extends Command
{
    public function name(): string
    {
        return 'key:generate';
    }

    public function description(): string
    {
        return 'Generate APP_KEY and write it to .env';
    }

    public function handle(): int
    {
        if (!extension_loaded('openssl') && !function_exists('random_bytes')) {
            throw new RuntimeException('Cannot generate a key: openssl and random_bytes() are both unavailable.');
        }

        $key = 'base64:' . base64_encode(random_bytes(32));
        $path = rtrim($this->console->basePath(), '/\\') . DIRECTORY_SEPARATOR . '.env';

        if (!is_file($path)) {
            $example = rtrim($this->console->basePath(), '/\\') . DIRECTORY_SEPARATOR . '.env.example';

            if (!is_file($example)) {
                throw new RuntimeException('Neither .env nor .env.example exists.');
            }

            copy($example, $path);
            $this->info('Created .env from .env.example.');
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Unable to read .env.');
        }

        if (preg_match('/^APP_KEY=.*$/m', $contents)) {
            $contents = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $contents) ?? $contents;
        } else {
            $contents = rtrim($contents) . "\nAPP_KEY=" . $key . "\n";
        }

        file_put_contents($path, $contents);
        $this->success('APP_KEY generated and written to .env.');

        return 0;
    }
}
