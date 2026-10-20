<?php

declare(strict_types=1);

namespace Kabuto\Http;

use Closure;
use Kabuto\Common\Body;
use Kabuto\Common\Cookie;
use Kabuto\Common\HttpCode;
use Kabuto\Common\Param;
use Kabuto\Common\Query;
use Kabuto\Common\Req;
use Kabuto\Common\Res;
use Kabuto\Common\UploadedFile as CommonUploadedFile;
use Kabuto\Common\User;
use Kabuto\Container\Container;
use Kabuto\Container\ContainerException;
use Kabuto\Exceptions\ValidationException;
use Kabuto\Support\DTO;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Turns a route handler (closure, "Class@method" string, invokable class or
 * [object, method] pair) into a callable that receives (Request, Response).
 *
 * Controller dependencies come from the container. Method arguments are
 * resolved in order:
 *
 *   1. NestJS-style parameter attributes (Param, Query, Body, Req, Res).
 *   2. Request / Response type-hints (explicit escape hatch).
 *   3. Any other class type-hint, resolved from the container.
 *   4. PHP default values.
 */
final class HandlerResolver
{
    public function __construct(private readonly Container $container)
    {
    }

    public function resolve(mixed $handler): callable
    {
        if ($handler instanceof Closure) {
            return $this->hasParameterDecorators(new ReflectionFunction($handler))
                ? $this->invoke(null, null, new ReflectionFunction($handler), null)
                : $handler;
        }

        if (is_string($handler)) {
            if (str_contains($handler, '@')) {
                [$class, $method] = explode('@', $handler, 2);
                return $this->method($this->container->get($class), $method);
            }

            if (class_exists($handler)) {
                $instance = $this->container->get($handler);
                if (is_callable($instance)) {
                    return $instance;
                }
                if (method_exists($instance, 'handle')) {
                    return $this->method($instance, 'handle');
                }
            }
        }

        if (is_array($handler) && count($handler) === 2) {
            [$target, $method] = $handler;
            $instance = is_string($target) ? $this->container->get($target) : $target;
            return $this->method($instance, $method);
        }

        if (is_callable($handler)) {
            return $handler;
        }

        throw new ContainerException(sprintf('Unresolvable route handler of type [%s].', get_debug_type($handler)));
    }

    private function method(object $instance, string $method): Closure
    {
        if (!method_exists($instance, $method)) {
            throw new ContainerException(sprintf(
                'Method [%s::%s] does not exist.',
                $instance::class,
                $method,
            ));
        }

        $reflection = new ReflectionMethod($instance, $method);
        $httpCode = $this->successStatus($reflection);

        return $this->invoke($instance, $method, $reflection, $httpCode);
    }

    /**
     * @param ReflectionFunctionAbstract $reflection
     */
    private function invoke(?object $instance, ?string $method, object $reflection, ?int $httpCode): Closure
    {
        return function (Request $request, Response $response) use ($instance, $method, $reflection, $httpCode): mixed {
            $arguments = array_map(
                fn (ReflectionParameter $param): mixed => $this->argument($param, $request, $response),
                $reflection->getParameters(),
            );

            $result = $instance !== null && $method !== null
                ? $instance->{$method}(...$arguments)
                : ($reflection->getClosure())(...$arguments);

            if ($httpCode !== null && !$result instanceof Response) {
                $response->status($httpCode);
            }

            return $result;
        };
    }

    /**
     * @param ReflectionFunctionAbstract $reflection
     */
    private function hasParameterDecorators(object $reflection): bool
    {
        foreach ($reflection->getParameters() as $param) {
            if ($this->parameterDecorator($param) !== null) {
                return true;
            }
        }

        return false;
    }

    private function successStatus(ReflectionMethod $method): ?int
    {
        $attributes = $method->getAttributes(HttpCode::class);

        if ($attributes === []) {
            return null;
        }

        /** @var HttpCode $httpCode */
        $httpCode = $attributes[0]->newInstance();

        return $httpCode->status;
    }

