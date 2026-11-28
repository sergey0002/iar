<?php

declare(strict_types=1);

/**
 * Гейт покрытия: падает, если покрытие строк кода проекта < 100%.
 *
 * Использование: php tools/coverage-gate.php clover.xml [threshold]
 * Учитываются только src/**, index.php и legacy.php (вендор и тесты исключены).
 */

$path = $argv[1] ?? 'clover.xml';
$threshold = isset($argv[2]) ? (float) $argv[2] : 100.0;

if (!is_file($path)) {
    fwrite(STDERR, "Coverage file not found: {$path}\n");
    exit(1);
}

$xml = @simplexml_load_file($path);
if ($xml === false) {
    fwrite(STDERR, "Could not parse coverage file: {$path}\n");
    exit(1);
}

$total = 0;
$covered = 0;
$perFile = [];

foreach ($xml->xpath('//file') ?: [] as $file) {
    $name = str_replace('\\', '/', (string) $file['name']);
    $isProject = str_contains($name, '/src/')
        || str_ends_with($name, '/index.php')
        || str_ends_with($name, '/legacy.php');
    if (!$isProject || str_contains($name, '/vendor/') || str_contains($name, '/tests/')) {
        continue;
    }

    $fileTotal = 0;
    $fileCovered = 0;
    foreach ($file->line as $line) {
        if ((string) $line['type'] !== 'stmt') {
            continue;
        }
        $fileTotal++;
        if ((int) $line['count'] > 0) {
            $fileCovered++;
        }
    }

    if ($fileTotal === 0) {
        continue;
    }

    $total += $fileTotal;
    $covered += $fileCovered;
    $perFile[$name] = [$fileCovered, $fileTotal];
}

if ($total === 0) {
    fwrite(STDERR, "No project statements found in coverage report.\n");
    exit(1);
}

$percent = $covered / $total * 100;

ksort($perFile);
foreach ($perFile as $name => [$c, $t]) {
    $short = substr($name, (int) strrpos($name, '/src/') !== 0 ? 0 : 0);
    fwrite(STDOUT, sprintf("  %6.2f%%  %4d/%-4d  %s\n", $c / $t * 100, $c, $t, basename($name)));
}

fwrite(STDOUT, sprintf("\nProject line coverage: %.2f%% (%d/%d), threshold %.2f%%\n", $percent, $covered, $total, $threshold));

if ($percent + 0.0001 < $threshold) {
    fwrite(STDERR, "Coverage gate FAILED\n");
    exit(1);
}

fwrite(STDOUT, "Coverage gate OK\n");
exit(0);
