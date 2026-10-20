<?php

declare(strict_types=1);

namespace Kabuto\Database;

/**
 * Entry point for schema definition files (`database/schema.php`).
 *
 * Supports Prisma-style declarative arrays, Prisma DSL strings,
 * and the fluent Schema::model() builder.
 */
final class Schema
{
    /**
     * Start a fluent model definition.
     */
    public static function model(string $name): ModelBuilder
    {
        return new ModelBuilder($name);
    }

    /**
     * Parse a native Prisma schema definition string (.prisma format).
     */
    public static function fromPrisma(string $dsl): SchemaDefinition
    {
        $datasources = [];
        $models = [];

        // Parse datasource blocks
        if (preg_match_all('/datasource\s+(\w+)\s*\{([^}]+)\}/s', $dsl, $dsMatches, PREG_SET_ORDER)) {
            foreach ($dsMatches as $match) {
                $dsName = $match[1];
                $body = $match[2];

                $provider = 'sqlite';
                if (preg_match('/provider\s*=\s*["\']([^"\']+)["\']/', $body, $pMatch)) {
                    $provider = $pMatch[1];
                }

                $url = '';
                if (preg_match('/url\s*=\s*(?:env\(["\']([^"\']+)["\']\)|["\']([^"\']+)["\'])/', $body, $uMatch)) {
                    $envVar = $uMatch[1] ?? '';
                    $rawUrl = $uMatch[2] ?? '';
                    $url = $envVar !== '' ? (string) (getenv($envVar) ?: '') : $rawUrl;
                }

                $datasources[$dsName] = [
                    'driver' => $provider,
                    'path' => $url,
                ];
            }
        }

        if ($datasources === []) {
            $datasources['db'] = [
                'driver' => 'sqlite',
                'path' => 'database/app.sqlite',
            ];
        }

        // Parse model blocks
        if (preg_match_all('/model\s+(\w+)\s*\{([^}]+)\}/s', $dsl, $modelMatches, PREG_SET_ORDER)) {
            foreach ($modelMatches as $match) {
                $modelName = $match[1];
                $body = trim($match[2]);

                $table = null;
                if (preg_match('/@@map\(["\']([^"\']+)["\']\)/', $body, $mapMatch)) {
                    $table = $mapMatch[1];
                }

                $fields = [];
                $relations = [];

                $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '//') || str_starts_with($line, '@@')) {
                        continue;
                    }

                    $tokens = preg_split('/\s+/', $line, 3) ?: [];
                    if (count($tokens) < 2) {
                        continue;
                    }

                    $fieldName = $tokens[0];
                    $fieldType = $tokens[1];
                    $attrs = $tokens[2] ?? '';
                    $fullSpec = trim($fieldType . ' ' . $attrs);

                    if (str_ends_with($fieldType, '[]')) {
                        $relations[$fieldName] = $fieldType;
                    } elseif (str_contains($attrs, '@relation')) {
                        $relations[$fieldName] = $fullSpec;
                    } else {
                        $fields[$fieldName] = $fullSpec;
                    }
                }

