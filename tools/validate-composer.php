<?php

declare(strict_types=1);

$path = dirname(__DIR__) . '/composer.json';
$contents = file_get_contents($path);
if ($contents === false) {
    fwrite(STDERR, "Cannot read composer.json.\n");
    exit(1);
}

try {
    $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fwrite(STDERR, 'Invalid composer.json: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$errors = [];
foreach (['name', 'description', 'type', 'license', 'require', 'autoload'] as $required) {
    if (!array_key_exists($required, $manifest)) $errors[] = 'Missing required manifest key: ' . $required;
}
if (!isset($manifest['require']['php'])) $errors[] = 'Missing PHP version requirement.';
if (!isset($manifest['autoload']['psr-4']['Kabuto\\'])) $errors[] = 'Missing Kabuto PSR-4 autoload mapping.';
foreach (($manifest['autoload']['psr-4'] ?? []) as $namespace => $directories) {
    foreach ((array) $directories as $directory) {
        if (!is_dir(dirname(__DIR__) . '/' . $directory)) $errors[] = sprintf('PSR-4 path for [%s] does not exist: %s', $namespace, $directory);
    }
}
foreach (($manifest['bin'] ?? []) as $binary) {
    if (!is_file(dirname(__DIR__) . '/' . $binary)) $errors[] = 'Declared Composer binary does not exist: ' . $binary;
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Composer manifest structure valid.\n");
