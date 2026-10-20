<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Auth\JWT;
use Kabuto\Console\Command;
use Kabuto\Support\Dotenv;
use Kabuto\Redis\RedisConnectionFactory;
use Throwable;

final class DoctorCommand extends Command
{
    /** @var list<array{label:string,ok:bool,message:string}> */
    private array $checks = [];

    public function name(): string
    {
        return 'doctor';
    }

    public function description(): string
    {
        return 'Check PHP, environment, database, storage, and production configuration';
    }

    public function handle(): int
    {
        $base = rtrim($this->console->basePath(), '/\\');
        $this->checkPhp();
        $this->checkExtension('PDO', extension_loaded('pdo'));
        $this->checkExtension('OpenSSL', extension_loaded('openssl'));
        $this->checkExtension('JSON', extension_loaded('json'));
        $this->checkEnvironment($base);
        $this->checkStorage($base);
        $this->checkDatabase($base);
        $this->checkProductionConfig();
        $this->checkRedis($base);

        $this->line('Kabuto Doctor');
        $this->line('');
        foreach ($this->checks as $check) {
            $mark = $check['ok'] ? '✓' : '✗';
            $this->line(sprintf('%s %-24s %s', $mark, $check['label'], $check['message']));
        }

        $failed = count(array_filter($this->checks, static fn (array $check): bool => !$check['ok']));
        $this->line('');
        $this->line($failed === 0 ? 'Doctor found no problems.' : sprintf('Doctor found %d problem(s).', $failed));

        return $failed === 0 ? 0 : 1;
    }

    private function checkPhp(): void
    {
        $ok = version_compare(PHP_VERSION, '8.2.0', '>=');
        $this->add('PHP 8.2+', $ok, PHP_VERSION);
    }

    private function checkExtension(string $name, bool $ok): void
    {
        $this->add($name . ' extension', $ok, $ok ? 'available' : 'missing');
    }

    private function checkEnvironment(string $base): void
    {
        $envPath = $base . DIRECTORY_SEPARATOR . '.env';
        $exists = is_file($envPath);
        $this->add('.env file', $exists, $exists ? 'found' : 'missing; copy .env.example to .env');

        if ($exists) {
            try {
                $values = Dotenv::parse($envPath);
                $appKey = (string) ($values['APP_KEY'] ?? '');
                $this->add('APP_KEY', $this->validKey($appKey), $this->validKey($appKey) ? 'valid' : 'missing or must decode to 32 bytes');

                $jwt = (string) ($values['JWT_SECRET'] ?? $values['APP_KEY'] ?? '');
                $jwtValid = strlen($jwt) >= 32;
                $this->add('JWT_SECRET', $jwtValid, $jwtValid ? 'valid length' : 'missing or shorter than 32 bytes');
            } catch (Throwable $e) {
                $this->add('Environment parsing', false, $e->getMessage());
            }
        } else {
            $this->add('APP_KEY', false, 'cannot check without .env');
            $this->add('JWT_SECRET', false, 'cannot check without .env');
        }
    }

    private function checkStorage(string $base): void
    {
        $path = $base . '/storage';
        $exists = is_dir($path) || @mkdir($path, 0755, true);
        $writable = $exists && is_writable($path);
        $this->add('Storage directory', $writable, $writable ? $path . ' writable' : $path . ' missing or not writable');
    }

    private function checkDatabase(string $base): void
    {
        try {
            $env = is_file($base . '/.env') ? Dotenv::parse($base . '/.env') : [];
            $get = static function (string $key, mixed $default = null) use ($env): mixed {
                return $env[$key] ?? $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key) ?: $default;
            };
            $default = (string) $get('DB_CONNECTION', 'db');
            $driver = strtolower((string) $get('DB_DRIVER', 'mysql'));
            if ($driver === 'sqlite') {
                $path = (string) $get('DB_PATH', 'database/app.sqlite');
                if (!str_contains($path, '/') && !str_contains($path, '\\') && $path !== ':memory:') $path = $base . '/' . $path;
                if ($path !== ':memory:' && !str_contains($path, '://')) {
                    $directory = dirname($path);
                    if (!is_dir($directory)) @mkdir($directory, 0755, true);
                }
                $pdo = new \PDO('sqlite:' . $path);
            } elseif ($driver === 'mysql') {
                $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $get('DB_HOST', '127.0.0.1'), $get('DB_PORT', 3306), $get('DB_DATABASE', 'app'));
                $pdo = new \PDO($dsn, (string) $get('DB_USERNAME', 'root'), (string) $get('DB_PASSWORD', ''));
            } else {
                throw new \RuntimeException('unsupported driver [' . $driver . ']');
            }
            $pdo->query('SELECT 1');
            $this->add('Database connection', true, $default . ' connected');
        } catch (Throwable $e) {
            $this->add('Database connection', false, $e->getMessage());
        }
    }

    private function checkProductionConfig(): void
    {
        $environment = strtolower((string) ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'local'));
        $debug = filter_var($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN);
        $this->add('Production debug', $environment !== 'production' || !$debug, $environment === 'production' && $debug ? 'APP_DEBUG is enabled' : 'safe');
    }

    private function checkRedis(string $base): void
    {
        $envPath = $base . DIRECTORY_SEPARATOR . '.env';
        $env = is_file($envPath) ? Dotenv::parse($envPath) : [];
        $driver = strtolower((string) ($env['CACHE_DRIVER'] ?? getenv('CACHE_DRIVER') ?: 'file'));
        $realtime = strtolower((string) ($env['REALTIME_DRIVER'] ?? getenv('REALTIME_DRIVER') ?: 'array'));
        $session = strtolower((string) ($env['SESSION_DRIVER'] ?? getenv('SESSION_DRIVER') ?: 'file'));
        $queue = strtolower((string) ($env['QUEUE_CONNECTION'] ?? getenv('QUEUE_CONNECTION') ?: 'database'));
        $required = in_array('redis', [$driver, $realtime, $session, $queue], true);
        if (!$required) { $this->add('Redis', true, 'not required by configured drivers'); return; }
        if (!class_exists('Redis')) { $this->add('Redis', false, 'ext-redis is missing'); return; }
        try {
            $factory = new RedisConnectionFactory((array) require $base . '/config/redis.php');
            $this->add('Redis connection', $factory->ping(), 'connected');
        } catch (Throwable $e) { $this->add('Redis connection', false, $e->getMessage()); }
    }

    private function validKey(string $value): bool
    {
        if (str_starts_with($value, 'base64:')) $value = substr($value, 7);
        $decoded = base64_decode($value, true);
        return $decoded !== false && strlen($decoded) === 32;
    }

    private function add(string $label, bool $ok, string $message): void
    {
        $this->checks[] = ['label' => $label, 'ok' => $ok, 'message' => $message];
    }
}
