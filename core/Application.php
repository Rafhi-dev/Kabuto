<?php

declare(strict_types=1);

namespace Kabuto;

use Kabuto\Container\Container;
use Kabuto\Http\Request;
use Kabuto\Http\Response;
use Kabuto\Routing\Route;
use Kabuto\Routing\Router;
use Kabuto\Database\ConnectionManager;
use Kabuto\Database\DB;
use Kabuto\Database\SchemaLoader;
use Kabuto\Exceptions\NotFoundException;
use Kabuto\Routing\ModuleScanner;
use Kabuto\Routing\RouteScanner;
use Kabuto\Storage\Storage;
use Kabuto\Support\Config;
use Kabuto\Support\Logger;
use Kabuto\Middleware\RequestLogger;
use Kabuto\Middleware\Observability;
use Kabuto\Observability\HealthRegistry;
use Kabuto\Observability\MetricsRegistry;
use Kabuto\Observability\TraceContext;
use Kabuto\Events\EventDispatcher;
use Kabuto\Queue\DatabaseQueue;
use Kabuto\Queue\QueueManager;
use Kabuto\Cache\Cache;
use Kabuto\Cache\FileCacheStore;
use Kabuto\Cache\RedisCacheStore;
use Kabuto\Mail\ArrayMailTransport;
use Kabuto\Mail\MailManager;
use Kabuto\Mail\MailTransport;
use Kabuto\Mail\SmtpTransport;
use Kabuto\Notifications\MailChannel;
use Kabuto\Notifications\NotificationManager;
use Kabuto\Notifications\ArrayNotificationChannel;
use Kabuto\Notifications\ArrayNotificationFailureStore;
use Kabuto\Notifications\ArrayNotificationPreferenceStore;
use Kabuto\Notifications\DatabaseNotificationFailureStore;
use Kabuto\Notifications\DatabaseNotificationPreferenceStore;
use Kabuto\Notifications\RedisNotificationChannel;
use Kabuto\Scheduler\Scheduler;
use Kabuto\Realtime\ArrayRealtimeChannel;
use Kabuto\Realtime\ArrayEventReplayStore;
use Kabuto\Realtime\GatewayRegistry;
use Kabuto\Realtime\GatewayScanner;
use Kabuto\Realtime\RealtimeManager;
use Kabuto\Realtime\RealtimeMessage;
use Kabuto\Redis\RedisConnectionFactory;
use Kabuto\Realtime\RedisPubSubChannel;
use Kabuto\Auth\FileSessionStore;
use Kabuto\Auth\RedisSessionStore;
use Kabuto\Auth\JwtManager;
use Kabuto\Auth\JwtService;
use Kabuto\Auth\ArrayTokenRevoker;
use Kabuto\Auth\FileTokenRevoker;
use Kabuto\Auth\DatabaseTokenRevoker;
use Kabuto\Auth\RedisTokenRevoker;
use Kabuto\Auth\PolicyRegistry;
use Kabuto\Auth\Gate;
use Kabuto\Middleware\Authorization;

/**
 * Application facade: the public entry point for defining routes, middleware,
 * groups, module registration and running the request lifecycle.
 */
final class Application
{
    private static ?self $current = null;

    private Router $router;

    private Container $container;

    private Config $config;

    private string $basePath;

    /** @var list<callable|string> */
    private array $middleware = [];

    /** @var callable|null */
    private $errorHandler = null;

    /** @var list<class-string<\Kabuto\Exceptions\ExceptionFilter>|class-string<\Kabuto\Exceptions\ContextualExceptionFilter>> */
    private array $exceptionFilters = [];

    public function __construct(
        ?Router $router = null,
        ?Container $container = null,
        ?string $basePath = null,
    ) {
        $this->router = $router ?? new Router();
        $this->container = $container ?? new Container();
        $this->config = new Config();
        $this->basePath = $basePath ?? dirname(__DIR__);

        self::$current = $this;
    }

