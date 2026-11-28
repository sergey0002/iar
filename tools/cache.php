<?php

/**
 * Управление кешем IAR. Работает на PHP 7.0+ без Composer и расширений.
 *
 *   php tools/cache.php stats                     — сколько файлов, вес, распределение
 *   php tools/cache.php clean --source=photo.jpg  — удалить все варианты одного оригинала
 *   php tools/cache.php clean --older-than=30d    — удалить варианты старше N (s|m|h|d|w)
 *   php tools/cache.php clean --orphans           — удалить варианты удалённых оригиналов
 *   php tools/cache.php clean --max-size=500M     — удалить самые старые, пока вес > лимита
 *   php tools/cache.php wipe                      — удалить всё содержимое кеша
 *
 * Любой clean/wipe принимает --dry-run. Кеш полностью удаляемый: после
 * очистки варианты пересоздаются при первом обращении по URL.
 *
 * Имя кеш-файла самодостаточно для точечной очистки:
 *   <имя>__<W>x<H>[кроп][режим]__<md5 относительного пути>_<mtime>[_хеш настроек].<ext>
 * поэтому «удалить всё по одному оригиналу» — это поиск по маркеру хеша,
 * без чтения самих файлов и без обхода всего кеша по содержимому.
 *
 * Инструмент использует те же правила ключа, что и оба движка (legacy.php),
 * поэтому одинаково обслуживает кеш, созданный на PHP 7 и на PHP 8.
 */

require_once dirname(__DIR__) . '/legacy.php';

$root = dirname(__DIR__);
$command = isset($argv[1]) ? $argv[1] : 'help';
$options = array();
foreach (array_slice($argv, 2) as $arg) {
    if (strpos($arg, '--') !== 0) {
        continue;
    }
    $parts = explode('=', substr($arg, 2), 2);
    $options[$parts[0]] = isset($parts[1]) ? $parts[1] : true;
}

$dryRun = isset($options['dry-run']);
$cfg = iar_legacy_config($root . '/config.php');
$cacheDir = $cfg['cache_dir'];

if ($cfg['base_problem'] && $command !== 'help') {
    echo "Внимание: images_base_dir недоступен, используется корень сайта.\n";
}

/** Список файлов кеша (без чтения содержимого). */
function cache_entries($dir)
{
    if (!is_dir($dir)) {
        return array();
    }

    $entries = array();
    $names = scandir($dir);
    foreach ($names === false ? array() : $names as $name) {
        if ($name === '.' || $name === '..' || substr($name, -5) === '.lock' || strpos($name, 'iar_') === 0) {
            continue;
        }
        $path = $dir . '/' . $name;
        if (!is_file($path)) {
            continue;
        }
        $entries[] = array(
            'name' => $name,
            'path' => $path,
            'size' => (int) filesize($path),
            'mtime' => (int) filemtime($path),
        );
    }

    return $entries;
}

function human_bytes($bytes)
{
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $i = 0;
    $value = (float) $bytes;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }

    return round($value, $i === 0 ? 0 : 2) . ' ' . $units[$i];
}

function parse_size($value)
{
    $unit = strtoupper(substr($value, -1));
    $number = (float) ($unit >= 'A' && $unit <= 'Z' ? substr($value, 0, -1) : $value);
    $factors = array('K' => 1024, 'M' => 1048576, 'G' => 1073741824, 'T' => 1099511627776);
    $factor = isset($factors[$unit]) ? $factors[$unit] : 1;

    return (int) round($number * $factor);
}

