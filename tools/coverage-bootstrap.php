<?php

declare(strict_types=1);

if (!function_exists('xdebug_start_code_coverage')) {
    fwrite(STDERR, "Xdebug coverage extension is required for coverage output.\n");
    return;
}

xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);
register_shutdown_function(static function (): void {
    $coverage = xdebug_get_code_coverage();
    if (!is_array($coverage)) return;

    $projectRoot = dirname(__DIR__);
    $target = $projectRoot . '/build/coverage/coverage.lcov';
    $directory = dirname($target);
    if (!is_dir($directory)) @mkdir($directory, 0777, true);

    $lcov = [];
    foreach ($coverage as $file => $lines) {
        if (!str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', $projectRoot) . '/core/')) continue;
        $lcov[] = 'TN:Kabuto';
        $lcov[] = 'SF:' . $file;
        foreach ($lines as $line => $hits) {
            if (!is_int($hits)) continue;
            $lcov[] = 'DA:' . (int) $line . ',' . ($hits < 0 ? 0 : $hits);
        }
        $lcov[] = 'end_of_record';
    }
    file_put_contents($target, implode("\n", $lcov) . "\n");
});
