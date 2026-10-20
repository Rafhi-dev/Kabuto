<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\Console;
use Kabuto\Console\GeneratorCommand;
use Kabuto\Database\FieldDefinition;
use Kabuto\Database\ModelDefinition;

/**
 * Internal generator for schema-driven files. Extends the standard stub
 * placeholders with model metadata (pk, accessor, DTO params/rules).
 */
final class DbGeneratorCommand extends GeneratorCommand
{
    public function __construct(
        Console $console,
        array $arguments,
        private readonly string $stubName,
        private readonly string $explicitClass,
        string $module,
        private readonly ModelDefinition $definition,
        private readonly ?string $directory = null,
    ) {
        parent::__construct($console, $arguments);
        $this->module = $module;
        $this->className = $explicitClass;
    }

    public function name(): string
    {
        return 'make:db-generic';
    }

    public function description(): string
    {
        return 'Internal schema-driven generator';
    }

    protected function parseName(): void
    {
        // Class name and module are provided explicitly.
    }

    protected function targetDirectory(): string
    {
        return $this->directory ?? 'app/Modules/' . $this->module;
    }

    protected function stub(): string
    {
        return $this->stubName;
    }

    protected function render(): string
    {
        $stub = file_get_contents($this->stubPath());

        if ($stub === false) {
            throw new \RuntimeException(sprintf('Missing stub: %s', $this->stubPath()));
        }

        $replacements = [
            '{{ namespace }}' => $this->namespace(),
            '{{ class }}' => $this->className,
            '{{ module }}' => $this->module,
            '{{ moduleVar }}' => lcfirst($this->module),
            '{{ modulePlural }}' => self::plural($this->module),
            '{{ modulePluralVar }}' => lcfirst(self::plural($this->module)),
            '{{ route }}' => self::kebab(self::plural($this->module)),
            '{{ model }}' => $this->definition->name,
            '{{ modelVar }}' => lcfirst($this->definition->name),
            '{{ pk }}' => $this->definition->primaryKey(),
            '{{ params }}' => $this->renderParams(false),
            '{{ paramsAll }}' => $this->renderParams(true),
            '{{ rules }}' => $this->renderRules(),
            '{{ array }}' => $this->renderArray(),
            '{{ arrayCreate }}' => $this->renderArrayCreate(),
            '{{ fromRow }}' => $this->renderFromRow(),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $stub);
    }

    private function renderParams(bool $all): string
    {
        $lines = [];
        foreach ($this->createFields($all) as [$field, $type, $nullable, $default]) {
            $lines[] = sprintf(
                '        public %s%s $%s%s,',
                $nullable ? '?' : '',
                $type,
                $field->name,
                $default,
            );
        }
        return implode("\n", $lines);
    }

    private function renderRules(): string
    {
        $lines = [];
        foreach ($this->createFields(false) as [$field]) {
            $lines[] = sprintf("            '%s' => '%s',", $field->name, $this->ruleFor($field));
        }
        return implode("\n", $lines);
    }

    private function renderArray(): string
    {
        $lines = [];
        foreach ($this->definition->fields as $name => $field) {
            $value = '$this->' . $name;
            if ($this->isEnum($field)) {
                $value .= ' instanceof \\BackedEnum ? ' . $value . '->value : ' . $value;
            }
            $lines[] = sprintf("            '%s' => %s,", $name, $value);
        }
        return implode("\n", $lines);
    }

    private function renderArrayCreate(): string
    {
        $lines = [];
        foreach ($this->createFields(false) as [$field]) {
            $lines[] = sprintf("            '%s' => \$this->%s,", $field->name, $field->name);
        }
        return implode("\n", $lines);
    }

    private function renderFromRow(): string
    {
        $lines = [];
        foreach ($this->definition->fields as $name => $field) {
            $lines[] = sprintf('            %s,', $this->castForFromRow($field));
        }
        return implode("\n", $lines);
    }

    /**
     * Fields for the create DTO: everything except auto-increment PKs and
     * managed timestamps. With $all, every field is included (output DTO).
     *
     * @return list<array{0:FieldDefinition,1:string,2:bool,3:string}>
     */
    private function createFields(bool $all): array
    {
        $pk = $this->definition->primaryKey();
        $result = [];

        foreach ($this->definition->fields as $name => $field) {
            $type = strtolower($field->type);

            if (!$all) {
                if ($name === $pk && ($type === 'increments' || $field->has('auto'))) {
                    continue;
                }
                if (in_array($name, ['created_at', 'updated_at'], true) && $field->has('useCurrent')) {
                    continue;
                }
                // Auto timestamp columns with defaults are server-managed.
                if (in_array($type, ['timestamp', 'datetime'], true) && ($field->hasDefault || $field->has('useCurrent'))) {
                    continue;
                }
            }

            $result[] = [$field, $this->phpType($field), $this->isNullable($field), $this->phpDefault($field, $all)];
        }

        return $result;
    }

    private function phpType(FieldDefinition $field): string
    {
        return match (strtolower($field->type)) {
            'integer', 'int', 'biginteger', 'increments' => 'int',
            'float', 'decimal' => 'float',
            'boolean', 'bool' => 'bool',
            'json', 'array' => 'array',
            default => 'string',
        };
    }

    private function isNullable(FieldDefinition $field): bool
    {
        return $field->nullable() || $field->hasDefault;
    }

    private function phpDefault(FieldDefinition $field, bool $all): string
    {
        if ($this->isNullable($field)) {
            return ' = null';
        }

        if ($field->hasDefault && is_scalar($field->default)) {
            $type = $this->phpType($field);
            $default = $field->default;
            if ($type === 'string') {
                return " = '" . addslashes((string) $default) . "'";
            }
            if ($type === 'bool') {
                return $default ? ' = true' : ' = false';
            }
            if ($type === 'array') {
                return ' = []';
            }
            return ' = ' . $default;
        }

        // Non-nullable fields without defaults are always provided
        // positionally (fromRow supplies fallbacks), so no default needed.
        return '';
    }

    private function ruleFor(FieldDefinition $field): string
    {
        $rules = [];

        if (!$this->isNullable($field)) {
            $rules[] = 'required';
        }

        $rules[] = match (strtolower($field->type)) {
            'integer', 'int', 'biginteger', 'increments' => 'int',
            'float', 'decimal' => 'numeric',
            'boolean', 'bool' => 'bool',
            'json', 'array' => 'array',
            default => 'string',
        };

        if (strtolower($field->name) === 'email' || str_ends_with(strtolower($field->name), '_email')) {
            $rules[] = 'email';
        }

        if ($this->isEnum($field)) {
            $rules[] = 'in:' . implode(',', $field->params);
        }

        if ($field->length !== null && in_array(strtolower($field->type), ['string', 'uuid'], true)) {
            $rules[] = 'max:' . $field->length;
        }

        return implode('|', $rules);
    }

    private function isEnum(FieldDefinition $field): bool
    {
        return strtolower($field->type) === 'enum' && $field->params !== [];
    }

    private function castForFromRow(FieldDefinition $field): string
    {
        $raw = "\$row['" . $field->name . "']";

        if ($this->isNullable($field) && !$field->hasDefault) {
            return $raw . ' ?? null';
        }

        return match ($this->phpType($field)) {
            'int' => '(int) (' . $raw . ' ?? 0)',
            'float' => '(float) (' . $raw . ' ?? 0)',
            'bool' => '(bool) (' . $raw . ' ?? false)',
            'array' => '(array) (' . $raw . ' ?? [])',
            default => '(string) (' . $raw . " ?? '')",
        };
    }
}