function parse_age($value)
{
    $unit = strtolower(substr($value, -1));
    $number = (int) ($unit >= 'a' && $unit <= 'z' ? substr($value, 0, -1) : $value);
    $factors = array('s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800);
    $factor = isset($factors[$unit]) ? $factors[$unit] : 86400;

    return $number * $factor;
}

function remove_entries($entries, $dryRun)
{
    $removed = 0;
    $bytes = 0;
    foreach ($entries as $entry) {
        if ($dryRun || @unlink($entry['path'])) {
            $removed++;
            $bytes += $entry['size'];
        }
    }

    return array($removed, $bytes);
}

function cache_report($action, $removed, $bytes, $dryRun)
{
    printf(
        "%s: %d файл(ов), %s%s\n",
        $action,
        $removed,
        human_bytes($bytes),
        $dryRun ? ' (dry-run, ничего не удалено)' : ''
    );
}

if ($command === 'stats') {
    $entries = cache_entries($cacheDir);
    $total = array_sum(array_column($entries, 'size'));
    $byExt = array();
    $bySource = array();
    foreach ($entries as $entry) {
        $ext = strtolower((string) pathinfo($entry['name'], PATHINFO_EXTENSION));
        $byExt[$ext] = (isset($byExt[$ext]) ? $byExt[$ext] : 0) + 1;
        if (preg_match('/__([0-9a-f]{32})_\d+/', $entry['name'], $m) === 1) {
            $bySource[$m[1]] = (isset($bySource[$m[1]]) ? $bySource[$m[1]] : 0) + 1;
        }
    }
    arsort($byExt);

    echo 'Кеш: ' . $cacheDir . "\n";
    echo 'Файлов: ' . count($entries) . ', вес: ' . human_bytes($total) . "\n";
    echo 'Уникальных оригиналов: ' . count($bySource) . "\n";
    echo "По форматам:\n";
    foreach ($byExt as $ext => $n) {
        echo '  ' . str_pad($ext === '' ? '(без расширения)' : $ext, 8) . $n . "\n";
    }
    if ($entries !== array()) {
        echo 'Самый старый: ' . date('Y-m-d H:i', min(array_column($entries, 'mtime')))
            . ', самый новый: ' . date('Y-m-d H:i', max(array_column($entries, 'mtime'))) . "\n";
    }
    exit(0);
}

if ($command === 'wipe') {
    $entries = cache_entries($cacheDir);
    list($removed, $bytes) = remove_entries($entries, $dryRun);
    cache_report('Полная очистка', $removed, $bytes, $dryRun);
    exit(0);
}

if ($command === 'clean') {
    $entries = cache_entries($cacheDir);
    $selected = array();
    $reasons = array();

    if (isset($options['source']) && is_string($options['source'])) {
        // Путь можно задать так же, как в URL: с псевдонимом и папкой-пресетом.
        // Кеш-ключ считается от реального пути, поэтому раскрываем маршрут.
        $requested = ltrim($options['source'], '/');
        $router = iar_legacy_router($cfg['aliases_file'], $cfg['base']);
        $routed = iar_legacy_route($router, $requested);
        $source = $cfg['base'] . $routed['name'];
        $marker = iar_legacy_hash_marker($source, $cfg['base']);
        $prefix = pathinfo($source, PATHINFO_FILENAME) . '__';
        foreach ($entries as $entry) {
            if (strpos($entry['name'], $prefix) === 0 && strpos($entry['name'], $marker) !== false) {
                $selected[$entry['name']] = $entry;
            }
        }
        $reasons[] = 'оригинал ' . $options['source']
            . ($routed['name'] === $requested ? '' : ' (путь ' . $routed['name'] . ')');
    }

    if (isset($options['older-than']) && is_string($options['older-than'])) {
        $threshold = time() - parse_age($options['older-than']);
        foreach ($entries as $entry) {
            if ($entry['mtime'] < $threshold) {
                $selected[$entry['name']] = $entry;
            }
        }
        $reasons[] = 'старше ' . $options['older-than'];
    }

    if (isset($options['orphans'])) {
        // Хеш пути необратим, поэтому строим множество «живых» маркеров
        // обходом оригиналов и удаляем всё, чего в нём нет.
        $alive = array();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cfg['base'], FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if (!in_array(strtolower($file->getExtension()), $cfg['allowed'], true)) {
                continue;
            }
            $alive[iar_legacy_hash_marker($file->getPathname(), $cfg['base'])] = true;
        }
        foreach ($entries as $entry) {
            if (preg_match('/__([0-9a-f]{32})_\d+/', $entry['name'], $m) !== 1) {
                continue;
            }
            if (!isset($alive['__' . $m[1] . '_'])) {
                $selected[$entry['name']] = $entry;
            }
        }
        $reasons[] = 'оригинал удалён (сироты)';
    }

    if (isset($options['max-size']) && is_string($options['max-size'])) {
        $limit = parse_size($options['max-size']);
        $all = $entries;
        usort($all, function ($a, $b) {
            return $a['mtime'] === $b['mtime'] ? 0 : ($a['mtime'] < $b['mtime'] ? -1 : 1);
        });
        $total = array_sum(array_column($all, 'size'));
        foreach ($all as $entry) {
            if ($total <= $limit) {
                break;
            }
            if (isset($selected[$entry['name']])) {
                continue;
            }
            $selected[$entry['name']] = $entry;
            $total -= $entry['size'];
        }
        $reasons[] = 'лимит веса ' . $options['max-size'];
    }

    if ($reasons === array()) {
        echo "Укажите критерий: --source=, --older-than=, --orphans, --max-size=\n";
        exit(1);
    }

    list($removed, $bytes) = remove_entries(array_values($selected), $dryRun);
    cache_report('Очистка (' . implode(', ', $reasons) . ')', $removed, $bytes, $dryRun);
    exit(0);
}

echo <<<TEXT
Управление кешем IAR (PHP 7.0+, без зависимостей)

  php tools/cache.php stats
  php tools/cache.php clean --source=photo.jpg [--dry-run]
                            путь можно писать как в URL: --source=z/thumb/photo.jpg
  php tools/cache.php clean --older-than=30d [--dry-run]
  php tools/cache.php clean --orphans [--dry-run]
  php tools/cache.php clean --max-size=500M [--dry-run]
  php tools/cache.php wipe [--dry-run]

Кеш можно удалить целиком в любой момент — варианты пересоздаются
при первом обращении по URL.

TEXT;
exit(0);
