<?php

declare(strict_types=1);

/**
 * Портативное измерение покрытия строк — без Xdebug и PCOV.
 *
 * Зачем: концепция IAR — «положил папку и работает», тесты обязаны
 * запускаться и измеряться там, где нет ни composer, ни расширений.
 * В CI основным остаётся PHPUnit + PCOV (tools/coverage-gate.php),
 * этот инструмент — независимая проверка того же множества строк.
 *
 * Как: исходники копируются в .cov/ и в начало каждого оператора,
 * начинающегося с новой строки, добавляется вызов IarCoverage::hit().
 * Исходный код проекта не изменяется.
 *
 * Использование:
 *   php tools/coverage-portable.php build     — собрать .cov/
 *   php tools/coverage-portable.php report    — свести .cov/hits и проверить порог
 *   php tools/coverage-portable.php reset     — очистить накопленные попадания
 */

$root = dirname(__DIR__);
$covDir = $root . '/.cov';
$command = $argv[1] ?? 'report';

/** Инструментирует один PHP-файл. Возвращает [код, список строк]. */
function iar_instrument(string $source, string $key): array
{
    // Строки, помеченные @codeCoverageIgnore, исключаются так же, как в PHPUnit.
    // IAR_STRICT_COVERAGE=1 отключает исключения (CI-джоб с ext-imagick/avif).
    $strict = getenv('IAR_STRICT_COVERAGE') === '1';
    $ignored = [];
    if (!$strict) {
        $depthIgnore = false;
        foreach (explode("\n", $source) as $i => $line) {
            if (str_contains($line, '@codeCoverageIgnoreStart')) {
                $depthIgnore = true;
            }
            if ($depthIgnore || str_contains($line, '@codeCoverageIgnore')) {
                $ignored[$i + 1] = true;
            }
            if (str_contains($line, '@codeCoverageIgnoreStop')) {
                $depthIgnore = false;
            }
        }
    }

    $tokens = token_get_all($source);
    $out = '';
    $lines = [];

    $prev = null;          // последний значимый токен
    $prevEndsLine = false; // был ли он последним на своей строке
    $atLineStart = true;   // текущий токен — первый на строке
    $matchStack = [];      // глубины блоков match (в них вставлять нельзя)
    $pendingMatch = false;
    $depth = 0;

    // Токены, с которых может начинаться оператор.
    $stmtStart = [
        T_VARIABLE, T_RETURN, T_ECHO, T_PRINT, T_THROW, T_IF, T_FOREACH, T_FOR,
        T_WHILE, T_SWITCH, T_TRY, T_BREAK, T_CONTINUE, T_UNSET, T_GLOBAL,
        T_INC, T_DEC, T_EXIT, T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED,
    ];
    $terminators = ['{', '}', ';', ':'];

    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        $id = is_array($token) ? $token[0] : null;
        $text = is_array($token) ? $token[1] : $token;
        $line = is_array($token) ? $token[2] : 0;

        if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
            $out .= $text;
            if (str_contains($text, "\n")) {
                $atLineStart = true;
                $prevEndsLine = true;
            }

            continue;
        }

        if ($id === T_MATCH) {
            $pendingMatch = true;
        }

        if ($text === '{') {
            $depth++;
            if ($pendingMatch) {
                $matchStack[] = $depth;
                $pendingMatch = false;
            }
        } elseif ($text === '}') {
            if ($matchStack !== [] && end($matchStack) === $depth) {
                array_pop($matchStack);
            }
            $depth--;
        }

        $insert = false;
        if ($atLineStart && $prevEndsLine && $matchStack === [] && $prev !== null
            && in_array($prev, $terminators, true) && in_array($id, $stmtStart, true)
        ) {
            if ($id === T_STRING || $id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED) {
                // Только вызов функции или статики, не метка/имя аргумента.
                $next = iar_next_meaningful($tokens, $i);
                $insert = $next === '(' || $next === '::';
            } elseif ($id === T_WHILE) {
                $insert = $prev !== '}'; // do...while
            } else {
                $insert = true;
            }
        }

        if ($insert && !isset($ignored[$line])) {
            $out .= sprintf('\\IarCoverage::hit(%s, %d);', var_export($key, true), $line);
            $lines[] = $line;
        }

        $out .= $text;
        $prev = $text;
        $prevEndsLine = false;
        $atLineStart = false;
    }

    return [$out, array_values(array_unique($lines))];
}

function iar_next_meaningful(array $tokens, int $i): ?string
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

function iar_copy_tree(string $from, string $to, callable $filter): array
{
    $map = [];
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
            $map += iar_copy_tree($source, $target, $filter);
        } else {
            $map += $filter($source, $target);
        }
    }

    return $map;
}

