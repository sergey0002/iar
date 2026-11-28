<?php

declare(strict_types=1);

/**
 * Счётчик операций ввода-вывода (диагностика производительности).
 *
 * Копирует src/, index.php и legacy.php в .probe/, подменяя вызовы файловых
 * функций и хеширования на \IarProbe::<fn>(), который считает вызовы и
 * делегирует оригинальной функции. Исходный код проекта не изменяется.
 *
 * Зачем: политика «запрос из кеша не должен трогать оригинал» должна быть
 * измерима, а не декларирована. Отчёт показывает точное число stat/read/hash
 * операций на один HTTP-запрос.
 *
 * Замер cache hit на примере сценария 0 (базовый ресайз):
 *
 *   php tools/io-probe.php build
 *   php tools/integration.php setup 0 /tmp/iar-probe
 *   php tools/integration.php payload 0 0 /tmp/iar-probe "" probe > /tmp/p.json
 *   php tests/Integration/step.php /tmp/p.json   # MISS: вариант создаётся
 *   php tools/io-probe.php reset
 *   php tests/Integration/step.php /tmp/p.json   # HIT: только этот запрос в отчёте
 *   php tools/io-probe.php report
 *
 * Команды: build | reset | report.
 */

$root = dirname(__DIR__);
$probeDir = $root . '/.probe';
$command = $argv[1] ?? 'build';

/** Функции, которые считаем: дорогие операции над файлами. */
function iar_probe_functions(): array
{
    return [
        // stat-вызовы (дешёвые, но их количество важно)
        'filemtime', 'filesize', 'is_file', 'is_dir', 'file_exists', 'realpath', 'stat', 'lstat',
        'is_readable', 'is_writable', 'filetype', 'fileperms',
        // чтение содержимого (дорого)
        'file_get_contents', 'fopen', 'readfile', 'file', 'fread', 'fgets',
        // метаданные изображения = частичное чтение файла
        'getimagesize', 'getimagesizefromstring', 'exif_read_data',
        // хеширование содержимого (недопустимо на горячем пути)
        'md5_file', 'sha1_file', 'hash_file', 'crc32',
        // сканирование каталога
        'scandir', 'opendir', 'glob', 'readdir',
        // запись
        'file_put_contents', 'fwrite', 'rename', 'unlink', 'mkdir', 'tempnam', 'copy', 'touch',
        'flock', 'fclose',
    ];
}

function iar_probe_instrument(string $source): string
{
    $targets = array_flip(iar_probe_functions());
    $tokens = token_get_all($source);
    $out = '';
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        $text = is_array($token) ? $token[1] : $token;
        $id = is_array($token) ? $token[0] : null;

        if ($id === T_STRING && isset($targets[$text])) {
            $prev = iar_probe_prev($tokens, $i);
            $next = iar_probe_next($tokens, $i);
            $isCall = $next === '(';
            $isMember = in_array($prev, ['->', '::', 'function', '\\'], true);

            if ($isCall && !$isMember) {
                $out .= '\\IarProbe::' . $text;

                continue;
            }
        }

        $out .= $text;
    }

    return $out;
}

function iar_probe_prev(array $tokens, int $i): ?string
{
    for ($j = $i - 1; $j >= 0; $j--) {
        $t = $tokens[$j];
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return is_array($t) ? $t[1] : $t;
    }

    return null;
}

function iar_probe_next(array $tokens, int $i): ?string
{
    $count = count($tokens);
    for ($j = $i + 1; $j < $count; $j++) {
        $t = $tokens[$j];
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return is_array($t) ? $t[1] : $t;
    }

    return null;
}

function iar_probe_tree(string $from, string $to): void
{
    if (!is_dir($to)) {
        mkdir($to, 0777, true);
    }
    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $source = $from . '/' . $entry;
        $target = $to . '/' . $entry;
        if (is_dir($source)) {
            iar_probe_tree($source, $target);
        } elseif (str_ends_with($source, '.php')) {
            file_put_contents($target, iar_probe_instrument((string) file_get_contents($source)));
        } else {
            copy($source, $target);
        }
    }
}

if ($command === 'build') {
    iar_probe_tree($root . '/src', $probeDir . '/src');
    file_put_contents(
        $probeDir . '/index.php',
        iar_probe_instrument((string) file_get_contents($root . '/index.php')),
    );
    file_put_contents(
        $probeDir . '/legacy.php',
        iar_probe_instrument((string) file_get_contents($root . '/legacy.php')),
    );
    copy($root . '/config.php', $probeDir . '/config.php');
    copy($root . '/tools/io-probe-collector.php', $probeDir . '/collector.php');

    file_put_contents($probeDir . '/autoload.php', <<<'PHP'
<?php
require_once __DIR__ . '/collector.php';
spl_autoload_register(static function (string $class): void {
    $root = dirname(__DIR__);
    $map = [
        'App\\Tests\\' => $root . '/tests/',
        'App\\' => __DIR__ . '/src/',
        'Intervention\\Image\\' => $root . '/vendor/intervention/image/src/',
        'Intervention\\Gif\\' => $root . '/vendor/intervention/gif/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }

            return;
        }
    }
});
PHP);

    echo "Probe tree built in .probe/\n";
    exit(0);
}

if ($command === 'reset') {
    foreach (glob($probeDir . '/hits/*.json') ?: [] as $file) {
        unlink($file);
    }
    echo "Probe hits cleared.\n";
    exit(0);
}

if ($command === 'report') {
    $dir = (string) ($argv[2] ?? $probeDir . '/hits');
    $totals = [];
    $files = glob(rtrim($dir, '/') . '/*.json') ?: [];
    foreach ($files as $file) {
        $data = (array) json_decode((string) file_get_contents($file), true);
        $counts = isset($data['counts']) && is_array($data['counts']) ? $data['counts'] : $data;
        foreach ($counts as $fn => $n) {
            $totals[$fn] = ($totals[$fn] ?? 0) + (int) $n;
        }
        if (isset($data['trace']) && is_array($data['trace'])) {
            echo '--- request ' . basename($file) . " ---\n";
            foreach ($data['trace'] as $line) {
                echo '  ' . $line . "\n";
            }
        }
    }
    arsort($totals);
    echo str_pad('Operation', 26) . "Calls\n" . str_repeat('-', 36) . "\n";
    foreach ($totals as $fn => $n) {
        echo str_pad((string) $fn, 26) . $n . "\n";
    }
    echo str_repeat('-', 36) . "\n" . str_pad('TOTAL', 26) . array_sum($totals) . "\n";
    echo 'Requests: ' . count($files) . "\n";
    exit(0);
}

echo "Unknown command: {$command}\n";
exit(1);
