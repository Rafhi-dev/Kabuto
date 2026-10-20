<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\Command;
use Kabuto\OpenApi\OpenApiGenerator;
use RuntimeException;

final class OpenApiGenerateCommand extends Command
{
    public function name(): string { return 'openapi:generate'; }
    public function description(): string { return 'Generate an OpenAPI 3 document from registered routes'; }

    public function handle(): int
    {
        $base = rtrim($this->console->basePath(), '/\\');
        $path = $this->option('output', $base . '/storage/openapi.json');
        $title = $this->option('title', (string) config('app.name', 'Kabuto API'));
        $version = $this->option('version', '1.0.0');
        $document = (new OpenApiGenerator($this->console->app()->router()))->generate($title ?? 'Kabuto API', $version ?? '1.0.0');
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) throw new RuntimeException('Cannot create output directory: ' . $directory);
        file_put_contents($path, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
        $this->success('OpenAPI document generated: ' . $path);
        return 0;
    }
}
