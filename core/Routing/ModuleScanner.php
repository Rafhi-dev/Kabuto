<?php

declare(strict_types=1);

namespace Kabuto\Routing;

use Kabuto\Common\Controller;
use Kabuto\Common\Module;
use Kabuto\Container\Container;
use ReflectionClass;

/**
 * Registers NestJS-style modules: resolves imports recursively, registers
 * providers in the container and controller routes on the router.
 *
 *   $scanner->register(AppModule::class);
 */
final class ModuleScanner
{
    /** @var list<class-string> */
    private array $registered = [];

    /** @var list<class-string> */
    private array $resolving = [];

    public function __construct(
        private readonly Router $router,
        private readonly Container $container,
    ) {
    }

    /**
     * Register a module and everything it imports.
     *
     * @param class-string $module
     */
    public function register(string $module): void
    {
        if (in_array($module, $this->registered, true)) {
            return;
        }

        if (in_array($module, $this->resolving, true)) {
            throw new \LogicException(sprintf(
                'Circular module import detected: %s -> %s. Break the cycle by moving the shared provider into its own module.',
                implode(' -> ', [...$this->resolving, $module]),
                $module,
            ));
        }

        $definition = $this->definition($module);
        $this->resolving[] = $module;

        foreach ($definition->imports as $import) {
            $this->register($import);
        }

        foreach ($definition->providers as $provider) {
            $this->registerProvider($module, $provider);
        }

        $routes = new RouteScanner($this->router);

        foreach ($definition->controllers as $controller) {
            $this->registerController($module, $controller, $routes);
        }

        array_pop($this->resolving);
        $this->registered[] = $module;
    }

    /**
     * @return list<class-string>
     */
    public function registered(): array
    {
        return $this->registered;
    }

    /**
     * @param class-string $module
     */
    private function definition(string $module): Module
    {
        if (!class_exists($module)) {
            throw new \LogicException(sprintf(
                'Module [%s] does not exist. Check the class name and its namespace.',
                $module,
            ));
        }

        $reflection = new ReflectionClass($module);
        $attributes = $reflection->getAttributes(Module::class);

        if ($attributes === []) {
            throw new \LogicException(sprintf(
                'Class [%s] is not a module. Add #[Module(...)] above the class declaration.',
                $module,
            ));
        }

        /** @var Module $definition */
        $definition = $attributes[0]->newInstance();

        return $definition;
    }

    /**
     * @param class-string $module
     * @param class-string $provider
     */
    private function registerProvider(string $module, string $provider): void
    {
        if (!class_exists($provider)) {
            throw new \LogicException(sprintf(
                'Provider [%s] listed in module [%s] does not exist.',
                $provider,
                $module,
            ));
        }

        if (!$this->container->has($provider)) {
            $this->container->singleton($provider);
        }
    }

    /**
     * @param class-string $module
     * @param class-string $controller
     */
    private function registerController(string $module, string $controller, RouteScanner $routes): void
    {
        if (!class_exists($controller)) {
            throw new \LogicException(sprintf(
                'Controller [%s] listed in module [%s] does not exist.',
                $controller,
                $module,
            ));
        }

        $reflection = new ReflectionClass($controller);

        if ($reflection->getAttributes(Controller::class) === []) {
            throw new \LogicException(sprintf(
                'Class [%s] listed as controller in module [%s] is missing #[Controller(...)].',
                $controller,
                $module,
            ));
        }

        $routes->register($controller);
    }
}
