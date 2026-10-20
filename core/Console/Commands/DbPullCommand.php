<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

/**
 * Introspects a live datasource and rewrites database/schema.php from it.
 * belongsTo relations are inferred from foreign-key constraints; other
 * relations are preserved from the existing file when table + model names
 * still match.
 */
final class DbPullCommand extends DatabaseCommand
{
    public function name(): string
    {
        return 'db:pull';
    }

    public function description(): string
    {
        return 'Rebuild database/schema.php models from a live database';
    }

    public function handle(): int
    {
        $pulled = $this->migrator->pull($this->datasource);

        if ($pulled === []) {
            $this->info(sprintf('Datasource [%s] has no tables to pull.', $this->datasource));
            return 0;
        }

        $path = $this->console->basePath() . '/database/schema.php';

        if (is_file($path) && !$this->flag('force')) {
            $this->error('database/schema.php already exists (use --force to overwrite).');
            return 1;
        }

        // Preserve relations whose model + table still exist.
        $previous = [];
        foreach ($this->schema->models as $model) {
            $previous[$model->name] = $model;
        }

        $modelsCode = [];
        foreach ($pulled as $name => $model) {
            $table = $model['table'];
            $relations = '';

            if (($model['relations'] ?? []) !== []) {
                $relations = $this->renderPulledRelations($model['relations']);
            } elseif (isset($previous[$name]) && $previous[$name]->table === $table && $previous[$name]->relations !== []) {
                $relations = $this->renderRelations($previous[$name]->relations);
            }

            $modelsCode[] = $this->renderModel(
                $name,
                $table,
                $model['fields'],
                $model['schema'] ?? '',
                $this->datasource,
                $relations,
            );
        }

        $content = $this->renderFile($modelsCode);
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, $content);
        $this->success(sprintf('Pulled %d model(s) from [%s] into database/schema.php.', count($pulled), $this->datasource));

        return 0;
    }

    /**
     * @param array<string,RelationDefinition> $relations
     */
    private function renderRelations(array $relations): string
    {
        $lines = [];
        foreach ($relations as $rel) {
            if ($rel->kind === 'hasMany') {
                $lines[] = sprintf("                    '%s' => '%s[]',", $rel->name, $rel->model);
            } else {
                $lines[] = sprintf(
                    "                    '%s' => '%s @relation(fields: [%s], references: [id])',",
                    $rel->name,
                    $rel->model,
                    $rel->foreignKey,
                );
            }
        }

        return "\n                'relations' => [\n" . implode("\n", $lines) . "\n                ],";
    }

    /**
     * @param array<string,array{model:string,foreignKey:string,references:string}> $relations
     */
    private function renderPulledRelations(array $relations): string
    {
        $lines = [];
        foreach ($relations as $name => $rel) {
            $lines[] = sprintf(
                "                    '%s' => '%s @relation(fields: [%s], references: [%s])',",
                $name,
                $rel['model'],
                $rel['foreignKey'],
                $rel['references'],
            );
        }

        return "\n                'relations' => [\n" . implode("\n", $lines) . "\n                ],";
    }

    /**
     * @param array<string,string> $fields
     */
    private function renderModel(string $name, string $table, array $fields, string $schema, string $datasource, string $relations): string
    {
        $lines = [];
        foreach ($fields as $field => $spec) {
            $lines[] = sprintf("                    '%s' => '%s',", $field, addslashes($spec));
        }

        $meta = sprintf("\n            'table' => '%s',", addslashes($table));
        if ($schema !== '') {
            $meta .= sprintf("\n            'schema' => '%s',", addslashes($schema));
        }
        if ($datasource !== ($this->schema->defaultDatasource ?? '')) {
            $meta .= sprintf("\n            'datasource' => '%s',", addslashes($datasource));
        }

        return sprintf(
            "        '%s' => [%s\n            'fields' => [\n%s\n            ],%s\n        ],",
            $name,
            $meta,
            implode("\n", $lines),
            $relations,
        );
    }

    /**
     * @param list<string> $modelsCode
     */
    private function renderFile(array $modelsCode): string
    {
        $dsCode = [];
        foreach ($this->schema->datasources as $ds) {
            $schemas = implode(', ', array_map(static fn (string $s): string => "'" . addslashes($s) . "'", $ds->schemas));
            $dsCode[] = sprintf(
                "        '%s' => [\n            'driver' => '%s',\n            'schemas' => [%s],\n        ],",
                addslashes($ds->name),
                addslashes($ds->driver),
                $schemas,
            );
        }

        $template = <<<'PHP'
            <?php

            declare(strict_types=1);

            use Kabuto\Database\Schema;

            // Generated by `php bin/console db:pull`. belongsTo relations are
            // inferred from foreign keys; hasMany relations are preserved from
            // the previous file when model + table names still match.
            // Connection options live in config/database.php; only the driver
            // and schema list are written here.
            return Schema::define([
                'datasources' => [
            {{DS}}
                ],
                'models' => [
            {{MODELS}}
                ],
            ]);

            PHP;

        return str_replace(
            ['{{DS}}', '{{MODELS}}'],
            [implode("\n", $dsCode), implode("\n", $modelsCode)],
            $template,
        );
    }
}