    private function argument(ReflectionParameter $param, Request $request, Response $response): mixed
    {
        $decorator = $this->parameterDecorator($param);

        if ($decorator instanceof Param) {
            return $this->cast($this->routeValue($request, $decorator->name ?? $param->getName(), $param), $param);
        }

        if ($decorator instanceof Query) {
            if ($decorator->name === null && $this->allowsArray($param)) {
                return $request->query();
            }
            return $this->cast($this->queryValue($request, $decorator->name ?? $param->getName(), $param), $param);
        }

        if ($decorator instanceof Body) {
            return $this->body($param, $request, $decorator->name);
        }

        if ($decorator instanceof Req) {
            return $request;
        }

        if ($decorator instanceof Res) {
            return $response;
        }

        if ($decorator instanceof Cookie) {
            $name = $decorator->name ?? $param->getName();
            $val = $request->cookie($name, $decorator->default, $decorator->decrypt);
            return $this->cast($val ?? $this->fallback($param), $param);
        }

        if ($decorator instanceof User) {
            $user = $request->user();
            if ($decorator->property !== null) {
                if (is_array($user)) {
                    $val = $user[$decorator->property] ?? $decorator->default;
                } elseif (is_object($user)) {
                    $val = $user->{$decorator->property} ?? $decorator->default;
                } else {
                    $val = $decorator->default;
                }
                return $this->cast($val ?? $this->fallback($param), $param);
            }
            return $this->cast($user ?? $this->fallback($param), $param);
        }

        if ($decorator instanceof CommonUploadedFile) {
            return $this->uploadedFile($param, $request, $decorator);
        }

        return $this->legacyArgument($param, $request, $response);
    }

    private function parameterDecorator(ReflectionParameter $param): ?object
    {
        foreach ($param->getAttributes() as $attribute) {
            $instance = $attribute->newInstance();

            if ($instance instanceof Param
                || $instance instanceof Query
                || $instance instanceof Body
                || $instance instanceof Req
                || $instance instanceof Res
                || $instance instanceof Cookie
                || $instance instanceof User
                || $instance instanceof CommonUploadedFile
            ) {
                return $instance;
            }
        }

        return null;
    }

    private function routeValue(Request $request, string $name, ReflectionParameter $param): mixed
    {
        $value = $request->param($name);

        if ($value === null && !$param->isDefaultValueAvailable() && !$param->allowsNull()) {
            throw new ContainerException(sprintf(
                'Missing route parameter [%s] for [%s].',
                $name,
                $this->owner($param),
            ));
        }

        return $value ?? $this->fallback($param);
    }

    private function queryValue(Request $request, string $name, ReflectionParameter $param): mixed
    {
        $value = $request->query($name);

        return $value ?? $this->fallback($param);
    }

    /**
     * @return array<string,mixed>|DTO|mixed
     */
    private function body(ReflectionParameter $param, Request $request, ?string $name): mixed
    {
        if ($name !== null) {
            return $this->cast($request->input($name) ?? $this->fallback($param), $param);
        }

        $type = $param->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $class = $type->getName();

            if (is_subclass_of($class, DTO::class)) {
                return $class::fromArray($request->body());
            }

            if ($this->container->has($class)) {
                return $this->container->get($class);
            }
        }

