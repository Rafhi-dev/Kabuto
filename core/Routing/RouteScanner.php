<?php

declare(strict_types=1);

namespace Kabuto\Routing;

use Kabuto\Common\Auth;
use Kabuto\Common\Can;
use Kabuto\Common\PublicRoute;
use Kabuto\Common\UseExceptionFilters;
use Kabuto\Common\Controller;
use Kabuto\Common\Permissions;
use Kabuto\Common\Roles;
use Kabuto\Common\RouteMethod;
use Kabuto\Middleware\Authorize;
use Kabuto\Middleware\JwtAuth;
use ReflectionClass;
use ReflectionMethod;

/**
 * Discovers controllers annotated with #[Controller] and registers their
 * method-level route attributes on the router.
 *
 * Only annotated classes are considered, keeping explicit route files as a
 * secondary, fully supported mechanism.
 */
final class RouteScanner
{
    public function __construct(private readonly Router $router)
    {
    }

    /**
     * Find annotated controller classes under a directory.
     *
     * @return list<class-string>
     */
    public function discover(string $directory, string $namespace): array
    {
        $directory = rtrim($directory, '/\\');
        $namespace = trim($namespace, '\\');
        $classes = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            if (!str_ends_with($file->getBasename('.php'), 'Controller')) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($directory) + 1);
            $relative = str_replace(['/', '\\'], '\\', $relative);
            $class = $namespace . '\\' . substr($relative, 0, -4);

            if (class_exists($class) && $this->isController($class)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    /**
     * @param class-string $class
     */
    public function isController(string $class): bool
    {
        $reflection = new ReflectionClass($class);

        return $reflection->getAttributes(Controller::class) !== [];
    }

    /**
     * Register every route declared by the given controller class.
     *
     * @param class-string $class
     */
    public function register(string $class): void
    {
        $reflection = new ReflectionClass($class);
        $classAttributes = $reflection->getAttributes(Controller::class);

        if ($classAttributes === []) {
            return;
        }

        /** @var Controller $controller */
        $controller = $classAttributes[0]->newInstance();
        $prefix = trim($controller->path, '/');

        $classAuthAttributes = $reflection->getAttributes(Auth::class);
        $classAuth = $classAuthAttributes !== [] ? $classAuthAttributes[0]->newInstance() : null;

        $classRolesAttributes = $reflection->getAttributes(Roles::class);
        $classRoles = $classRolesAttributes !== [] ? $classRolesAttributes[0]->newInstance() : null;

        $classPermissionsAttributes = $reflection->getAttributes(Permissions::class);
        $classPermissions = $classPermissionsAttributes !== [] ? $classPermissionsAttributes[0]->newInstance() : null;
        $classCanAttributes = $reflection->getAttributes(Can::class);
        $classCan = $classCanAttributes !== [] ? $classCanAttributes[0]->newInstance() : null;
        $classPublic = $reflection->getAttributes(PublicRoute::class) !== [];
        $classFilterAttributes = $reflection->getAttributes(UseExceptionFilters::class);
        $classFilters = $classFilterAttributes !== [] ? $classFilterAttributes[0]->newInstance()->filters : [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor()) {
                continue;
            }

            foreach ($method->getAttributes() as $attribute) {
                $instance = $attribute->newInstance();

                if (!$instance instanceof RouteMethod) {
                    continue;
                }

                $path = $prefix === '' ? $instance->path : rtrim($prefix . '/' . ltrim($instance->path, '/'), '/');
                $middleware = array_merge($controller->middleware, $instance->middleware);

                $methodAuthAttributes = $method->getAttributes(Auth::class);
                $methodAuth = $methodAuthAttributes !== [] ? $methodAuthAttributes[0]->newInstance() : null;
                $auth = $methodAuth ?? $classAuth;

                $methodRolesAttributes = $method->getAttributes(Roles::class);
                $methodRoles = $methodRolesAttributes !== [] ? $methodRolesAttributes[0]->newInstance() : null;
                $roles = $methodRoles ?? $classRoles;

                $methodPermissionsAttributes = $method->getAttributes(Permissions::class);
                $methodPermissions = $methodPermissionsAttributes !== [] ? $methodPermissionsAttributes[0]->newInstance() : null;
                $permissions = $methodPermissions ?? $classPermissions;
                $methodCanAttributes = $method->getAttributes(Can::class);
                $can = $methodCanAttributes !== [] ? $methodCanAttributes[0]->newInstance() : $classCan;
                $public = $method->getAttributes(PublicRoute::class) !== [] || $classPublic;
                $methodFilterAttributes = $method->getAttributes(UseExceptionFilters::class);
                $methodFilters = $methodFilterAttributes !== [] ? $methodFilterAttributes[0]->newInstance()->filters : [];
                $filters = array_values(array_unique(array_merge($methodFilters, $classFilters)));

                // When roles or permissions are required, authentication is implicitly required
                if ($roles !== null || $permissions !== null || $can !== null) {
                    $auth ??= new Auth();
                }
                $metadata = [];
                $metadata['protected'] = $auth !== null || $roles !== null || $permissions !== null || $can !== null;
                $metadata['public'] = $public;
                $metadata['exception_filters'] = $filters;
                if ($can !== null) {
                    $metadata['authorization'] = ['ability' => $can->ability, 'model' => $can->model, 'parameter' => $can->parameter];
                }

                if ($auth !== null) {
                    $guard = $auth->guard ?? JwtAuth::class;
                    if (!in_array($guard, $middleware, true)) {
                        $middleware[] = $guard;
                    }
                }

                if ($roles !== null || $permissions !== null) {
                    $middleware[] = new Authorize(
                        roles: $roles?->roles() ?? [],
                        permissions: $permissions?->permissions() ?? [],
                    );
                }
                if ($can !== null) {
                    $middleware[] = new \Kabuto\Middleware\PolicyAuthorize($can->ability, $can->model, $can->parameter);
                }

                $pattern = $path === '' ? '/' : '/' . ltrim($path, '/');

                if ($this->router->has($instance->method, $pattern)) {
                    continue;
                }

                $this->router->add(
                    $instance->method,
                    $pattern,
                    [$class, $method->getName()],
                    $middleware,
                    $metadata,
                );
            }
        }
    }
}
