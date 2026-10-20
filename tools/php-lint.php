<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$directories = ['core', 'tests', 'config', 'app', 'database', 'public'];
$files = [];

foreach ($directories as $directory) {
    $path = $root . DIRECTORY_SEPARATOR . $directory;
    if (!is_dir($path)) continue;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') $files[] = $file->getPathname();
    }
}

sort($files);
$failed = 0;
foreach ($files as $file) {
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file), $output, $status);
    if ($status !== 0) {
        fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);
        $failed++;
    }
    $output = [];
}

if ($failed > 0) {
    fwrite(STDERR, sprintf("PHP lint failed in %d of %d file(s).%s", $failed, count($files), PHP_EOL));
    exit(1);
}

fwrite(STDOUT, sprintf("PHP lint passed for %d file(s).%s", count($files), PHP_EOL));