        if ($this->allowsArray($param)) {
            return $request->body();
        }

        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        throw new ContainerException(sprintf(
            'Unable to resolve #[Body()] for [$%s] of [%s]. Type it as array or a DTO subclass.',
            $param->getName(),
            $this->owner($param),
        ));
    }

    /**
     * Resolution order before parameter decorators existed. Kept so existing
     * handlers written against (Request, Response) keep working.
     */
    private function legacyArgument(ReflectionParameter $param, Request $request, Response $response): mixed
    {
        $type = $param->getType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : null;

        if ($name === Request::class) {
            return $request;
        }
        if ($name === Response::class) {
            return $response;
        }
        if ($name === UploadedFile::class) {
            return $request->file($param->getName()) ?? $this->fallback($param);
        }
        if ($name !== null && $type !== null && !$type->isBuiltin() && $this->container->has($name)) {
            return $this->container->get($name);
        }
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        throw new ContainerException(sprintf(
            'Unable to resolve parameter [$%s] of method [%s]. Add a #[Param()], #[Query()] or #[Body()] attribute, a default value, or register [%s] in the container.',
            $param->getName(),
            $this->owner($param),
            $name ?? 'the type-hint',
        ));
    }

    private function fallback(ReflectionParameter $param): mixed
    {
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        if ($param->allowsNull()) {
            return null;
        }

        return null;
    }

    private function allowsArray(ReflectionParameter $param): bool
    {
        $type = $param->getType();

        return $type instanceof ReflectionNamedType
            && $type->isBuiltin()
            && $type->getName() === 'array';
    }

    private function cast(mixed $value, ReflectionParameter $param): mixed
    {
        if ($value === null) {
            return $this->fallback($param);
        }

        $type = $param->getType();

        if (!$type instanceof ReflectionNamedType || !$type->isBuiltin()) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => $this->toInt($value, $param),
            'float' => $this->toFloat($value, $param),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $this->fallback($param),
            'string' => (string) $value,
            'array' => (array) $value,
            default => $value,
        };
    }

    private function toInt(mixed $value, ReflectionParameter $param): mixed
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new ContainerException(sprintf(
                'Route value [%s] is not a valid integer for [$%s] of [%s].',
                (string) $value,
                $param->getName(),
                $this->owner($param),
            ));
        }

        return (int) $value;
    }

    private function toFloat(mixed $value, ReflectionParameter $param): mixed
    {
        if (!is_numeric($value)) {
            throw new ContainerException(sprintf(
                'Route value [%s] is not a valid number for [$%s] of [%s].',
                (string) $value,
                $param->getName(),
                $this->owner($param),
            ));
        }

        return (float) $value;
    }

    private function owner(ReflectionParameter $param): string
    {
        return $param->getDeclaringClass()?->getName() . '::' . $param->getDeclaringFunction()->getName();
    }

    private function uploadedFile(ReflectionParameter $param, Request $request, CommonUploadedFile $decorator): ?UploadedFile
    {
        $name = $decorator->name ?? $param->getName();
        $file = $request->file($name);

        if ($file === null || !$file->isValid()) {
            if ($decorator->required && !$param->isDefaultValueAvailable() && !$param->allowsNull()) {
                throw new ValidationException([$name => ["The {$name} file is required."]]);
            }

            return $this->fallback($param);
        }

        try {
            UploadFileInspector::validate($file);
        } catch (\RuntimeException $exception) {
            throw new ValidationException([$name => [$exception->getMessage()]]);
        }

        // Validate maxSize
        if ($decorator->maxSize !== null) {
            $maxBytes = $this->parseSize($decorator->maxSize);
            if ($file->size() > $maxBytes) {
                throw new ValidationException([$name => ["The {$name} file must not be greater than {$decorator->maxSize}."]]);
            }
        }

        // Validate mimes (MIME types or extensions)
        if ($decorator->mimes !== []) {
            $matched = false;
            $fileMime = strtolower($file->mime());
            $fileExt = strtolower($file->extension());

            foreach ($decorator->mimes as $allowed) {
                $allowed = strtolower(trim($allowed));
                if ($allowed === $fileMime || $allowed === $fileExt || str_ends_with($fileMime, '/' . $allowed)) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                throw new ValidationException([$name => ["The {$name} file must be of type: " . implode(', ', $decorator->mimes) . "."]]);
            }
        }

        return $file;
    }

    private function parseSize(string $size): int
    {
        $units = ['B' => 1, 'KB' => 1024, 'MB' => 1048576, 'GB' => 1073741824];
        $pattern = '/^(\d+(?:\.\d+)?)\s*(B|KB|MB|GB|K|M|G)?$/i';

        if (preg_match($pattern, trim($size), $matches)) {
            $value = (float) $matches[1];
            $unit = strtoupper($matches[2] ?? 'B');
            if ($unit === 'K') {
                $unit = 'KB';
            }
            if ($unit === 'M') {
                $unit = 'MB';
            }
            if ($unit === 'G') {
                $unit = 'GB';
            }

            return (int) round($value * ($units[$unit] ?? 1));
        }

        throw new ContainerException(sprintf(
            'Invalid upload maxSize [%s]. Use a value such as 500KB, 2MB, or 1GB.',
            $size,
        ));
    }
}
