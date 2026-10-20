<?php

declare(strict_types=1);

namespace Kabuto\Auth;

use Kabuto\Application;
use Kabuto\Exceptions\ForbiddenException;
use Kabuto\Http\Request;
use Throwable;

final class Gate
{
    /** @var array<string, callable> */
    private static array $abilities = [];

    /** @var list<callable> */
    private static array $beforeCallbacks = [];

    /** @var array<class-string,object> */
    private static array $policies = [];

    private mixed $user = null;
    private bool $userExplicitlySet = false;

    public function __construct(mixed $user = null)
    {
        if ($user !== null) {
            $this->user = $user;
            $this->userExplicitlySet = true;
        }
    }

    public static function define(string $ability, callable $callback): void
    {
        self::$abilities[$ability] = $callback;
    }

    public static function before(callable $callback): void
    {
        self::$beforeCallbacks[] = $callback;
    }

    /** @param class-string $model @param object $policy */
    public static function policy(string $model, object $policy): void
    {
        self::$policies[$model] = $policy;
    }

    public static function registerPolicies(PolicyRegistry $registry): void
    {
        self::$policies = [];
        foreach ($registry->all() as $model => $policy) self::$policies[$model] = $policy;
    }

    public function authorizeModel(string $ability, mixed $model, mixed ...$arguments): void
    {
        $policyClass = null;
        if ($model instanceof \Kabuto\Auth\PolicyTarget) {
            $policyClass = $model->modelClass();
            $model = $model->value();
        }
        $policy = self::$policies[$policyClass ?? (is_object($model) ? $model::class : get_debug_type($model))] ?? null;
        if ($policy === null || !method_exists($policy, $ability)) throw new ForbiddenException();
        $user = $this->getUser();
        if (!(bool) $policy->{$ability}($user, $model, ...$arguments)) throw new ForbiddenException();
    }

    public static function has(string $ability): bool
    {
        return isset(self::$abilities[$ability]);
    }

    public static function forUser(mixed $user): self
    {
        return new self($user);
    }

    public function __call(string $method, array $arguments): mixed
    {
        $ability = (string) ($arguments[0] ?? '');
        $args = array_slice($arguments, 1);

        return match ($method) {
            'allows' => $this->check($ability, $args),
            'denies' => !$this->check($ability, $args),
            'authorize' => $this->check($ability, $args) ? null : throw new ForbiddenException(),
            default => throw new \BadMethodCallException("Method Gate::{$method} does not exist."),
        };
    }

    public static function __callStatic(string $method, array $arguments): mixed
    {
        $instance = new self();
        return $instance->__call($method, $arguments);
    }

    /**
     * @param list<mixed> $arguments
     */
    public function check(string $ability, array $arguments = []): bool
    {
        $user = $this->getUser();

        // 1. Run before callbacks (e.g. superadmin bypass)
        foreach (self::$beforeCallbacks as $before) {
            $result = $before($user, $ability, ...$arguments);
            if (is_bool($result)) {
                return $result;
            }
        }

        // 2. Check defined ability callback
        if (!isset(self::$abilities[$ability])) {
            return false;
        }

        $callback = self::$abilities[$ability];

        return (bool) $callback($user, ...$arguments);
    }

    public function allowsUser(string $ability, mixed ...$arguments): bool
    {
        return $this->check($ability, $arguments);
    }

    public function deniesUser(string $ability, mixed ...$arguments): bool
    {
        return !$this->check($ability, $arguments);
    }

    public function authorizeUser(string $ability, mixed ...$arguments): void
    {
        if (!$this->check($ability, $arguments)) {
            throw new ForbiddenException();
        }
    }

    public static function flush(): void
    {
        self::$abilities = [];
        self::$beforeCallbacks = [];
        self::$policies = [];
    }

    private function getUser(): mixed
    {
        if ($this->userExplicitlySet) {
            return $this->user;
        }

        if (class_exists(Application::class)) {
            try {
                $app = Application::current();
                if ($app->container()->has(Request::class)) {
                    return $app->container()->get(Request::class)->user();
                }
            } catch (Throwable) {
            }
        }

        return null;
    }
}
