<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\Command;
use Kabuto\Console\GeneratorCommand;
use RuntimeException;

/**
 * Scaffolds a fresh Kabuto project skeleton in a target directory.
 */
final class NewProjectCommand extends Command
{
    public function name(): string
    {
        return 'new';
    }

    public function description(): string
    {
        return 'Create a new Kabuto project skeleton (--starter=api|web|minimal)';
    }

    public function handle(): int
    {
        $name = $this->argument(0);

        if ($name === null || trim($name) === '') {
            throw new RuntimeException('Missing project name. Usage: php bin/console new <name>');
        }

        $target = rtrim($this->console->basePath(), '/\\') . '/' . GeneratorCommand::studly($name);

        if (is_dir($target) && !$this->flag('force')) {
            $this->error(sprintf('Directory already exists: %s (use --force to merge)', $target));
            return 1;
        }

        $starter = strtolower((string) ($this->option('starter', 'api') ?? 'api'));
        if (!in_array($starter, ['api', 'web', 'minimal'], true)) {
            throw new RuntimeException('Unknown starter [' . $starter . ']. Choose api, web, or minimal.');
        }

        foreach ($this->skeleton($starter) as $relative => $contents) {
            $path = $target . '/' . $relative;
            $directory = dirname($path);

            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            if (is_file($path) && !$this->flag('force')) {
                continue;
            }

            file_put_contents($path, $contents);
            $this->success(sprintf('Created %s/%s', GeneratorCommand::studly($name), $relative));
        }

        $this->line('');
        $this->info('Next steps:');
        $this->info(sprintf('  cd %s', GeneratorCommand::studly($name)));
        $this->info('  composer install');
        $this->info('  composer run serve');

        return 0;
    }

