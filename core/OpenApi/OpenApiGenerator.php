<?php

declare(strict_types=1);

namespace Kabuto\OpenApi;

use Kabuto\Common\Auth;
use Kabuto\Common\Body;
use Kabuto\Common\HttpCode;
use Kabuto\Common\Param;
use Kabuto\Common\Query;
use Kabuto\Common\UploadedFile as UploadedFileAttribute;
use Kabuto\Routing\Route;
use Kabuto\Routing\Router;
use Kabuto\Support\DTO;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

final class OpenApiGenerator
{
    public function __construct(private readonly Router $router) {}

    /** @return array<string,mixed> */
    public function generate(string $title = 'Kabuto API', string $version = '1.0.0'): array
    {
        $document = [
            'openapi' => '3.0.3',
            'info' => ['title' => $title, 'version' => $version],
            'paths' => [],
            'components' => ['schemas' => [], 'securitySchemes' => [
                'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
            ]],
        ];

        foreach ($this->router->routes() as $route) {
            $path = $this->openApiPath($route->pattern());
            $method = strtolower($route->method());
            if ($method === '*') continue;
            [$operation, $schemas] = $this->operation($route);
            $document['paths'][$path][$method] = $operation;
            foreach ($schemas as $name => $schema) $document['components']['schemas'][$name] = $schema;
        }

        if ($document['components']['schemas'] === []) unset($document['components']['schemas']);
        return $document;
    }

    private function openApiPath(string $pattern): string
    {
        return (string) preg_replace('/:(\w+)/', '{$1}', $pattern);
    }

    /** @return array{0:array<string,mixed>,1:array<string,array<string,mixed>>} */
    private function operation(Route $route): array
    {
        $operation = [
            'operationId' => $this->operationId($route),
            'responses' => ['200' => ['description' => 'Successful response.']],
        ];
        $schemas = [];
        $reflection = $this->handlerReflection($route->handler());
        if ($reflection !== null) {
            $status = $reflection->getAttributes(HttpCode::class);
            if ($status !== []) {
                /** @var HttpCode $code */
                $code = $status[0]->newInstance();
                $operation['responses'] = [(string) $code->status => ['description' => 'Successful response.']];
            }
            $parameters = [];
            $requestBody = null;
            foreach ($reflection->getParameters() as $parameter) {
                $attribute = $this->attribute($parameter);
                if ($attribute instanceof Param || $attribute instanceof Query) {
                    $name = $attribute->name ?? $parameter->getName();
                    $in = $attribute instanceof Param ? 'path' : 'query';
                    $parameters[] = ['name' => $name, 'in' => $in, 'required' => $in === 'path' || !$parameter->isDefaultValueAvailable(), 'schema' => $this->typeSchema($parameter->getType())];
                } elseif ($attribute instanceof Body) {
                    $schema = $this->parameterSchema($parameter, $schemas);
                    $requestBody = ['required' => !$parameter->isDefaultValueAvailable(), 'content' => ['application/json' => ['schema' => $schema]]];
                } elseif ($attribute instanceof UploadedFileAttribute) {
                    $name = $attribute->name ?? $parameter->getName();
                    $requestBody = ['required' => $attribute->required, 'content' => ['multipart/form-data' => ['schema' => ['type' => 'object', 'properties' => [$name => ['type' => 'string', 'format' => 'binary']], 'required' => $attribute->required ? [$name] : []]]]];
                }
            }
            if ($parameters !== []) $operation['parameters'] = $parameters;
            if ($requestBody !== null) $operation['requestBody'] = $requestBody;
        }
        if ($this->isAuthenticated($route, $reflection)) $operation['security'] = [['bearerAuth' => []]];
        return [$operation, $schemas];
    }

    private function operationId(Route $route): string
    {
        $handler = $route->handler();
        $name = is_array($handler) && count($handler) === 2 ? (string) $handler[1] : 'handler';
        return strtolower($route->method()) . '_' . trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $route->pattern()), '_') . '_' . $name;
    }

    private function handlerReflection(mixed $handler): ?ReflectionMethod
    {
        if (is_array($handler) && count($handler) === 2 && is_object($handler[0])) return new ReflectionMethod($handler[0], (string) $handler[1]);
        if (is_array($handler) && count($handler) === 2 && is_string($handler[0]) && class_exists($handler[0])) return new ReflectionMethod($handler[0], (string) $handler[1]);
        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
            if (class_exists($class)) return new ReflectionMethod($class, $method);
        }
        return null;
    }

    private function attribute(ReflectionParameter $parameter): ?object
    {
        foreach ($parameter->getAttributes() as $attribute) {
            $instance = $attribute->newInstance();
            if ($instance instanceof Param || $instance instanceof Query || $instance instanceof Body || $instance instanceof UploadedFileAttribute) return $instance;
        }
        return null;
    }

    /** @param array<string,array<string,mixed>> $schemas */
    private function parameterSchema(ReflectionParameter $parameter, array &$schemas): array
    {
        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && is_subclass_of($type->getName(), DTO::class)) {
            $name = (new ReflectionClass($type->getName()))->getShortName();
            $schema = $this->dtoSchema($type->getName());
            $schemas[$name] = $schema;
            return ['$ref' => '#/components/schemas/' . $name];
        }
        return $this->typeSchema($type);
    }

    /** @return array<string,mixed> */
    private function dtoSchema(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $properties = [];
        $required = [];
        $constructor = $reflection->getConstructor();
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $properties[$parameter->getName()] = ['type' => $this->typeSchema($parameter->getType())['type'] ?? 'object'];
            if (!$parameter->isDefaultValueAvailable() && !$parameter->allowsNull()) $required[] = $parameter->getName();
        }
        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) $schema['required'] = $required;
        return $schema;
    }

    private function typeSchema(?\ReflectionType $type): array
    {
        if (!$type instanceof ReflectionNamedType) return ['type' => 'object'];
        return match ($type->getName()) {
            'int' => ['type' => 'integer'], 'float' => ['type' => 'number'], 'bool' => ['type' => 'boolean'], 'string' => ['type' => 'string'], 'array' => ['type' => 'array', 'items' => ['type' => 'object']], default => ['type' => $type->isBuiltin() ? 'object' : 'object'],
        };
    }

    private function isAuthenticated(Route $route, ?ReflectionMethod $method): bool
    {
        foreach ($route->middleware() as $middleware) if (is_string($middleware) && str_ends_with($middleware, 'JwtAuth')) return true;
        return $method?->getAttributes(Auth::class) !== [];
    }
}