                $models[$modelName] = [
                    'table' => $table,
                    'fields' => $fields,
                    'relations' => $relations,
                ];
            }
        }

        return self::define([
            'datasources' => $datasources,
            'models' => $models,
        ]);
    }

    /**
     * @param array{
     *     datasource?:array<string,mixed>,
     *     datasources?:array<string,array<string,mixed>>,
     *     models?:array<string|int,mixed>
     * } $config
     */
    public static function define(array $config): SchemaDefinition
    {
        // Support singular 'datasource' (Prisma style)
        if (isset($config['datasource']) && !isset($config['datasources'])) {
            $config['datasources'] = ['db' => $config['datasource']];
        }

        $datasources = [];
        foreach ($config['datasources'] ?? [] as $name => $ds) {
            $driver = DriverCapabilities::normalize((string) ($ds['provider'] ?? $ds['driver'] ?? 'sqlite'));
            if (!in_array($driver, DriverCapabilities::DRIVERS, true)) {
                throw new DatabaseException(sprintf('Unsupported driver [%s] for datasource [%s].', $driver, $name));
            }

            $options = $ds;
            if (isset($options['url']) && is_string($options['url'])) {
                if (str_starts_with($options['url'], 'mysql://') || str_starts_with($options['url'], 'postgres://') || str_starts_with($options['url'], 'postgresql://')) {
                    $parts = parse_url($options['url']);
                    if ($parts !== false) {
                        $options['host'] ??= $parts['host'] ?? '127.0.0.1';
                        if (isset($parts['port'])) {
                            $options['port'] ??= $parts['port'];
                        }
                        if (isset($parts['user'])) {
                            $options['username'] ??= $parts['user'];
                        }
                        if (isset($parts['pass'])) {
                            $options['password'] ??= $parts['pass'];
                        }
                        if (isset($parts['path'])) {
                            $options['database'] ??= ltrim($parts['path'], '/');
                        }
                    }
                } elseif (!isset($options['path'])) {
                    $options['path'] = $options['url'];
                }
            }
            unset($options['driver'], $options['provider'], $options['schemas']);

            $datasources[$name] = new DatasourceDefinition(
                (string) $name,
                $driver,
                array_values((array) ($ds['schemas'] ?? [])),
                $options,
            );
        }

        if ($datasources === []) {
            throw new DatabaseException('Schema must define at least one datasource.');
        }

        $defaultDatasource = (string) array_key_first($datasources);

        $models = [];
        foreach ($config['models'] ?? [] as $key => $model) {
            if ($model instanceof FieldBuilder) {
                $model = $model->model();
            }
            if ($model instanceof ModelBuilder) {
                $model = $model->toArray();
                $name = $model['name'];
            } elseif (is_int($key) && is_array($model) && isset($model['name'])) {
                $name = (string) $model['name'];
            } else {
                $name = (string) $key;
            }

            $models[$name] = self::parseModel($name, $model, $datasources, $defaultDatasource);
        }

        // Validate relation targets and resolve inverse foreign keys after all models are known.
        foreach ($models as $modelName => $model) {
            $updatedRelations = [];
            foreach ($model->relations as $relName => $relation) {
                if (!isset($models[$relation->model])) {
                    throw new DatabaseException(sprintf(
                        'Model [%s] relation [%s] targets unknown model [%s].',
                        $model->name,
                        $relation->name,
                        $relation->model,
                    ));
                }

                if ($relation->isHasMany()) {
                    $targetModel = $models[$relation->model];
                    $resolvedKey = $relation->foreignKey;
                    foreach ($targetModel->relations as $targetRel) {
                        if ($targetRel->isBelongsTo() && $targetRel->model === $model->name) {
                            $resolvedKey = $targetRel->foreignKey;
                            break;
                        }
                    }
                    $relation = new RelationDefinition($relation->name, $relation->kind, $relation->model, $resolvedKey);
                }
                $updatedRelations[$relName] = $relation;
            }
            $models[$modelName] = new ModelDefinition(
                $model->name,
                $model->table,
                $model->schema,
                $model->datasource,
                $model->fields,
                $updatedRelations,
            );
        }

        return new SchemaDefinition($datasources, $models, $defaultDatasource);
    }

    /**
     * @param array<string,mixed>                $model
     * @param array<string,DatasourceDefinition> $datasources
     */
    private static function parseModel(
        string $name,
        array $model,
        array $datasources,
        string $defaultDatasource,
    ): ModelDefinition {
        $datasource = (string) ($model['datasource'] ?? $defaultDatasource);

        if (!isset($datasources[$datasource])) {
            throw new DatabaseException(sprintf('Model [%s] uses unknown datasource [%s].', $name, $datasource));
        }

        $ds = $datasources[$datasource];
        $schema = (string) ($model['schema'] ?? $ds->defaultSchema() ?? '');

        if ($schema !== '' && !$ds->serves($schema)) {
            throw new DatabaseException(sprintf(
                'Model [%s] uses schema [%s] which is not listed in datasource [%s] schemas.',
                $name,
                $schema,
                $datasource,
            ));
        }

        $fields = [];
        foreach ($model['fields'] ?? [] as $fieldName => $spec) {
            $fields[(string) $fieldName] = self::parseField((string) $fieldName, $spec);
        }

        $relations = [];
        foreach ($model['relations'] ?? [] as $relName => $spec) {
            $relations[(string) $relName] = self::parseRelation((string) $relName, $spec, $name);
        }

        return new ModelDefinition(
            $name,
            (string) ($model['table'] ?? self::snake(self::plural($name))),
            $schema,
            $datasource,
            $fields,
            $relations,
        );
    }

    private static function parseField(string $name, mixed $spec): FieldDefinition
    {
        if ($spec instanceof FieldDefinition) {
            return $spec;
        }

        if ($spec instanceof FieldBuilder) {
            return $spec->toDefinition();
        }

        if (is_array($spec)) {
            $type = (string) ($spec['type'] ?? 'string');
            [$base, $length, $params] = self::splitType($type);
            $flags = array_map('strval', (array) ($spec['flags'] ?? []));
            foreach (['nullable', 'unique', 'index', 'useCurrent'] as $flag) {
                if (($spec[$flag] ?? false) === true && !in_array($flag, $flags, true)) {
                    $flags[] = $flag;
                }
            }

            return new FieldDefinition(
                $name,
                $base,
                $length ?? (isset($spec['length']) ? (int) $spec['length'] : null),
                $flags,
                $params,
                $spec['default'] ?? null,
                array_key_exists('default', $spec),
            );
        }

        $specStr = trim((string) $spec);

        // Reject legacy compact format (e.g. 'increments', 'string:100', 'text|nullable', 'timestamp|useCurrent')
        if (
            str_contains($specStr, '|')
            || $specStr === 'increments'
            || (str_contains($specStr, ':') && !str_contains($specStr, '@'))
            || (preg_match('/^[a-z]/', $specStr) && !str_starts_with(strtolower($specStr), 'enum(') && !in_array(strtolower($specStr), ['json', 'text'], true) && !str_contains($specStr, '@'))
        ) {
            throw new DatabaseException(sprintf(
                'Field [%s] uses unsupported legacy schema format [%s]. Kabuto requires Prisma-style definitions, e.g. "Int @id @default(autoincrement())", "String(100)", or "String?".',
                $name,
                $specStr,
            ));
        }

        // Prisma DSL field format: e.g. "Int @id @default(autoincrement())", "String(150) @unique", "String?", "Enum(active,inactive) @default(active)"
        return self::parsePrismaField($name, $specStr);
    }

    /**
     * Parses Prisma-style field specification strings.
     * Examples:
     * - "Int @id @default(autoincrement())"
     * - "String(150) @unique"
     * - "String?"
     * - "DateTime @default(now())"
     * - "Boolean @default(false)"
     * - "Enum(active,inactive) @default(active)"
     */
    private static function parsePrismaField(string $name, string $spec): FieldDefinition
    {
        $tokens = preg_split('/\s+/', $spec) ?: [];
        $typeToken = array_shift($tokens) ?? 'String';

        $isNullable = str_ends_with($typeToken, '?');
        $rawType = rtrim($typeToken, '?');

        $params = [];
        if (preg_match('/^enum\(([^)]+)\)$/i', $rawType, $enumMatch)) {
            $params = array_map('trim', explode(',', $enumMatch[1]));
            $rawType = 'enum';
        }

        $length = null;
        if (preg_match('/\((\d+)\)/', $rawType, $lenMatch)) {
            $length = (int) $lenMatch[1];
            $rawType = (string) preg_replace('/\(.*/', '', $rawType);
        }

        $type = match (strtolower($rawType)) {
            'int', 'int32', 'int64' => 'integer',
            'string' => 'string',
            'datetime', 'timestamp' => 'timestamp',
            'boolean', 'bool' => 'boolean',
            'float' => 'float',
            'decimal' => 'decimal',
            'json' => 'json',
            'text' => 'text',
            'bigint' => 'biginteger',
            'enum' => 'enum',
            default => strtolower($rawType),
        };

        $flags = [];
        if ($isNullable) {
            $flags[] = 'nullable';
        }

        $default = null;
        $hasDefault = false;

        $remaining = implode(' ', $tokens);

        if (str_contains($remaining, '@id')) {
            if (str_contains($remaining, '@default(autoincrement())') || $type === 'integer') {
                $type = 'increments';
            }
        }

        if (str_contains($remaining, '@unique')) {
            $flags[] = 'unique';
        }

        if (str_contains($remaining, '@index')) {
            $flags[] = 'index';
        }

        if (str_contains($remaining, '@default(now())') || str_contains($remaining, '@updatedAt')) {
            $flags[] = 'useCurrent';
        } elseif (preg_match('/@default\(([^)]+)\)/', $remaining, $defMatch)) {
            $defVal = trim($defMatch[1], " \t\n\r\0\x0B\"'");
            $default = match ($defVal) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => is_numeric($defVal) ? (str_contains($defVal, '.') ? (float) $defVal : (int) $defVal) : $defVal,
            };
            $hasDefault = true;
        }

        return new FieldDefinition($name, $type, $length, array_values(array_unique($flags)), $params, $default, $hasDefault);
    }

    /**
     * @return array{0:string,1:int|null,2:list<string>}
     */
    private static function splitType(string $type): array
    {
        $params = [];
        $length = null;

        if (str_contains($type, ':')) {
            [$base, $rest] = explode(':', $type, 2);
            if (strtolower($base) === 'enum') {
                $params = array_map('trim', explode(',', $rest));
            } elseif (is_numeric($rest)) {
                $length = (int) $rest;
            }
        } else {
            $base = $type;
        }

        return [strtolower($base), $length, $params];
    }

    private static function parseRelation(string $name, mixed $spec, string $currentModel = ''): RelationDefinition
    {
        if ($spec instanceof RelationDefinition) {
            return $spec;
        }

        if (is_array($spec)) {
            $kind = (string) ($spec['kind'] ?? $spec['type'] ?? '');
            $model = (string) ($spec['model'] ?? '');
            $key = (string) ($spec['foreignKey'] ?? $spec['key'] ?? '');
            $references = (string) ($spec['references'] ?? 'id');
        } else {
            $specStr = trim((string) $spec);
            $references = 'id';

            // Prisma hasMany array: "Post[]"
            if (preg_match('/^([A-Za-z0-9_]+)\[\]$/', $specStr, $arrMatch)) {
                $kind = 'hasMany';
                $model = $arrMatch[1];
                $key = strtolower(self::snake($currentModel)) . '_id';
            } elseif (preg_match('/^([A-Za-z0-9_]+)\s+@relation\(fields:\s*\[([^\]]+)\],\s*references:\s*\[([^\]]+)\]\)/', $specStr, $relMatch)) {
                // Prisma belongsTo: "User @relation(fields: [author_id], references: [id])"
                $kind = 'belongsTo';
                $model = $relMatch[1];
                $key = trim($relMatch[2]);
                $references = trim($relMatch[3]);
            } else {
                throw new DatabaseException(sprintf(
                    'Invalid relation [%s]: [%s]. Expected Prisma format: "TargetModel[]" for hasMany, or "TargetModel @relation(fields: [fk], references: [id])" for belongsTo.',
                    $name,
                    $specStr,
                ));
            }
        }

        if (!in_array($kind, ['hasMany', 'belongsTo'], true) || $model === '' || $key === '') {
            throw new DatabaseException(sprintf(
                'Invalid relation [%s]. Expected "TargetModel[]" or "TargetModel @relation(fields: [fk], references: [id])".',
                $name,
            ));
        }

        return new RelationDefinition($name, $kind, $model, $key, $references);
    }

    public static function snake(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }

    public static function plural(string $value): string
    {
        if (preg_match('/(s|x|z|ch|sh)$/i', $value)) {
            return $value . 'es';
        }
        if (preg_match('/[^aeiou]y$/i', $value)) {
            return substr($value, 0, -1) . 'ies';
        }
        return $value . 's';
    }
}