    /**
     * @return array<string,string>
     */
    private function skeleton(string $starter = 'api'): array
    {
        $composer = json_encode([
            'name' => 'app/app',
            'description' => 'A Kabuto ' . $starter . ' application.',
            'type' => 'project',
            'license' => 'MIT',
            'require' => ['php' => '>=8.2', 'kabuto/framework' => '^1.0'],
            'minimum-stability' => 'stable',
            'prefer-stable' => true,
            'autoload' => [
                'psr-4' => ['App\\' => 'app/'],
                'files' => ['vendor/kabuto/framework/core/Support/helpers.php'],
            ],
            'scripts' => [
                'serve' => 'php kabuto serve',
                'test' => 'php kabuto test',
                'install' => 'php kabuto install',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $kabuto = <<<'PHP'
            #!/usr/bin/env php
            <?php

            declare(strict_types=1);

            $basePath = __DIR__;
            $autoload = $basePath . '/vendor/autoload.php';

            if (is_file($autoload)) {
                require $autoload;
            }

            Kabuto\Support\Dotenv::load($basePath);

            use Kabuto\Application;
            use Kabuto\Console\Console;
            use Kabuto\Console\Commands\DbPullCommand;
            use Kabuto\Console\Commands\DbPushCommand;
            use Kabuto\Console\Commands\DbSeedCommand;
            use Kabuto\Console\Commands\DbTablesCommand;
            use Kabuto\Console\Commands\KeyGenerateCommand;
            use Kabuto\Console\Commands\InstallCommand;
            use Kabuto\Console\Commands\ContainerCheckCommand;
            use Kabuto\Console\Commands\MakeEventCommand;
            use Kabuto\Console\Commands\MakeListenerCommand;
            use Kabuto\Console\Commands\QueueFailedCommand;
            use Kabuto\Console\Commands\QueueRetryCommand;
            use Kabuto\Console\Commands\QueueWorkCommand;
            use Kabuto\Console\Commands\MakeControllerCommand;
            use Kabuto\Console\Commands\MakeDtoCommand;
            use Kabuto\Console\Commands\MakeMiddlewareCommand;
            use Kabuto\Console\Commands\MakeModuleCommand;
            use Kabuto\Console\Commands\MakeModuleFromDbCommand;
            use Kabuto\Console\Commands\MakeModulesFromDbCommand;
            use Kabuto\Console\Commands\MakeRepositoryCommand;
            use Kabuto\Console\Commands\MakeResourceCommand;
            use Kabuto\Console\Commands\MakeServiceCommand;
            use Kabuto\Console\Commands\RouteListCommand;
            use Kabuto\Console\Commands\ScheduleRunCommand;
            use Kabuto\Console\Commands\ScheduleListCommand;
            use Kabuto\Console\Commands\ScheduleWorkCommand;
            use Kabuto\Console\Commands\ServeCommand;
            use Kabuto\Console\Commands\TestCommand;

            $app = new Application(basePath: $basePath);

            try {
                $app->boot();
                $bootError = null;
            } catch (Throwable $bootError) {
            }

            $console = new Console($basePath, $app, $bootError ?? null);

            $console
                ->add(ServeCommand::class, ['serve'])
                ->add(TestCommand::class, ['test', 't'])
                ->add(MakeModuleCommand::class, ['module'])
                ->add(MakeResourceCommand::class, ['resource'])
                ->add(MakeControllerCommand::class, ['controller'])
                ->add(MakeServiceCommand::class, ['service'])
                ->add(MakeRepositoryCommand::class, ['repository'])
                ->add(MakeDtoCommand::class, ['dto'])
                ->add(MakeMiddlewareCommand::class, ['middleware'])
                ->add(MakeModuleFromDbCommand::class, ['module-from-db'])
                ->add(MakeModulesFromDbCommand::class, ['modules-from-db'])
                ->add(DbPushCommand::class, ['push'])
                ->add(DbPullCommand::class, ['pull'])
                ->add(DbTablesCommand::class, ['tables'])
                ->add(DbSeedCommand::class, ['seed'])
                ->add(KeyGenerateCommand::class, ['key'])
                ->add(InstallCommand::class)
                ->add(ContainerCheckCommand::class)
                ->add(MakeEventCommand::class)
                ->add(MakeListenerCommand::class)
                ->add(QueueWorkCommand::class)
                ->add(QueueFailedCommand::class)
                ->add(QueueRetryCommand::class)
                ->add(ScheduleRunCommand::class)
                ->add(ScheduleListCommand::class)
                ->add(ScheduleWorkCommand::class)
                ->add(RouteListCommand::class, ['routes']);

            exit($console->run($argv));

            PHP;

        return [
            'composer.json' => $composer . "\n",
            'README.md' => "# Kabuto Application\n\n## Getting started\n\n```bash\ncomposer install\nphp kabuto install\nphp kabuto doctor\nphp kabuto serve\n```\n\n## CLI\n\nCreate a project with `php kabuto new Shop --starter=api`, `--starter=web`, or `--starter=minimal`.\nUse `php kabuto route:list --verbose` to inspect route middleware.\n",
            'storage/.gitkeep' => '',
            '.gitignore' => "/vendor/\ncomposer.lock\n.env\n",
            'storage/app/.gitkeep' => '',
            'storage/app/public/.gitkeep' => '',
            'storage/app/private/.gitkeep' => '',
            'storage/logs/.gitkeep' => '',
            'database/migrations/.gitkeep' => '',
            'config/app.php' => "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'name' => env('APP_NAME', 'App'),\n    'env' => env('APP_ENV', 'local'),\n    'debug' => filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN),\n    'timezone' => env('APP_TIMEZONE', 'UTC'),\n];\n",
            'config/modules.php' => "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'modules' => [App\\AppModule::class],\n    'providers' => [],\n    'routes' => dirname(__DIR__) . '/app/Modules',\n    'namespace' => 'App\\\\Modules',\n    'discover' => true,\n];\n",
            'config/mail.php' => "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'driver' => env('MAIL_DRIVER', 'array'),\n    'from' => env('MAIL_FROM_ADDRESS', ''),\n    'host' => env('MAIL_HOST', '127.0.0.1'),\n    'port' => (int) env('MAIL_PORT', 587),\n    'username' => env('MAIL_USERNAME', null),\n    'password' => env('MAIL_PASSWORD', null),\n    'tls' => filter_var(env('MAIL_TLS', true), FILTER_VALIDATE_BOOLEAN),\n    'allow_insecure_auth' => filter_var(env('MAIL_ALLOW_INSECURE_AUTH', false), FILTER_VALIDATE_BOOLEAN),\n    'ehlo_name' => env('MAIL_EHLO_NAME', 'kabuto.local'),\n    'retries' => (int) env('MAIL_RETRIES', 2),\n    'timeout' => (float) env('MAIL_TIMEOUT', 10),\n];\n",
            'config/redis.php' => "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'host' => env('REDIS_HOST', '127.0.0.1'),\n    'port' => (int) env('REDIS_PORT', 6379),\n    'password' => env('REDIS_PASSWORD', null),\n    'database' => (int) env('REDIS_DB', 0),\n    'timeout' => (float) env('REDIS_TIMEOUT', 2),\n    'retries' => (int) env('REDIS_RETRIES', 1),\n    'tls' => filter_var(env('REDIS_TLS', false), FILTER_VALIDATE_BOOLEAN),\n    'persistent' => filter_var(env('REDIS_PERSISTENT', false), FILTER_VALIDATE_BOOLEAN),\n    'prefix' => env('REDIS_PREFIX', 'kabuto:'),\n];\n",
            'config/queue.php' => "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'driver' => env('QUEUE_CONNECTION', 'database'),\n    'queue' => env('QUEUE_NAME', 'default'),\n];\n",
            'config/session.php' => "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'driver' => env('SESSION_DRIVER', 'file'),\n    'cookie' => env('SESSION_COOKIE', 'kabuto_session'),\n    'lifetime' => (int) env('SESSION_LIFETIME', 7200),\n    'secure' => filter_var(env('SESSION_SECURE', false), FILTER_VALIDATE_BOOLEAN),\n];\n",
            'config/uploads.php' => "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'max_files' => (int) env('UPLOAD_MAX_FILES', 10),\n    'max_total_size' => (int) env('UPLOAD_MAX_TOTAL_SIZE', 20971520),\n    'max_file_size' => (int) env('UPLOAD_MAX_FILE_SIZE', 10485760),\n    'scan_driver' => env('UPLOAD_SCAN_DRIVER', 'none'),\n    'clamav_socket' => env('CLAMAV_SOCKET', '/var/run/clamav/clamd.sock'),\n    'quarantine_path' => env('UPLOAD_QUARANTINE_PATH', base_path('storage/quarantine')),\n    'sanitize_images' => filter_var(env('UPLOAD_SANITIZE_IMAGES', false), FILTER_VALIDATE_BOOLEAN),\n];\n",
            'config/storage.php' => "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'uploads' => [\n        'max_files' => (int) env('UPLOAD_MAX_FILES', 10),\n        'max_total_size' => (int) env('UPLOAD_MAX_TOTAL_SIZE', 20971520),\n        'max_file_size' => (int) env('UPLOAD_MAX_FILE_SIZE', 10485760),\n        'scan_driver' => env('UPLOAD_SCAN_DRIVER', 'none'),\n        'clamav_socket' => env('CLAMAV_SOCKET', '/var/run/clamav/clamd.sock'),\n        'quarantine_path' => env('UPLOAD_QUARANTINE_PATH', base_path('storage/quarantine')),\n        'sanitize_images' => filter_var(env('UPLOAD_SANITIZE_IMAGES', false), FILTER_VALIDATE_BOOLEAN),\n    ],\n    's3' => [\n        'bucket' => env('S3_BUCKET', ''),\n        'endpoint' => env('S3_ENDPOINT', 'https://s3.amazonaws.com'),\n        'region' => env('S3_REGION', 'us-east-1'),\n        'access_key' => env('S3_ACCESS_KEY', ''),\n        'secret_key' => env('S3_SECRET_KEY', ''),\n        'session_token' => env('S3_SESSION_TOKEN', null),\n        'path_style' => filter_var(env('S3_PATH_STYLE', true), FILTER_VALIDATE_BOOLEAN),\n        'public' => filter_var(env('S3_PUBLIC', false), FILTER_VALIDATE_BOOLEAN),\n        'timeout' => (float) env('S3_TIMEOUT', 15),\n        'retries' => (int) env('S3_RETRIES', 2),\n        'max_object_size' => (int) env('S3_MAX_OBJECT_SIZE', 5000000000000),\n        'allowed_content_types' => array_values(array_filter(array_map('trim', explode(',', (string) env('S3_ALLOWED_CONTENT_TYPES', ''))))),\n    ],\n];\n",
            'config/notifications.php' => "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'driver' => env('NOTIFICATIONS_DRIVER', 'array'),\n    'attempts' => (int) env('NOTIFICATIONS_ATTEMPTS', 1),\n    'retry_delay_ms' => (int) env('NOTIFICATIONS_RETRY_DELAY_MS', 100),\n    'store' => env('NOTIFICATIONS_STORE', 'memory'),\n];\n",
            'config/schedule.php' => "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'tasks' => [],\n    'lock_store' => env('SCHEDULE_LOCK_STORE', 'memory'), // memory, database, redis\n    'history_store' => env('SCHEDULE_HISTORY_STORE', 'memory'), // memory, database, none\n    'shutdown_grace_seconds' => (int) env('SCHEDULE_SHUTDOWN_GRACE', 5),\n    'maintenance_file' => base_path('storage/framework/down'),\n];\n",
            'config/realtime.php' => "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'driver' => env('REALTIME_DRIVER', 'array'),\n    'replay_capacity' => (int) env('REALTIME_REPLAY_CAPACITY', 100),\n    'heartbeat_seconds' => (int) env('REALTIME_HEARTBEAT_SECONDS', 15),\n    'idle_timeout_seconds' => (int) env('REALTIME_IDLE_TIMEOUT_SECONDS', 0),\n    'max_duration_seconds' => (int) env('REALTIME_MAX_DURATION_SECONDS', 0),\n    'poll_interval_ms' => (int) env('REALTIME_POLL_INTERVAL_MS', 200),\n    'fanout_redis' => filter_var(env('REALTIME_FANOUT_REDIS', false), FILTER_VALIDATE_BOOLEAN),\n    'gateways' => [\n        'path' => base_path('app/Gateways'),\n        'namespace' => env('REALTIME_GATEWAY_NAMESPACE', 'App\\\\Gateways'),\n    ],\n];\n",
            'config/observability.php' => "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'http' => [\n        'enabled' => filter_var(env('OBSERVABILITY_HTTP', true), FILTER_VALIDATE_BOOLEAN),\n    ],\n    'metrics' => [\n        'enabled' => filter_var(env('OBSERVABILITY_METRICS', true), FILTER_VALIDATE_BOOLEAN),\n    ],\n];\n",
             'config/auth.php' => "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'jwt' => [\n        'secret' => env('JWT_SECRET', env('APP_KEY', '')),\n        'access_ttl' => (int) env('JWT_ACCESS_TTL', 900),\n        'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 2592000),\n        'issuer' => env('JWT_ISSUER', null),\n        'audience' => env('JWT_AUDIENCE', null),\n        'leeway' => (int) env('JWT_LEEWAY', 0),\n        'max_access_ttl' => (int) env('JWT_MAX_ACCESS_TTL', 900),\n        'max_refresh_ttl' => (int) env('JWT_MAX_REFRESH_TTL', 2592000),\n        'revocation' => env('JWT_REVOCATION', 'memory'),\n        'revocation_path' => env('JWT_REVOCATION_PATH', base_path('storage/framework/jwt-revocations.json')),\n    ],\n];\n",
            'public/index.php' => "<?php\n\ndeclare(strict_types=1);\n\nrequire __DIR__ . '/../vendor/autoload.php';\n\nKabuto\\Support\\Dotenv::load(dirname(__DIR__));\n\nuse Kabuto\\Application;\nuse Kabuto\\Http\\Request;\nuse Kabuto\\Middleware\\Cors;\nuse Kabuto\\Middleware\\JsonBody;\nuse Kabuto\\Middleware\\SecurityHeaders;\n\n\$app = new Application(basePath: dirname(__DIR__));\n\n\$app->use(SecurityHeaders::class);\n\$app->use(Cors::class);\n\$app->use(JsonBody::class);\n\n\$app->boot();\n\n\$app->handle(Request::fromGlobals())->emit();\n",
            'kabuto' => $kabuto,
            '.env' => "APP_NAME=App\nAPP_ENV=local\nAPP_DEBUG=false\nAPP_TIMEZONE=UTC\nAPP_KEY=\nJWT_SECRET=\n\nDB_CONNECTION=mysql\nDB_DRIVER=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=app\nDB_USERNAME=root\nDB_PASSWORD=\n",
            '.env.example' => "# Copy to .env and fill in the blanks:  cp .env.example .env\n\nAPP_NAME=App\nAPP_ENV=local\nAPP_DEBUG=false\nAPP_TIMEZONE=UTC\nAPP_KEY=\nJWT_SECRET=\n\nDB_CONNECTION=mysql\nDB_DRIVER=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=app\nDB_USERNAME=root\nDB_PASSWORD=\n",
            'app/AppController.php' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nuse Kabuto\\Common\\Controller;\nuse Kabuto\\Common\\Get;\n\n#[Controller]\nfinal class AppController\n{\n    #[Get('/')]\n    public function index(): array\n    {\n        return [\n            'name' => config('app.name'),\n            'status' => 'ok',\n        ];\n    }\n}\n",
            'app/AppModule.php' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nuse Kabuto\\Common\\Module;\n\n#[Module(\n    controllers: [AppController::class],\n    imports: [\n        // Add feature modules here, e.g. UserModule::class,\n    ],\n)]\nfinal class AppModule\n{\n}\n",
            'app/Modules/.gitkeep' => '',
            'app/Gateways/.gitkeep' => '',
            'database/schema.php' => $starter === 'minimal' ? "<?php\n\ndeclare(strict_types=1);\n\nuse Kabuto\\Database\\Schema;\n\nreturn Schema::define(['datasources' => ['db' => ['driver' => 'sqlite', 'path' => base_path('database/app.sqlite')]]]);\n" : "<?php\n\ndeclare(strict_types=1);\n\nuse Kabuto\\Database\\Schema;\n\nreturn Schema::define([\n    'datasources' => [\n        'db' => [\n            'driver' => 'sqlite',\n            'path' => base_path('database/app.sqlite'),\n            'schemas' => ['base'],\n        ],\n    ],\n    'models' => [\n        'User' => [\n            'table' => 'users',\n            'schema' => 'base',\n            'fields' => [\n                'id' => 'Int @id @default(autoincrement())',\n                'name' => 'String(100)',\n                'email' => 'String(150) @unique',\n                'created_at' => 'DateTime @default(now())',\n            ],\n        ],\n    ],\n]);\n",
        ];
    }
}