    /**
     * The most recently created application instance (used by global helpers).
     */
    public static function current(): self
    {
        return self::$current ??= new self();
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    /**
     * Load configuration files from a directory (default: <base>/config).
     */
    public function loadConfig(?string $directory = null): self
    {
        $this->config->load($directory ?? $this->basePath . '/config');
        return $this;
    }

    /**
     * Register a transient binding in the container.
     *
     * @param class-string $id
     * @param callable(Container):mixed|string|null $concrete
     */
    public function bind(string $id, callable|string|null $concrete = null): self
    {
        $this->container->bind($id, $concrete);
        return $this;
    }

    /**
     * Register a shared binding in the container.
     *
     * @param class-string $id
     * @param callable(Container):mixed|string|null $concrete
     */
    public function singleton(string $id, callable|string|null $concrete = null): self
    {
        $this->container->singleton($id, $concrete);
        return $this;
    }

    /**
     * Register an already-built object in the container.
     *
     * @param class-string $id
     */
    public function instance(string $id, object $instance): self
    {
        $this->container->instance($id, $instance);
        return $this;
    }

    /**
     * Run a service provider: a class-string, any callable, or an object
     * exposing register().
     *
     * @param callable|object|class-string $provider
     */
    public function register(callable|object|string $provider): self
    {
        if (is_string($provider)) {
            if (class_exists($provider)) {
                $provider = $this->container->get($provider);
            } elseif (!is_callable($provider)) {
                throw new \InvalidArgumentException(sprintf('Provider [%s] is neither a class nor a callable.', $provider));
            }
        }

        if (is_object($provider) && method_exists($provider, 'register')) {
            $provider->register($this);
            return $this;
        }

        if (is_callable($provider)) {
            $provider($this);
        }

        return $this;
    }

    /**
     * Register a NestJS-style module (and everything it imports).
     *
     * @param class-string $module
     */
    public function registerModule(string $module): self
    {
        (new ModuleScanner($this->router, $this->container))->register($module);

        return $this;
    }

    /**
     * Load every `routes.php` found directly under each module directory.
     *
     * Each file is included with `$app` and `$router` in scope.
     */
    public function loadRoutes(string $modulesPath): self
    {
        $modulesPath = rtrim($modulesPath, '/\\');

        if (!is_dir($modulesPath)) {
            return $this;
        }

        $app = $this;
        $router = $this->router;

        foreach (glob($modulesPath . '/*/routes.php') ?: [] as $file) {
            (static function (string $__file) use ($app, $router): void {
                require $__file;
            })($file);
        }

        return $this;
    }

    /**
     * Discover controllers annotated with #[Controller] under a directory and
     * register their routes automatically. Only annotated classes are picked
     * up, so route files remain the primary, explicit mechanism.
     */
    public function discoverModules(string $modulesPath, string $namespace = 'App\\Modules'): self
    {
        $modulesPath = rtrim($modulesPath, '/\\');

        if (!is_dir($modulesPath)) {
            return $this;
        }

        $scanner = new RouteScanner($this->router);

        foreach ($scanner->discover($modulesPath, $namespace) as $class) {
            $scanner->register($class);
        }

        return $this;
    }

    /**
     * Boot the framework: load config, validate required env, run providers
     * and load routes from the given modules config.
     */
    public function boot(): self
    {
        $this->loadConfig();

        $this->registerLogging();
        $this->registerObservability();
        $this->registerJwt();
        $this->registerPolicies();
        $this->container->get(PolicyRegistry::class);
        $this->configureUploads();
        $this->registerEvents();
        $this->registerCache();
        $this->registerMail();
        $this->registerNotifications();
        $this->registerScheduler();
        $this->registerRealtime();
        $this->registerGateways();
        $this->registerRedis();
        $this->registerSession();

        Support\Dotenv::required(['APP_KEY']);

        $this->registerDatabase();
        $this->registerConfiguredSchedules();
        $this->registerStorageRoute();

        $config = $this->config->get('modules', []);

        foreach ($config['providers'] ?? [] as $provider) {
            $this->register($provider);
        }

        foreach ($config['modules'] ?? [] as $module) {
            $this->registerModule($module);
        }

        if (isset($config['routes'])) {
            $this->loadRoutes($config['routes']);
        }

        if (($config['discover'] ?? false) && isset($config['routes'])) {
            $this->discoverModules($config['routes'], $config['namespace'] ?? 'App\\Modules');
        }

        return $this;
    }

    private function registerLogging(): void
    {
        $this->singleton(Logger::class, function (): Logger {
            return new Logger(
                (string) $this->config->get('logging.channel', 'app'),
                (string) $this->config->get('logging.level', 'info'),
                (string) $this->config->get('logging.path', $this->basePath . '/storage/logs/kabuto.log'),
            );
        });

        if ($this->config->get('logging.http.enabled', false)) {
            $this->use(RequestLogger::class);
        }
    }

    private function registerObservability(): void
    {
        $this->singleton(MetricsRegistry::class, fn (): MetricsRegistry => new MetricsRegistry());
        $this->singleton(TraceContext::class, fn (): TraceContext => new TraceContext());
        $this->singleton(HealthRegistry::class, function (): HealthRegistry {
            $health = new HealthRegistry();
            $health->check('process', static fn (): bool => true, readiness: false);
            $health->check('database', function (): array {
                try {
                    $this->container->get(ConnectionManager::class)->connection()->query('SELECT 1');
                    return ['ok' => true];
                } catch (\Throwable $exception) {
                    return ['ok' => false, 'error' => $exception->getMessage()];
                }
            });
            $health->check('redis', function (): array {
                $driver = strtolower((string) $this->config->get('realtime.driver', 'array'));
                $cache = strtolower((string) $this->config->get('cache.driver', 'file'));
                $queue = strtolower((string) $this->config->get('queue.driver', 'database'));
                if (!in_array('redis', [$driver, $cache, $queue], true)) return ['ok' => true, 'skipped' => true];
                try {
                    return ['ok' => $this->container->get(RedisConnectionFactory::class)->ping()];
                } catch (\Throwable $exception) {
                    return ['ok' => false, 'error' => $exception->getMessage()];
                }
            });
            $health->check('cache', function (): array {
                try {
                    $key = 'kabuto:health:' . bin2hex(random_bytes(4));
                    $cache = Cache::put($key, true, 5);
                    Cache::forget($key);
                    return ['ok' => $cache];
                } catch (\Throwable $exception) {
                    return ['ok' => false, 'error' => $exception->getMessage()];
                }
            });
            $health->check('queue', function (): array {
                try {
                    $manager = new QueueManager($this->container->get(ConnectionManager::class));
                    $probe = $manager->probe(
                        (string) $this->config->get('queue.queue', 'default'),
                        $this->container->has(RedisConnectionFactory::class) ? $this->container->get(RedisConnectionFactory::class) : null,
                    );
                    $registry = $this->container->get(MetricsRegistry::class);
                    $registry->gauge('kabuto_queue_depth', $probe['depth'], ['queue' => (string) $this->config->get('queue.queue', 'default')]);
                    $registry->gauge('kabuto_queue_failed_jobs', $probe['failed'], ['queue' => (string) $this->config->get('queue.queue', 'default')]);
                    return $probe;
                } catch (\Throwable $exception) {
                    return ['ok' => false, 'error' => $exception->getMessage()];
                }
            });
            $health->check('mail', function (): array {
                try {
                    $manager = $this->container->get(MailManager::class);
                    return ['ok' => $manager->health(), 'driver' => (string) config('mail.driver', 'array')];
                } catch (\Throwable $exception) {
                    return ['ok' => false, 'error' => $exception->getMessage()];
                }
            });
            $health->check('storage', function (): array {
                $path = (string) $this->config->get('logging.path', $this->basePath . '/storage/logs/kabuto.log');
                return ['ok' => is_dir(dirname($path)) || @mkdir(dirname($path), 0755, true) || is_dir(dirname($path))];
            });
            $health->check('realtime', function (): array {
                try {
                    $this->container->get(\Kabuto\Realtime\RealtimeManager::class)->channel('default');
                    return ['ok' => true];
                } catch (\Throwable $exception) {
                    return ['ok' => false, 'error' => $exception->getMessage()];
                }
            });
            return $health;
        });
        if ((bool) $this->config->get('observability.http.enabled', true)) {
            $this->use(Observability::class);
        }
        if ((bool) $this->config->get('auth.deny_by_default', false)) $this->use(Authorization::class);
        if (!$this->router->has('GET', '/health/live')) {
            $this->router->get('/health/live', function (Request $request, Response $response): Response {
                $result = $this->container->get(HealthRegistry::class)->live();
                return $response->json($result, $result['status'] === 'ok' ? 200 : 503);
            }, [], ['public' => true]);
        }
        if (!$this->router->has('GET', '/health/ready')) {
            $this->router->get('/health/ready', function (Request $request, Response $response): Response {
                $result = $this->container->get(HealthRegistry::class)->ready();
                return $response->json($result, $result['status'] === 'ok' ? 200 : 503);
            }, [], ['public' => true]);
        }
        if ((bool) $this->config->get('observability.metrics.enabled', true) && !$this->router->has('GET', '/metrics')) {
            $this->router->get('/metrics', function (Request $request, Response $response): Response {
                return $response->header('Content-Type', 'text/plain; version=0.0.4')->send($this->container->get(MetricsRegistry::class)->prometheus());
            }, [], ['public' => true]);
        }
    }

    private function registerJwt(): void
    {
        $this->singleton(JwtManager::class, function (): JwtManager {
            $driver = strtolower((string) $this->config->get('auth.jwt.revocation', 'memory'));
            $revoker = match ($driver) {
                'file' => new FileTokenRevoker((string) $this->config->get('auth.jwt.revocation_path', $this->basePath . '/storage/framework/jwt-revocations.json')),
                'database' => new DatabaseTokenRevoker($this->container->get(ConnectionManager::class)->connection()),
                'redis' => new RedisTokenRevoker($this->container->get(RedisConnectionFactory::class)->make(), (string) $this->config->get('redis.prefix', 'kabuto:') . 'jwt:revoked:'),
                'none' => null,
                default => new ArrayTokenRevoker(),
            };
            return new JwtManager(
                (string) $this->config->get('auth.jwt.secret', env('JWT_SECRET', env('APP_KEY', ''))),
                (int) $this->config->get('auth.jwt.access_ttl', 900),
                (int) $this->config->get('auth.jwt.refresh_ttl', 2592000),
                $this->config->get('auth.jwt.issuer'),
                $this->config->get('auth.jwt.audience'),
                $revoker,
                (int) $this->config->get('auth.jwt.leeway', 0),
                (int) $this->config->get('auth.jwt.max_access_ttl', 0) ?: null,
                (int) $this->config->get('auth.jwt.max_refresh_ttl', 0) ?: null,
            );
        });
        $this->singleton(JwtService::class, fn (): JwtService => new JwtService($this->container->get(JwtManager::class)));
    }

    private function registerPolicies(): void
    {
        $this->singleton(PolicyRegistry::class, function (): PolicyRegistry {
            $registry = new PolicyRegistry();
            $registry->discover(
                (string) $this->config->get('auth.policies.path', $this->basePath . '/app/Policies'),
                (string) $this->config->get('auth.policies.namespace', 'App\\Policies'),
                (string) $this->config->get('auth.policies.models', 'App\\Models'),
            );
            Gate::registerPolicies($registry);
            return $registry;
        });
    }

    private function configureUploads(): void
    {
        $uploads = (array) $this->config->get('uploads', $this->config->get('storage.uploads', []));
        $scanner = match ((string) ($uploads['scan_driver'] ?? 'none')) {
            'clamav' => new \Kabuto\Storage\ClamAvScanner((string) ($uploads['clamav_socket'] ?? '/var/run/clamav/clamd.sock')),
            'stub' => new \Kabuto\Storage\StubScanner(),
            'none' => null,
            default => throw new \InvalidArgumentException('Unsupported upload scan driver [' . ($uploads['scan_driver'] ?? '') . '].'),
        };
        $quarantine = $scanner === null ? null : new \Kabuto\Storage\Quarantine((string) ($uploads['quarantine_path'] ?? $this->basePath . '/storage/quarantine'));
        $sanitizer = !empty($uploads['sanitize_images']) ? new \Kabuto\Storage\ImageSanitizer() : null;
        \Kabuto\Storage\Storage::setBasePath($this->basePath);
        \Kabuto\Storage\Storage::configureUploads((int) ($uploads['max_file_size'] ?? 10485760), $scanner, $quarantine, $sanitizer);
    }

    private function registerEvents(): void
    {
        $this->singleton(EventDispatcher::class, function (): EventDispatcher {
            return new EventDispatcher($this->container, function (): DatabaseQueue {
                $schema = SchemaLoader::load($this->basePath, (array) $this->config->get('database', []));
                $datasource = $schema->defaultDatasource ?? 'default';
                return new DatabaseQueue($this->container->get(ConnectionManager::class)->connection($datasource));
            });
        });
    }

    private function registerCache(): void
    {
        $this->singleton(Cache::class, function (): Cache {
            $driver = (string) $this->config->get('cache.driver', 'file');
            if ($driver === 'memory') Cache::setStore(new \Kabuto\Cache\MemoryCacheStore());
            elseif ($driver === 'redis') {
                if (!class_exists('Redis')) throw new \RuntimeException('Redis cache driver requires the ext-redis PHP extension.');
                $redisConfig = (array) $this->config->get('redis', []);
                Cache::setStore(new RedisCacheStore(
                    $this->container->get(RedisConnectionFactory::class)->make(),
                    (string) ($redisConfig['prefix'] ?? 'kabuto:') . 'cache:',
                ));
            } else Cache::setStore(new FileCacheStore((string) $this->config->get('cache.path', $this->basePath . '/storage/cache')));
            return new Cache();
        });
    }

    private function registerMail(): void
    {
        $this->singleton(MailManager::class, function (): MailManager {
            $driver = (string) $this->config->get('mail.driver', 'array');
            if ($driver === 'smtp') {
                return new MailManager(new SmtpTransport(
                    (string) $this->config->get('mail.host', '127.0.0.1'),
                    (int) $this->config->get('mail.port', 587),
                    $this->config->get('mail.username'),
                    $this->config->get('mail.password'),
                    (string) $this->config->get('mail.from', ''),
                    (bool) $this->config->get('mail.tls', true),
                    (int) $this->config->get('mail.retries', 2),
                    (float) $this->config->get('mail.timeout', 10),
                    null,
                    (string) $this->config->get('mail.ehlo_name', 'kabuto.local'),
                    (bool) $this->config->get('mail.allow_insecure_auth', false),
                ), (string) $this->config->get('mail.from', ''));
            }
            if ($driver !== 'array') throw new \RuntimeException("Unsupported mail driver [{$driver}]. Register a custom MailTransport binding.");
            return new MailManager(new ArrayMailTransport(), (string) $this->config->get('mail.from', ''));
        });
    }

    private function registerNotifications(): void
    {
        $this->singleton(NotificationManager::class, function (): NotificationManager {
            $channels = [
                'mail' => new MailChannel($this->container->get(MailManager::class)),
                'array' => new ArrayNotificationChannel(),
            ];
            if ((string) $this->config->get('notifications.driver', 'array') === 'redis') {
                $channels['redis'] = new RedisNotificationChannel($this->container->get(RedisConnectionFactory::class)->make(), (string) $this->config->get('redis.prefix', 'kabuto:') . 'notifications:');
            }
            $preferenceStore = new ArrayNotificationPreferenceStore();
            $failureStore = new ArrayNotificationFailureStore();
            if ((string) $this->config->get('notifications.store', 'memory') === 'database') {
                $pdo = $this->container->get(ConnectionManager::class)->connection();
                $preferenceStore = new DatabaseNotificationPreferenceStore($pdo);
                $failureStore = new DatabaseNotificationFailureStore($pdo);
            }
            return new NotificationManager(
                $channels,
                $preferenceStore,
                $failureStore,
                (int) $this->config->get('notifications.attempts', 1),
                (int) $this->config->get('notifications.retry_delay_ms', 100),
            );
        });
    }

    private function registerScheduler(): void
    {
        $this->singleton(Scheduler::class, function (): Scheduler {
            $lockStore = null;
            $historyStore = null;
            $lockDriver = (string) $this->config->get('schedule.lock_store', 'memory');
            if ($lockDriver === 'database') {
                $lockStore = new \Kabuto\Scheduler\DatabaseLockStore($this->container->get(ConnectionManager::class)->connection());
            } elseif ($lockDriver === 'redis') {
                $lockStore = new \Kabuto\Scheduler\RedisLockStore(
                    $this->container->get(RedisConnectionFactory::class)->make(),
                    (string) $this->config->get('redis.prefix', 'kabuto:') . 'schedule:lock:',
                );
            } elseif ($lockDriver !== 'memory') {
                throw new \InvalidArgumentException("Unsupported scheduler lock store [{$lockDriver}].");
            }

            $historyDriver = (string) $this->config->get('schedule.history_store', 'memory');
            if ($historyDriver === 'database') {
                $historyStore = new \Kabuto\Scheduler\DatabaseScheduleHistoryStore($this->container->get(ConnectionManager::class)->connection());
            } elseif ($historyDriver === 'none') {
                $historyStore = new \Kabuto\Scheduler\NullScheduleHistoryStore();
            } elseif ($historyDriver !== 'memory') {
                throw new \InvalidArgumentException("Unsupported scheduler history store [{$historyDriver}].");
            }

            $maintenanceFile = (string) $this->config->get('schedule.maintenance_file', $this->basePath . '/storage/framework/down');
            return (new Scheduler(
                $lockStore,
                $historyStore,
                (int) $this->config->get('schedule.shutdown_grace_seconds', 5),
            ))->duringMaintenance(static fn (): bool => is_file($maintenanceFile));
        });
    }

    private function registerConfiguredSchedules(): void
    {
        $scheduler = $this->container->get(Scheduler::class);
        foreach ((array) $this->config->get('schedule.tasks', []) as $task) {
            if (!is_array($task) || !isset($task['interval'], $task['callback']) || !is_callable($task['callback'])) {
                continue;
            }
            $scheduler->every(
                (int) $task['interval'],
                \Closure::fromCallable($task['callback']),
                (string) ($task['description'] ?? ''),
                isset($task['id']) ? (string) $task['id'] : null,
            );
            $registered = $scheduler->tasks()[array_key_last($scheduler->tasks())];
            if (isset($task['retries'])) {
                $registered->retries((int) $task['retries'], (int) ($task['retry_delay'] ?? 0));
            }
            if (isset($task['timeout'])) {
                $registered->timeout((int) $task['timeout']);
            }
            if (isset($task['lock_ttl'])) {
                $registered->withoutOverlapping((int) $task['lock_ttl']);
            }
            if (array_key_exists('without_overlapping', $task) && !$task['without_overlapping']) {
                $registered->allowOverlapping();
            }
            $registered->onOneServer((bool) ($task['on_one_server'] ?? true));
            $registered->evenInMaintenance((bool) ($task['even_in_maintenance'] ?? false));
        }
    }

    private function registerRealtime(): void
    {
        $this->singleton(RealtimeManager::class, function (): RealtimeManager {
            $manager = (string) $this->config->get('realtime.driver', 'array') === 'redis'
                ? new RealtimeManager(['default' => new RedisPubSubChannel($this->container->get(RedisConnectionFactory::class)->make(), 'default', (string) $this->config->get('redis.prefix', 'kabuto:'))])
                : new RealtimeManager(['default' => new ArrayRealtimeChannel()]);
            if ((int) $this->config->get('realtime.replay_capacity', 0) > 0) {
                $manager = $manager->withReplay(new ArrayEventReplayStore((int) $this->config->get('realtime.replay_capacity')));
            }
            $manager = $manager->withMetrics($this->container->get(MetricsRegistry::class));
            if ((bool) $this->config->get('realtime.fanout_redis', false)) {
                $factory = $this->container->get(RedisConnectionFactory::class);
                $prefix = (string) $this->config->get('redis.prefix', 'kabuto:');
                $manager = $manager->withFanout(static function (string $channel, RealtimeMessage $message) use ($factory, $prefix): void {
                    $factory->make()->publish(
                        $prefix . 'realtime:fanout',
                        json_encode(['channel' => $channel, 'event' => $message->event, 'data' => $message->data, 'id' => $message->id, 'retry' => $message->retry], JSON_THROW_ON_ERROR)
                    );
                });
            }
            return $manager;
        });
    }

    private function registerGateways(): void
    {
        $this->singleton(GatewayRegistry::class, function (): GatewayRegistry {
            $registry = new GatewayRegistry($this->container);
            (new GatewayScanner($registry))->discover(
                (string) $this->config->get('realtime.gateways.path', $this->basePath . '/app/Gateways'),
                (string) $this->config->get('realtime.gateways.namespace', 'App\\Gateways'),
            );
            return $registry;
        });
    }

    private function registerRedis(): void
    {
        $this->singleton(RedisConnectionFactory::class, fn (): RedisConnectionFactory => new RedisConnectionFactory((array) $this->config->get('redis', [])));
    }

    private function registerSession(): void
    {
        $this->singleton(\Kabuto\Auth\SessionManager::class, function (): \Kabuto\Auth\SessionManager {
            $driver = (string) $this->config->get('session.driver', 'file');
            $store = $driver === 'redis'
                ? new RedisSessionStore($this->container->get(RedisConnectionFactory::class)->make(), (string) $this->config->get('redis.prefix', 'kabuto:') . 'session:')
                : new FileSessionStore((string) $this->config->get('session.path', $this->basePath . '/storage/sessions'));
            return new \Kabuto\Auth\SessionManager($store, (string) $this->config->get('session.cookie', 'kabuto_session'), (int) $this->config->get('session.lifetime', 7200), (bool) $this->config->get('session.secure', false));
        });
    }

    public function scheduler(): Scheduler
    {
        return $this->container->get(Scheduler::class);
    }

    /**
     * Register core database connection manager and DB client singleton.
     */
    private function registerDatabase(): void
    {
        $this->singleton(ConnectionManager::class, function (): ConnectionManager {
            $schema = SchemaLoader::load($this->basePath, (array) $this->config->get('database', []));
            return (new ConnectionManager($schema))->withMetrics($this->container->get(MetricsRegistry::class));
        });

        $this->singleton(DB::class, function (): DB {
            return new DB($this->container->get(ConnectionManager::class));
        });
    }

    /**
     * Register static file handler for public storage (/storage/*).
     */
    private function registerStorageRoute(): void
    {
        $this->router->get('/storage/*', function (Request $request, Response $response): Response {
            $path = (string) $request->param('wildcard');
            $disk = Storage::disk('public');
            try {
                $fullPath = $disk->path($path);
            } catch (\RuntimeException) {
                throw new NotFoundException('File not found.');
            }

            $realPublicRoot = realpath($disk->root());
            $realFile = realpath($fullPath);

            $rootPrefix = $realPublicRoot === false ? '' : rtrim($realPublicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if ($realPublicRoot === false || $realFile === false || !str_starts_with($realFile, $rootPrefix) || !is_file($realFile)) {
                throw new NotFoundException('File not found.');
            }

            $mime = $disk->mime($path) ?? 'application/octet-stream';
            $size = (string) filesize($realFile);

            return $response
                ->header('Content-Type', $mime)
                ->header('Content-Length', $size)
                ->header('Cache-Control', 'public, max-age=31536000')
                ->send(file_get_contents($realFile) ?: '');
        });
    }

    /**
     * Register global middleware, applied to every request.
     *
     * @param callable|string ...$middleware
     */
    public function use(callable|string ...$middleware): self
    {
        array_push($this->middleware, ...$middleware);
        return $this;
    }

    /**
     * @param list<callable|string> $middleware
     */
    public function get(string $pattern, mixed $handler, array $middleware = []): Route
    {
        return $this->router->get($pattern, $handler, $middleware);
    }

    /**
     * @param list<callable|string> $middleware
     */
    public function post(string $pattern, mixed $handler, array $middleware = []): Route
    {
        return $this->router->post($pattern, $handler, $middleware);
    }

    /**
     * @param list<callable|string> $middleware
     */
    public function put(string $pattern, mixed $handler, array $middleware = []): Route
    {
        return $this->router->put($pattern, $handler, $middleware);
    }

    /**
     * @param list<callable|string> $middleware
     */
    public function patch(string $pattern, mixed $handler, array $middleware = []): Route
    {
        return $this->router->patch($pattern, $handler, $middleware);
    }

    /**
     * @param list<callable|string> $middleware
     */
    public function delete(string $pattern, mixed $handler, array $middleware = []): Route
    {
        return $this->router->delete($pattern, $handler, $middleware);
    }

    /**
     * @param list<callable|string> $middleware
     */
    public function any(string $pattern, mixed $handler, array $middleware = []): Route
    {
        return $this->router->any($pattern, $handler, $middleware);
    }

    /**
     * @param list<callable|string> $middleware
     */
    public function group(string $prefix, callable $callback, array $middleware = []): self
    {
        $this->router->group($prefix, $callback, $middleware);
        return $this;
    }

    public function onError(callable $handler): self
    {
        $this->errorHandler = $handler;
        return $this;
    }

    /** @param class-string<\Kabuto\Exceptions\ExceptionFilter>|class-string<\Kabuto\Exceptions\ContextualExceptionFilter> ...$filters */
    public function useGlobalFilters(string ...$filters): self
    {
        foreach ($filters as $filter) {
            if (!class_exists($filter)) throw new \InvalidArgumentException(sprintf('Exception filter [%s] does not exist.', $filter));
            if (!is_a($filter, \Kabuto\Exceptions\ExceptionFilter::class, true)
                && !is_a($filter, \Kabuto\Exceptions\ContextualExceptionFilter::class, true)) {
                throw new \InvalidArgumentException(sprintf('Exception filter [%s] must implement an exception filter interface.', $filter));
            }
            $this->exceptionFilters[] = $filter;
        }
        return $this;
    }

    public function handle(Request $request): Response
    {
        $kernel = new Kernel($this->router, $this->container, $this->middleware);
        if ($this->errorHandler !== null) {
            $kernel->onError($this->errorHandler);
        }
        if ($this->exceptionFilters !== []) $kernel->useGlobalFilters(...$this->exceptionFilters);
        return $kernel->handle($request);
    }

    public function listen(): void
    {
        $this->handle(Request::fromGlobals())->emit();
    }
}
