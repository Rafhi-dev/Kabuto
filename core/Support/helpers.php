<?php

declare(strict_types=1);

/**
 * Global helper functions for Kabuto.
 *
 * These are autoloaded via composer.json "autoload.files" so they are always
 * available, keeping application code free of long use statements.
 */

use Kabuto\Application;
use Kabuto\Http\Response;
use Kabuto\Support\Config;

if (!function_exists('app')) {
    /**
     * The current application instance, or a resolved service from its
     * container when an id is given.
     *
     * @template T of object
     * @param class-string<T>|null $id
     * @return ($id is null ? Application : T)
     */
    function app(?string $id = null): mixed
    {
        $app = Application::current();

        if ($id === null) {
            return $app;
        }

        return $app->container()->get($id);
    }
}

if (!function_exists('config')) {
    /**
     * Read a configuration value using dot-notation, e.g. config('app.name').
     */
    function config(string $key, mixed $default = null): mixed
    {
        $config = Application::current()->config();

        return $config instanceof Config ? $config->get($key, $default) : $default;
    }
}

if (!function_exists('mailer')) {
    function mailer(?\Kabuto\Mail\MailMessage $message = null): \Kabuto\Mail\MailManager
    {
        $manager = Application::current()->container()->get(\Kabuto\Mail\MailManager::class);
        if ($message !== null) $manager->send($message);
        return $manager;
    }
}

if (!function_exists('notify')) {
    function notify(mixed $notifiable, \Kabuto\Notifications\Notification $notification): int
    {
        return Application::current()->container()->get(\Kabuto\Notifications\NotificationManager::class)->send($notifiable, $notification);
    }
}

if (!function_exists('schedule')) {
    function schedule(): \Kabuto\Scheduler\Scheduler
    {
        return Application::current()->container()->get(\Kabuto\Scheduler\Scheduler::class);
    }
}

if (!function_exists('broadcast')) {
    function broadcast(string $channel, string $event, mixed $data): void
    {
        app(\Kabuto\Realtime\RealtimeManager::class)->publish($channel, new \Kabuto\Realtime\RealtimeMessage($event, $data));
    }
}

if (!function_exists('authorize')) {
    function authorize(string $ability, mixed $model, mixed ...$arguments): void
    {
        $user = null;
        if (class_exists(\Kabuto\Application::class)) {
            try {
                $container = \Kabuto\Application::current()->container();
                if ($container->has(\Kabuto\Http\Request::class)) {
                    $user = $container->get(\Kabuto\Http\Request::class)->user();
                }
            } catch (\Throwable) {
            }
        }
        \Kabuto\Auth\Gate::forUser($user)->authorizeModel($ability, $model, ...$arguments);
    }
}

if (!function_exists('json')) {
    /**
     * Build a JSON response.
     */
    function json(mixed $data, int $status = 200): Response
    {
        return (new Response())->status($status)->json($data);
    }
}

if (!function_exists('trace_headers')) {
    /** @param array<string,string> $headers @return array<string,string> */
    function trace_headers(array $headers = []): array
    {
        $context = \Kabuto\Observability\TraceContext::current();
        if ($context === null) return $headers;
        return app(\Kabuto\Observability\TraceContext::class)->inject($headers, $context);
    }
}

if (!function_exists('base_path')) {
    /**
     * Absolute path to the project root, optionally joined with a sub-path.
     */
    function base_path(string $path = ''): string
    {
        $base = Application::current()->basePath();
        $path = ltrim($path, '/\\');

        return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . $path;
    }
}

if (!function_exists('env')) {
    /**
     * Read an environment variable with basic type coercion for
     * "true", "false", "null" and "empty".
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true' => true,
            'false' => false,
            'null', 'nil' => null,
            'empty' => '',
            default => $value,
        };
    }
}

if (!function_exists('db')) {
    /**
     * The database client for a schema group (default: the default schema).
     */
    function db(?string $schema = null): Kabuto\Database\DB
    {
        return Application::current()->container()->get(Kabuto\Database\DB::class)->schema($schema);
    }
}

