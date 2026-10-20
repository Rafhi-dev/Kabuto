<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\Command;
use Kabuto\Runtime\WorkerServer;

/**
 * Start the Kabuto high-performance persistent worker HTTP server.
 *
 * Usage:
 *   php kabuto serve
 *   php kabuto serve --workers=auto
 *   php kabuto serve --workers=auto*2
 *   php kabuto serve --workers=4 --driver=revolt
 *   php kabuto serve --workers=4 --max-requests=100000
 *   php kabuto serve --port=8080 --host=0.0.0.0
 *   php kabuto serve --traditional
 */
final class ServeCommand extends Command
{
    public function name(): string
    {
        return 'serve';
    }

    public function description(): string
    {
        return 'Start the Kabuto high-performance persistent worker HTTP server';
    }

    public function handle(): int
    {
        $host = (string) $this->option('host', '127.0.0.1');
        $port = (int) $this->option('port', '8000');
        $workersOption = $this->option('workers');
        $envWorkers = env('SERVER_WORKERS');
        $rawWorkers = $workersOption ?? $envWorkers ?? 'auto';
        $workers = WorkerServer::resolveWorkers($rawWorkers);

        $envMax = env('SERVER_MAX_REQUESTS');
        $defaultMax = is_numeric($envMax) ? (int) $envMax : 100000;
        $maxRequests = (int) $this->option('max-requests', (string) $defaultMax);

        $driverOption = $this->option('driver');
        $envDriver = env('SERVER_RUNTIME');
        $driver = (string) ($driverOption ?? $envDriver ?? 'auto');

        $isInternalWorker = $this->flag('internal-worker');

        if ($this->flag('traditional')) {
            $this->info(sprintf('Starting PHP built-in server on http://%s:%d...', $host, $port));
            $publicDir = escapeshellarg($this->console->basePath() . '/public');
            $indexFile = escapeshellarg($this->console->basePath() . '/public/index.php');
            passthru(sprintf('php -S %s:%d -t %s %s', $host, $port, $publicDir, $indexFile));
            return 0;
        }

        $app = $this->console->app();
        $publicDir = $this->console->basePath() . '/public';

        $app->use(\Kabuto\Middleware\Cors::class);
        $app->use(\Kabuto\Middleware\RequestId::class);
        $app->use(\Kabuto\Middleware\JsonBody::class);

        $routesFile = $this->console->basePath() . '/routes/api.php';
        if (is_file($routesFile)) {
            (static function (string $__file, \Kabuto\Application $app): void {
                $router = $app->router();
                require $__file;
            })($routesFile, $app);
        }

        $server = new WorkerServer(
            $app,
            $host,
            $port,
            $isInternalWorker ? 1 : $workers,
            $maxRequests,
            $publicDir,
            $driver,
            $isInternalWorker,
        );

        $server->start(function (string $address) use ($host, $port, $workers, $maxRequests, $server, $isInternalWorker): void {
            if ($isInternalWorker) {
                return;
            }

            $cores = WorkerServer::detectCpuCores();
            $driverName = $server->getDriverName();

            if ($workers > 1) {
                if (function_exists('pcntl_fork')) {
                    $workerLabel = sprintf('%d workers (Unix pcntl cluster + %s) — %d CPU cores detected', $workers, $driverName, $cores);
                } else {
                    $workerLabel = sprintf('%d workers (Windows proc_open cluster + %s) — %d CPU cores detected', $workers, $driverName, $cores);
                }
            } else {
                $workerLabel = sprintf('1 worker (%s) — %d CPU cores detected', $driverName, $cores);
            }

            $maxRequestsLabel = $maxRequests > 0
                ? sprintf('%s (Auto memory recycling)', number_format($maxRequests))
                : 'Unlimited (No recycle limit)';

            $this->line();
            $this->line("\033[1;36m  _  __     _           _        \033[0m");
            $this->line("\033[1;36m | |/ /    | |         | |       \033[0m");
            $this->line("\033[1;36m | ' / __ _| |__  _   _| |_ ___  \033[0m");
            $this->line("\033[1;36m |  < / _` | '_ \\| | | | __/ _ \\ \033[0m");
            $this->line("\033[1;36m | . \\ (_| | |_) | |_| | || (_) |\033[0m");
            $this->line("\033[1;36m |_|\\_\\__,_|_.__/ \\__,_|\\__\\___/ \033[0m");
            $this->line();
            $this->line("  \033[1;32m⚡ Kabuto Native Worker Runtime Engine (Persistent In-Memory)\033[0m");
            $this->line("  ─────────────────────────────────────────────────────────────");
            $this->line(sprintf("  \033[1m• Server URL  :\033[0m \033[4;34mhttp://%s:%d\033[0m", $host, $port));
            $this->line(sprintf("  \033[1m• Runtime Mode:\033[0m %s", $workerLabel));
            $this->line(sprintf("  \033[1m• Max Requests:\033[0m %s", $maxRequestsLabel));
            $this->line(sprintf("  \033[1m• Memory Res. :\033[0m %.2f MB", memory_get_usage(true) / 1024 / 1024));
            $this->line("  ─────────────────────────────────────────────────────────────");
            $this->line("  Press \033[1mCtrl+C\033[0m to stop the server.");
            $this->line();
        });

        return 0;
    }
}
