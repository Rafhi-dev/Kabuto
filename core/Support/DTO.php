<?php

declare(strict_types=1);

namespace Kabuto\Support;

use Kabuto\Exceptions\ValidationException;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Minimal Data Transfer Object base with lightweight validation and
 * reflection-based hydration from request input.
 *
 * Subclasses declare rules via {@see rules()} using a pipe-separated syntax:
 *   'name'  => 'required|string|min:2|max:50',
 *   'email' => 'required|email',
 *   'age'   => 'nullable|int|min:0',
 *
 * Supported rules: required, nullable, string, int, integer, numeric, bool,
 * boolean, array, email, min:n, max:n.
 */
abstract readonly class DTO
{
    /**
     * @return array<string,string> field => pipe-separated rules
     */
    protected static function rules(): array
    {
        return [];
    }

    /**
     * Convert DTO properties to an associative array.
     *
     * @param bool $filterNull If true, excludes null properties (ideal for partial updates).
     * @return array<string,mixed>
     */
    public function toArray(bool $filterNull = false): array
    {
        $vars = get_object_vars($this);

        return $filterNull
            ? array_filter($vars, static fn (mixed $v): bool => $v !== null)
            : $vars;
    }

    /**
     * Validate the given input and hydrate a new instance.
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): static
    {
        $rules = static::rules();
        $errors = self::validate($data, $rules);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return static::hydrate($data, $rules);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,list<string>>
     */
    protected static function validate(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $ruleList = explode('|', $ruleString);
            $value = $data[$field] ?? null;
            $missing = !array_key_exists($field, $data) || $value === null || $value === '';

            if (in_array('required', $ruleList, true) && $missing) {
                $errors[$field][] = sprintf('The %s field is required.', $field);
                continue;
            }

            if ($missing) {
                continue;
            }

            foreach ($ruleList as $rule) {
                if ($rule === 'required' || $rule === 'nullable') {
                    continue;
                }

                $message = self::checkRule($field, $value, $rule, $ruleList);
                if ($message !== null) {
                    $errors[$field][] = $message;
                }
            }
        }

        return $errors;
    }

    /**
     * @param list<string> $ruleList
     */
    private static function checkRule(string $field, mixed $value, string $rule, array $ruleList = []): ?string
    {
        [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);

        return match ($name) {
            'string' => is_string($value) ? null : sprintf('The %s field must be a string.', $field),
            'int', 'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false
                ? null : sprintf('The %s field must be an integer.', $field),
            'numeric' => is_numeric($value) ? null : sprintf('The %s field must be numeric.', $field),
            'bool', 'boolean' => in_array($value, [true, false, 0, 1, '0', '1'], true)
                ? null : sprintf('The %s field must be a boolean.', $field),
            'array' => is_array($value) ? null : sprintf('The %s field must be an array.', $field),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false
                ? null : sprintf('The %s field must be a valid email address.', $field),
            'min' => self::checkLength($field, $value, (int) $param, true, $ruleList),
            'max' => self::checkLength($field, $value, (int) $param, false, $ruleList),
            'in' => self::checkIn($field, $value, (string) $param),
            default => null,
        };
    }

    private static function checkIn(string $field, mixed $value, string $param): ?string
    {
        $allowed = array_map('trim', explode(',', $param));

        if (!in_array((string) $value, $allowed, true)) {
            return sprintf('The %s field must be one of: %s.', $field, implode(', ', $allowed));
        }

        return null;
    }

    /**
     * @param list<string> $ruleList
     */
    private static function checkLength(string $field, mixed $value, int $limit, bool $isMin, array $ruleList = []): ?string
    {
        $isNumericField = in_array('int', $ruleList, true)
            || in_array('integer', $ruleList, true)
            || in_array('numeric', $ruleList, true);

        if ($isNumericField && is_numeric($value)) {
            $length = (float) $value;
        } elseif (is_numeric($value) && !is_string($value)) {
            $length = (float) $value;
        } elseif (is_array($value)) {
            $length = count($value);
        } else {
            $str = (string) $value;
            $length = function_exists('mb_strlen') ? mb_strlen($str) : strlen($str);
        }

        if ($isMin && $length < $limit) {
            return sprintf('The %s field must be at least %d.', $field, $limit);
        }
        if (!$isMin && $length > $limit) {
            return sprintf('The %s field must not be greater than %d.', $field, $limit);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function hydrate(array $data, array $rules): static
    {
        $reflection = new ReflectionClass(static::class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();
            $value = $data[$name] ?? null;
            $arguments[] = self::cast($value, $param->getType(), $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null);
        }

        return $reflection->newInstanceArgs($arguments);
    }

    private static function cast(mixed $value, ?\ReflectionType $type, mixed $default): mixed
    {
        if ($value === null) {
            return $default;
        }

        if (!$type instanceof ReflectionNamedType || !$type->isBuiltin()) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'string' => (string) $value,
            'array' => (array) $value,
            default => $value,
        };
    }
}
