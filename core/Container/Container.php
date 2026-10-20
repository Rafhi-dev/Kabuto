<?php

declare(strict_types=1);

namespace Kabuto\Container;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * A small PSR-11-inspired dependency injection container with constructor
 * autowiring. Concrete classes are resolved automatically; interfaces and
 * scalars must be bound explicitly.
 */
final class Container
{
    /** @var array<string, callable(self):mixed|null> null = autowire */
    private array $bindings = [];

    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, bool> */
    private array $shared = [];

    /** @var array<string, ReflectionClass<object>> */
    private array $reflectionCache = [];

    /** @var list<string> */
    private array $resolving = [];

    /**
     * Register a transient binding.
     *
     * @param string|class-string $id
     * @param callable(self):mixed|string|null $concrete
     */
    public function bind(string $id, callable|string|null $concrete = null): void
    {
        $this->bindings[$id] = $this->normalize($id, $concrete);
        unset($this->shared[$id], $this->instances[$id]);
    }

    /**
     * Register a shared (singleton) binding.
     *
     * @param string|class-string $id
     * @param callable(self):mixed|string|null $concrete
     */
    public function singleton(string $id, callable|string|null $concrete = null): void
    {
        $this->bindings[$id] = $this->normalize($id, $concrete);
        $this->shared[$id] = true;
        unset($this->instances[$id]);
    }

    /**
     * Register an already-built object.
     *
     * @param string|class-string $id
     */
    public function instance(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
        $this->shared[$id] = true;
    }

    public function bound(string $id): bool
    {
        return isset($this->instances[$id]) || array_key_exists($id, $this->bindings);
    }

    public function forgetInstance(string $id): void
    {
        unset($this->instances[$id]);
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id])
            || array_key_exists($id, $this->bindings)
            || $this->isAutowireable($id);
    }

    /**
     * @param array<string,bool> $seen
     */
    private function isAutowireable(string $class, array $seen = []): bool
    {
        if (isset($seen[$class]) || !class_exists($class)) {
            return false;
        }

        $seen[$class] = true;
        $reflection = $this->reflectionCache[$class] ??= new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            return false;
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return true;
        }

        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isDefaultValueAvailable()) {
                continue;
            }

            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $dep = $type->getName();
                if (isset($this->instances[$dep]) || array_key_exists($dep, $this->bindings) || $this->isAutowireable($dep, $seen)) {
                    continue;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * Resolve an entry from the container, autowiring concrete classes.
     *
     * @param string|class-string $id
     */
    public function get(string $id): mixed
    {
        if (in_array($id, $this->resolving, true)) {
            $cycle = array_merge($this->resolving, [$id]);
            throw new ContainerException('Circular dependency detected: ' . implode(' -> ', $cycle));
        }

        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        $this->resolving[] = $id;
        try {
            if (array_key_exists($id, $this->bindings)) {
                $binding = $this->bindings[$id];

                if ($binding === null) {
                    $value = $this->buildOrFail($id, $id);
                    if ($this->shared[$id] ?? false) $this->instances[$id] = $value;
                    return $value;
                }

                $value = $binding($this);
                if (($this->shared[$id] ?? false) && is_object($value)) $this->instances[$id] = $value;
                return $value;
            }

            if (class_exists($id)) return $this->build($id);
            throw new ContainerException(sprintf('Unable to resolve [%s] from the container.', $id));
        } finally {
            array_pop($this->resolving);
        }
    }

    /**
     * Statically inspect services without invoking factories or constructing
     * objects. Returns an empty list when all requested IDs are resolvable.
     *
     * @param list<string> $ids
     * @return list<string>
     */
    public function diagnose(array $ids): array
    {
        $errors = [];
        foreach (array_values(array_unique($ids)) as $id) {
            $this->inspect($id, [], $errors);
        }
        return array_values(array_unique($errors));
    }

    /** @return list<string> */
    public function bindingIds(): array
    {
        return array_values(array_unique(array_merge(array_keys($this->bindings), array_keys($this->instances))));
    }

    /** @param list<string> $chain @param list<string> $errors */
    private function inspect(string $id, array $chain, array &$errors): void
    {
        if (in_array($id, $chain, true)) {
            $errors[] = 'Circular dependency detected: ' . implode(' -> ', array_merge($chain, [$id]));
            return;
        }
        if (isset($this->instances[$id])) return;
        if (array_key_exists($id, $this->bindings)) {
            $binding = $this->bindings[$id];
            if ($binding === null) {
                $this->inspectClass($id, array_merge($chain, [$id]), $errors);
            }
            return;
        }
        if (class_exists($id)) {
            $this->inspectClass($id, array_merge($chain, [$id]), $errors);
            return;
        }
        $errors[] = sprintf('Unable to resolve [%s] from the container.', $id);
    }

    /** @param list<string> $chain @param list<string> $errors */
    private function inspectClass(string $class, array $chain, array &$errors): void
    {
        if (!class_exists($class)) {
            $errors[] = sprintf('Class [%s] not found.', $class);
            return;
        }
        $reflection = $this->reflectionCache[$class] ??= new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            $errors[] = sprintf('Class [%s] is not instantiable and has no instance binding.', $class);
            return;
        }
        $constructor = $reflection->getConstructor();
        if ($constructor === null) return;
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $this->inspect($type->getName(), $chain, $errors);
            } elseif (!$parameter->isDefaultValueAvailable()) {
                $errors[] = sprintf('Unable to autowire parameter [$%s] of [%s]. Bind it explicitly.', $parameter->getName(), $class);
            }
        }
    }

    private function buildOrFail(string $id, string $class): object
    {
        if (!class_exists($class)) {
            throw new ContainerException(sprintf('Unable to resolve [%s]: class [%s] not found.', $id, $class));
        }

        return $this->build($class);
    }

    /**
     * @param class-string $class
     */
    private function build(string $class): object
    {
        $reflection = $this->reflectionCache[$class] ??= new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException(sprintf('Class [%s] is not instantiable.', $class));
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return $reflection->newInstance();
        }

        $arguments = array_map(
            fn (ReflectionParameter $param): mixed => $this->resolveParameter($param, $class),
            $constructor->getParameters(),
        );

        return $reflection->newInstanceArgs($arguments);
    }

    private function resolveParameter(ReflectionParameter $param, string $context): mixed
    {
        $type = $param->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            /** @var class-string $name */
            $name = $type->getName();
            return $this->get($name);
        }

        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        throw new ContainerException(sprintf(
            'Unable to autowire parameter [$%s] of [%s]. Bind it explicitly.',
            $param->getName(),
            $context,
        ));
    }

    /**
     * @param callable(self):mixed|string|null $concrete
     * @return callable(self):mixed|null null means "autowire this class"
     */
    private function normalize(string $id, callable|string|null $concrete): ?callable
    {
        if ($concrete === null) {
            return null;
        }

        if (is_string($concrete)) {
            if ($concrete === $id && class_exists($id)) {
                return null;
            }
            return static fn (self $c): mixed => $c->get($concrete);
        }

        return $concrete instanceof Closure ? $concrete : Closure::fromCallable($concrete);
    }
}