if ($command === 'build') {
    if (!is_dir($covDir)) {
        mkdir($covDir, 0777, true);
    }

    $executable = [];
    $filter = static function (string $source, string $target) use ($root, &$executable): array {
        $key = substr($source, strlen($root) + 1);
        if (!str_ends_with($source, '.php')) {
            copy($source, $target);

            return [];
        }
        [$code, $lines] = iar_instrument((string) file_get_contents($source), $key);
        file_put_contents($target, $code);
        $executable[$key] = $lines;

        return [$key => $lines];
    };

    $executable = [];
    iar_copy_tree($root . '/src', $covDir . '/src', $filter);
    [$code, $lines] = iar_instrument((string) file_get_contents($root . '/index.php'), 'index.php');
    file_put_contents($covDir . '/index.php', $code);
    $executable['index.php'] = $lines;
    [$code, $lines] = iar_instrument((string) file_get_contents($root . '/legacy.php'), 'legacy.php');
    file_put_contents($covDir . '/legacy.php', $code);
    $executable['legacy.php'] = $lines;

    copy($root . '/config.php', $covDir . '/config.php');
    copy($root . '/tools/coverage-collector.php', $covDir . '/collector.php');

    // Автозагрузчик инструментированного дерева: App\ → .cov/src,
    // тесты и вендор берутся из оригинального дерева.
    file_put_contents($covDir . '/autoload.php', <<<'PHP'
<?php
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

    file_put_contents($covDir . '/lines.json', (string) json_encode($executable, JSON_PRETTY_PRINT));

    $total = array_sum(array_map('count', $executable));
    echo 'Instrumented files: ' . count($executable) . ', statement lines: ' . $total . "\n";
    exit(0);
}

if ($command === 'reset') {
    foreach (glob($covDir . '/hits/*.json') ?: [] as $file) {
        unlink($file);
    }
    echo "Coverage hits cleared.\n";
    exit(0);
}

if ($command === 'report') {
    $linesFile = $covDir . '/lines.json';
    if (!is_file($linesFile)) {
        echo "Run \"php tools/coverage-portable.php build\" first.\n";
        exit(1);
    }

    /** @var array<string,list<int>> $executable */
    $executable = (array) json_decode((string) file_get_contents($linesFile), true);
    $covered = [];

    foreach (glob($covDir . '/hits/*.json') ?: [] as $hitFile) {
        $data = (array) json_decode((string) file_get_contents($hitFile), true);
        foreach ($data as $file => $lines) {
            foreach ((array) $lines as $line) {
                $covered[$file][(int) $line] = true;
            }
        }
    }

    $totalLines = 0;
    $totalCovered = 0;
    $missingReport = [];

    ksort($executable);
    echo str_pad('File', 46) . str_pad('Covered', 12) . "Percent\n";
    echo str_repeat('-', 68) . "\n";

    foreach ($executable as $file => $lines) {
        $total = count($lines);
        $hit = 0;
        $missing = [];
        foreach ($lines as $line) {
            if (isset($covered[$file][$line])) {
                $hit++;
            } else {
                $missing[] = $line;
            }
        }
        $totalLines += $total;
        $totalCovered += $hit;
        $percent = $total === 0 ? 100.0 : $hit / $total * 100;
        echo str_pad($file, 46) . str_pad($hit . '/' . $total, 12) . number_format($percent, 2, '.', '') . "%\n";
        if ($missing !== []) {
            $missingReport[$file] = $missing;
        }
    }

    echo str_repeat('-', 68) . "\n";
    $overall = $totalLines === 0 ? 100.0 : $totalCovered / $totalLines * 100;
    echo str_pad('TOTAL', 46) . str_pad($totalCovered . '/' . $totalLines, 12) . number_format($overall, 2, '.', '') . "%\n";

    if ($missingReport !== []) {
        echo "\nUncovered lines:\n";
        foreach ($missingReport as $file => $missing) {
            echo '  ' . $file . ': ' . implode(', ', $missing) . "\n";
        }
    }

    file_put_contents($covDir . '/report.json', (string) json_encode([
        'total_lines' => $totalLines,
        'covered_lines' => $totalCovered,
        'percent' => round($overall, 2),
        'missing' => $missingReport,
    ], JSON_PRETTY_PRINT));

    $threshold = (float) ($argv[2] ?? 100);
    if ($overall + 0.0001 < $threshold) {
        echo "\nCoverage gate FAILED: " . number_format($overall, 2, '.', '') . "% < {$threshold}%\n";
        exit(1);
    }

    echo "\nCoverage gate passed (threshold {$threshold}%).\n";
    exit(0);
}

echo "Unknown command: {$command}\n";
exit(1);