if (!function_exists('encrypt')) {
    /**
     * Encrypt a string with APP_KEY (AES-256-CBC). Returns base64(iv.cipher.mac).
     */
    function encrypt(string $value): string
    {
        $key = appKey();

        if (!extension_loaded('openssl')) {
            throw new RuntimeException('The openssl extension is required for encrypt().');
        }

        $iv = random_bytes(16);
        $cipher = openssl_encrypt($value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($cipher === false) {
            throw new RuntimeException('Encryption failed.');
        }

        $mac = hash_hmac('sha256', $iv . $cipher, $key, true);

        return base64_encode($iv . $cipher . $mac);
    }
}

if (!function_exists('decrypt')) {
    /**
     * Decrypt a payload produced by encrypt(). Throws on tampering.
     */
    function decrypt(string $payload): string
    {
        $key = appKey();

        if (!extension_loaded('openssl')) {
            throw new RuntimeException('The openssl extension is required for decrypt().');
        }

        $raw = base64_decode($payload, true);

        if ($raw === false || strlen($raw) < 49) {
            throw new RuntimeException('Invalid encrypted payload.');
        }

        $iv = substr($raw, 0, 16);
        $mac = substr($raw, -32);
        $cipher = substr($raw, 16, -32);

        if (!hash_equals(hash_hmac('sha256', $iv . $cipher, $key, true), $mac)) {
            throw new RuntimeException('Encrypted payload failed integrity check.');
        }

        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($plain === false) {
            throw new RuntimeException('Decryption failed.');
        }

        return $plain;
    }
}

if (!function_exists('appKey')) {
    /**
     * Raw 32-byte application key from APP_KEY (base64: or base64(...)).
     */
    function appKey(): string
    {
        $configured = (string) config('app.key', env('APP_KEY', ''));

        if (str_starts_with($configured, 'base64:')) {
            $configured = substr($configured, 7);
        }

        $key = base64_decode($configured, true);

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('APP_KEY must be a base64-encoded 32-byte key. Run: php kabuto key:generate');
        }

        return $key;
    }
}

if (!function_exists('hash_make')) {
    /**
     * Hash a plain-text value using Argon2id or Bcrypt.
     *
     * @param array<string,mixed> $options
     */
    function hash_make(string $value, array $options = []): string
    {
        return \Kabuto\Support\Hash::make($value, $options);
    }
}

if (!function_exists('hash_verify')) {
    /**
     * Verify that a plain-text value matches a hash.
     */
    function hash_verify(string $value, string $hashedValue): bool
    {
        return \Kabuto\Support\Hash::verify($value, $hashedValue);
    }
}

if (!function_exists('cookie')) {
    /**
     * Create a new Cookie instance.
     *
     * @param array{
     *     path?: string,
     *     domain?: ?string,
     *     secure?: bool,
     *     httponly?: bool,
     *     samesite?: string,
     *     encrypt?: bool
     * } $options
     */
    function cookie(string $name, string $value = '', int $minutes = 0, array $options = []): \Kabuto\Http\Cookie
    {
        $c = \Kabuto\Http\Cookie::make($name, $value, $minutes);

        if (isset($options['path'])) {
            $c->path($options['path']);
        }
        if (isset($options['domain'])) {
            $c->domain($options['domain']);
        }
        if (isset($options['secure'])) {
            $c->secure((bool) $options['secure']);
        }
        if (isset($options['httponly'])) {
            $c->httpOnly((bool) $options['httponly']);
        }
        if (isset($options['samesite'])) {
            $c->sameSite($options['samesite']);
        }
        if (!empty($options['encrypt'])) {
            $c->encrypt(true);
        }

        return $c;
    }
}

if (!function_exists('jwt_sign')) {
    /**
     * Sign and issue a JWT token.
     *
     * @param array<string,mixed> $payload
     */
    function jwt_sign(array $payload, int $ttl = 3600, ?string $secret = null, array $headers = []): string
    {
        return \Kabuto\Auth\JWT::sign($payload, $secret, $ttl, $headers);
    }
}

if (!function_exists('jwt_decode')) {
    /**
     * Decode and verify a JWT token. Throws JwtException on failure.
     *
     * @return array<string,mixed>
     */
    function jwt_decode(string $token, ?string $secret = null, int $leeway = 0): array
    {
        return \Kabuto\Auth\JWT::decode($token, $secret, $leeway);
    }
}

if (!function_exists('jwt_verify')) {
    /**
     * Safely verify a JWT token. Returns payload array or null on failure.
     *
     * @return array<string,mixed>|null
     */
    function jwt_verify(string $token, ?string $secret = null, int $leeway = 0): ?array
    {
        return \Kabuto\Auth\JWT::verify($token, $secret, $leeway);
    }
}

if (!function_exists('gate')) {
    /**
     * Access the Gate authorization engine or check an ability.
     *
     * @param string|null $ability
     * @param mixed ...$args
     * @return ($ability is null ? \Kabuto\Auth\Gate : bool)
     */
    function gate(?string $ability = null, mixed ...$args): mixed
    {
        if ($ability === null) {
            return new \Kabuto\Auth\Gate();
        }

        return \Kabuto\Auth\Gate::allows($ability, ...$args);
    }
}

if (!function_exists('storage')) {
    /**
     * Access the Storage disk manager or execute operations on a disk.
     */
    function storage(?string $disk = null): \Kabuto\Storage\StorageDisk
    {
        return \Kabuto\Storage\Storage::disk($disk);
    }
}

if (!function_exists('cache')) {
    function cache(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) return \Kabuto\Cache\Cache::store();
        return \Kabuto\Cache\Cache::get($key, $default);
    }
}
