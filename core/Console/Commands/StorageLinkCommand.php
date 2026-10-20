<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\Command;

final class StorageLinkCommand extends Command
{
    public function name(): string
    {
        return 'storage:link';
    }

    public function description(): string
    {
        return 'Create the symbolic link from public/storage to storage/app/public';
    }

    public function handle(): int
    {
        $base = $this->console->basePath();
        $target = $base . '/storage/app/public';
        $link = $base . '/public/storage';

        if (!is_dir($target)) {
            mkdir($target, 0755, true);
        }

        if (file_exists($link) || is_link($link)) {
            $this->warn('The [public/storage] link already exists.');
            return 0;
        }

        if (@symlink($target, $link)) {
            $this->success('The [public/storage] link has been connected to [storage/app/public].');
            return 0;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = sprintf('cmd /c mklink /J "%s" "%s"', str_replace('/', '\\', $link), str_replace('/', '\\', $target));
            exec($cmd, $output, $code);
            if ($code === 0) {
                $this->success('The [public/storage] junction has been connected to [storage/app/public].');
                return 0;
            }
        }

        $this->error('Failed to create symbolic link. Please verify filesystem permissions.');
        return 1;
    }
}
