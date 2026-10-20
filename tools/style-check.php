<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$directories = ['core', 'tests', 'config', 'app', 'database', 'public'];
$violations = [];

foreach ($directories as $directory) {
    $path = $root . DIRECTORY_SEPARATOR . $directory;
    if (!is_dir($path)) continue;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') continue;
        $lines = file($file->getPathname());
        if ($lines === false) continue;
        foreach ($lines as $number => $line) {
            if (preg_match('/[ \t]+(?:\r?\n)?$/', $line) === 1) $violations[] = $file->getPathname() . ':' . ($number + 1) . ': trailing whitespace';
        }
    }
}

if ($violations !== []) {
    fwrite(STDERR, implode(PHP_EOL, $violations) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "PHP style whitespace check passed.\n");
