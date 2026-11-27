<?php

declare(strict_types=1);

/**
 * Драйвер интеграционных тестов.
 *
 *   php tools/integration.php run      — поднять встроенный PHP-сервер и
 *                                        прогнать все сценарии реальными HTTP-запросами
 *   php tools/integration.php export   — выгрузить сценарии в JSON (для внешних раннеров)
 *   php tools/integration.php setup    — подготовить площадку сценария
 *   php tools/integration.php payload  — собрать задание на один запрос
 *   php tools/integration.php verify   — проверить собранные ответы
 *
 * Сценарии описаны данными в tests/Integration/scenarios.php, проверка
 * ожиданий живёт здесь — поэтому любой раннер (в том числе без PHPUnit)
 * проверяет одно и то же.
 */

use App\Tests\Support\Fixtures;

$root = dirname(__DIR__);
require_once $root . '/tests/Support/Fixtures.php';

$command = $argv[1] ?? 'run';

function it_scenarios(string $root): array
{
    return require $root . '/tests/Integration/scenarios.php';
}

function it_cov_dir(string $root): ?string
{
    $dir = $root . '/.cov';

    return is_file($dir . '/autoload.php') ? $dir : null;
}

function it_entry(string $root): string
{
    $cov = it_cov_dir($root);

    return $cov === null ? $root . '/index.php' : $cov . '/index.php';
}

function it_remove(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path) && !is_link($path)) {
            it_remove($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function it_config(array $scenario, string $site): array
{
    return array_merge([
        'images_base_dir' => $site,
        'cache_dir' => $site . '/cache',
        'log_dir' => $site . '/log',
        'logging' => false,
        'redirect_to_cache' => false,
    ], $scenario['config'] ?? []);
}

function it_write_config(array $config, string $site): string
{
    $file = $site . '/iar-config.php';
    file_put_contents($file, '<?php return ' . var_export($config, true) . ';');

    return $file;
}

/** Подготовка площадки: фикстуры + конфиг. */
function it_setup(array $scenario, string $site): void
{
    it_remove($site);
    Fixtures::ensure($site);
    it_write_config(it_config($scenario, $site), $site);
}

/** Действие перед шагом + сборка задания для step.php. */
function it_payload(string $root, array $scenario, int $stepIndex, string $site, string $prevEtag): array
{
    $step = $scenario['steps'][$stepIndex];
    $action = $step['action'] ?? null;
    $config = it_config($scenario, $site);
    $entry = it_entry($root);
    $noAutoload = false;

    if ($action === 'wipe_cache') {
        it_remove($site . '/cache');
    } elseif ($action === 'touch_source') {
        $name = (string) ($step['get']['name'] ?? 'photo.jpg');
        touch($site . '/' . $name, time() + 60 + $stepIndex);
        clearstatcache();
    } elseif ($action === 'lower_quality') {
        $config['jpeg_quality'] = 60;
        it_write_config($config, $site);
    } elseif ($action === 'break_vendor') {
        // Копия точки входа в каталоге без vendor/ — проверяем диагностику.
        $dir = $site . '/no-vendor';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        copy($entry, $dir . '/index.php');
        $entry = $dir . '/index.php';
        $noAutoload = true;
    }

    $get = $step['get'] ?? [];
    $server = array_merge([
        'REQUEST_METHOD' => 'GET',
        'SCRIPT_NAME' => '/iar/index.php',
        'REQUEST_URI' => '/iar/index.php?' . http_build_query($get),
        'DOCUMENT_ROOT' => $site,
        'HTTP_ACCEPT' => '',
    ], $step['server'] ?? []);

    if (($step['use_previous_etag'] ?? false) && $prevEtag !== '') {
        $server['HTTP_IF_NONE_MATCH'] = $prevEtag;
    }

    return [
        'entry' => $entry,
        'get' => $get,
        'server' => $server,
        'config' => $site . '/iar-config.php',
        'coverage' => it_cov_dir($root),
        'no_autoload' => $noAutoload,
        'url' => '/iar/index.php?' . http_build_query($get),
    ];
}

function it_header(array $headers, string $name): ?string
{
    foreach ($headers as $key => $value) {
        if (strcasecmp((string) $key, $name) === 0) {
            return is_array($value) ? (string) end($value) : (string) $value;
        }
    }

    return null;
}

function it_cache_count(string $site, string $prefix): int
{
    $dir = $site . '/cache';
    if (!is_dir($dir)) {
        return 0;
    }
    $count = 0;
    foreach (scandir($dir) ?: [] as $entry) {
        if (str_starts_with($entry, $prefix)) {
            $count++;
        }
    }

    return $count;
}

function it_log_content(string $site): ?string
{
    $dir = $site . '/log';
    if (!is_dir($dir)) {
        return null;
    }
    $content = '';
    foreach (scandir($dir) ?: [] as $entry) {
        if (str_ends_with($entry, '.log')) {
            $content .= (string) file_get_contents($dir . '/' . $entry);
        }
    }

    return $content;
}

/** Проверка одного шага. Возвращает список текстовых ошибок. */
function it_check(array $expect, array $response, string $site, ?array $previous): array
{
    $errors = [];
    $headers = $response['headers'] ?? [];
    $body = (string) base64_decode((string) ($response['body_base64'] ?? ''), true);

    if (isset($expect['status']) && $expect['status'] !== $response['status']) {
        $errors[] = sprintf('status: expected %d, got %d', $expect['status'], $response['status']);
    }

    foreach ($expect['headers'] ?? [] as $name => $value) {
        $actual = it_header($headers, $name);
        if ($actual !== $value) {
            $errors[] = sprintf('header %s: expected "%s", got "%s"', $name, $value, $actual ?? '(absent)');
        }
    }

    foreach ($expect['headers_absent'] ?? [] as $name) {
        if (it_header($headers, $name) !== null) {
            $errors[] = sprintf('header %s must be absent', $name);
        }
    }

    foreach ($expect['header_present'] ?? [] as $name) {
        if (it_header($headers, $name) === null) {
            $errors[] = sprintf('header %s must be present', $name);
        }
    }

    foreach ($expect['header_contains'] ?? [] as $name => $needle) {
        if (!str_contains((string) it_header($headers, $name), $needle)) {
            $errors[] = sprintf('header %s must contain "%s"', $name, $needle);
        }
    }

    foreach ($expect['header_matches'] ?? [] as $name => $pattern) {
        if (preg_match($pattern, (string) it_header($headers, $name)) !== 1) {
            $errors[] = sprintf('header %s must match %s', $name, $pattern);
        }
    }

    foreach ($expect['header_same_as_previous'] ?? [] as $name => $prevName) {
        $actual = it_header($headers, $name);
        $expected = $previous === null ? null : it_header($previous['headers'] ?? [], $prevName);
        if ($actual === null || $actual !== $expected) {
            $errors[] = sprintf('header %s must repeat previous %s', $name, $prevName);
        }
    }

    if (($expect['body'] ?? null) === 'empty' && $body !== '') {
        $errors[] = 'body must be empty, got ' . strlen($body) . ' bytes';
    }
    if (($expect['body'] ?? null) === 'nonempty' && $body === '') {
        $errors[] = 'body must not be empty';
    }
    if (isset($expect['body_contains']) && !str_contains($body, $expect['body_contains'])) {
        $errors[] = sprintf('body must contain "%s"', $expect['body_contains']);
    }
    if (isset($expect['body_not_contains']) && str_contains($body, $expect['body_not_contains'])) {
        $errors[] = sprintf('body must not contain "%s"', $expect['body_not_contains']);
    }
    if (isset($expect['body_equals_source'])) {
        $source = (string) @file_get_contents($site . '/' . $expect['body_equals_source']);
        if ($body !== $source) {
            $errors[] = 'body must be byte-identical to the source';
        }
    }
    if (($expect['body_differs_from_previous'] ?? false) === true) {
        $prevBody = $previous === null ? '' : (string) base64_decode((string) ($previous['body_base64'] ?? ''), true);
        if ($body === $prevBody) {
            $errors[] = 'body must differ from the previous response';
        }
    }
    if (isset($expect['image_size'])) {
        $info = @getimagesizefromstring($body);
        $actual = $info === false ? null : [$info[0], $info[1]];
        if ($actual !== $expect['image_size']) {
            $errors[] = sprintf(
                'image size: expected %s, got %s',
                implode('x', $expect['image_size']),
                $actual === null ? '(not an image)' : implode('x', $actual),
            );
        }
    }
    if (isset($expect['cache_files'])) {
        $actual = it_cache_count($site, $expect['cache_files']['prefix']);
        if ($actual !== $expect['cache_files']['count']) {
            $errors[] = sprintf(
                'cache files with prefix "%s": expected %d, got %d',
                $expect['cache_files']['prefix'],
                $expect['cache_files']['count'],
                $actual,
            );
        }
    }
    if (array_key_exists('log_dir_exists', $expect)) {
        $actual = is_dir($site . '/log');
        if ($actual !== $expect['log_dir_exists']) {
            $errors[] = 'log directory existence: expected ' . var_export($expect['log_dir_exists'], true);
        }
    }
    if (isset($expect['log_lines'])) {
        $content = (string) it_log_content($site);
        $actual = $content === '' ? 0 : count(array_filter(explode("\n", trim($content))));
        if ($actual !== $expect['log_lines']) {
            $errors[] = sprintf('log lines: expected %d, got %d', $expect['log_lines'], $actual);
        }
    }
    if (isset($expect['log_contains']) && !str_contains((string) it_log_content($site), $expect['log_contains'])) {
        $errors[] = sprintf('log must contain "%s"', $expect['log_contains']);
    }

    return $errors;
}

function it_requires_met(string $requires): bool
{
    return match ($requires) {
        'webp' => function_exists('imagewebp'),
        'avif' => function_exists('imageavif'),
        'imagick' => class_exists(Imagick::class),
        default => true,
    };
}

/** Проверка одного сценария. Возвращает список ошибок. */
function it_verify_one(array $scenario, array $result): array
{
    $site = $result['site'];
    $previous = null;
    $errors = [];

    foreach ($scenario['steps'] as $stepIndex => $step) {
        $response = $result['steps'][$stepIndex] ?? null;
        if ($response === null) {
            $errors[] = "step {$stepIndex}: no response captured";

            continue;
        }
        foreach (it_check($step['expect'] ?? [], $response, $site, $previous) as $error) {
            $errors[] = "step {$stepIndex}: {$error}";
        }
        $previous = $response;
    }

    return $errors;
}

function it_verify(string $root, array $results): int
{
    $scenarios = it_scenarios($root);
    $passed = 0;
    $failed = 0;
    $skipped = 0;
    $checks = 0;
    $messages = [];

    foreach ($results as $index => $result) {
        $scenario = $scenarios[$index];
        if (!it_requires_met($scenario['requires'] ?? '')) {
            $skipped++;
            echo 'S';

            continue;
        }

        $checks += count($scenario['steps']);
        $errors = it_verify_one($scenario, $result);
        if ($errors === []) {
            $passed++;
            echo '.';
        } else {
            $failed++;
            echo 'F';
            $messages[] = $scenario['name'] . "\n    " . implode("\n    ", $errors);
        }
    }

    echo "\n\n";
    if ($messages !== []) {
        echo "FAILURES:\n\n";
        foreach ($messages as $i => $message) {
            echo ($i + 1) . ') ' . $message . "\n\n";
        }
    }

    printf(
        "Integration scenarios: %d, Passed: %d, Failed: %d, Skipped: %d, Steps: %d\n",
        count($results),
        $passed,
        $failed,
        $skipped,
        $checks,
    );

    return $failed === 0 ? 0 : 1;
}

if ($command === 'export') {
    echo (string) json_encode(it_scenarios($root), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);
}

if ($command === 'setup') {
    $scenarios = it_scenarios($root);
    it_setup($scenarios[(int) $argv[2]], (string) $argv[3]);
    echo (string) json_encode(['site' => $argv[3], 'entry' => it_entry($root)]);
    exit(0);
}

if ($command === 'payload') {
    $scenarios = it_scenarios($root);
    echo (string) json_encode(
        it_payload($root, $scenarios[(int) $argv[2]], (int) $argv[3], (string) $argv[4], (string) ($argv[5] ?? '')),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );
    exit(0);
}

if ($command === 'verify') {
    $results = (array) json_decode((string) file_get_contents((string) $argv[2]), true);
    exit(it_verify($root, $results));
}

if ($command !== 'run') {
    echo "Unknown command: {$command}\n";
    exit(1);
}

// ---- Режим run: реальный HTTP через встроенный сервер PHP ----

if (!function_exists('proc_open')) {
    echo "proc_open is unavailable; use an external runner with export/setup/payload/verify.\n";
    exit(1);
}

$scenarios = it_scenarios($root);
$site = sys_get_temp_dir() . '/iar_it_server_' . bin2hex(random_bytes(4));
Fixtures::ensure($site);
$payloadFile = $site . '/payload.json';
file_put_contents($payloadFile, '{}');

$port = 8000 + random_int(100, 900);
$env = array_merge($_ENV, getenv(), ['IAR_STEP_PAYLOAD' => $payloadFile]);
$spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $site, $root . '/tests/Integration/step.php'],
    $spec,
    $pipes,
    $root,
    $env,
);

if (!is_resource($server)) {
    echo "Could not start the built-in PHP server.\n";
    exit(1);
}

// Ждём готовности сервера.
$ready = false;
for ($i = 0; $i < 50; $i++) {
    $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if (is_resource($probe)) {
        fclose($probe);
        $ready = true;
        break;
    }
    usleep(100000);
}

if (!$ready) {
    echo "The built-in PHP server did not start on port {$port}.\n";
    proc_terminate($server);
    exit(1);
}

$results = [];
$passed = 0;
$failed = 0;
$skipped = 0;
$messages = [];
foreach ($scenarios as $index => $scenario) {
    if (!it_requires_met($scenario['requires'] ?? '')) {
        $results[$index] = ['site' => $site, 'steps' => []];
        $skipped++;
        echo 'S';

        continue;
    }

    it_setup($scenario, $site);
    file_put_contents($payloadFile, '{}');
    $previousEtag = '';
    $steps = [];

    foreach ($scenario['steps'] as $stepIndex => $step) {
        $payload = it_payload($root, $scenario, $stepIndex, $site, $previousEtag);
        file_put_contents($payloadFile, (string) json_encode($payload));

        $headerLines = [];
        foreach ($payload['server'] as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headerLines[] = $name . ': ' . $value;
            }
        }

        $url = 'http://127.0.0.1:' . $port . ($step['server']['REQUEST_URI'] ?? $payload['url']);
        $context = stream_context_create(['http' => [
            'method' => $payload['server']['REQUEST_METHOD'],
            'header' => implode("\r\n", $headerLines),
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 30,
        ]]);

        $body = @file_get_contents($url, false, $context);
        $rawHeaders = $http_response_header ?? [];
        $status = 0;
        $headers = [];
        foreach ($rawHeaders as $line) {
            if (str_starts_with($line, 'HTTP/')) {
                $parts = explode(' ', $line);
                $status = (int) ($parts[1] ?? 0);
                $headers = [];

                continue;
            }
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $headers[trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
            }
        }

        $steps[$stepIndex] = [
            'status' => $status,
            'headers' => $headers,
            'body_base64' => base64_encode($body === false ? '' : $body),
        ];
        $previousEtag = (string) it_header($headers, 'ETag');
    }

    $results[$index] = ['site' => $site, 'steps' => $steps];

    // Площадка одна на все сценарии, поэтому проверяем сразу и очищаем.
    $errors = it_verify_one($scenario, $results[$index]);
    if ($errors === []) {
        echo '.';
        $passed++;
    } else {
        echo 'F';
        $failed++;
        $messages[] = $scenario['name'] . "\n    " . implode("\n    ", $errors);
    }
    it_remove($site);
    Fixtures::ensure($site);
}

proc_terminate($server);
proc_close($server);

echo "\n\n";
if ($messages !== []) {
    echo "FAILURES:\n\n";
    foreach ($messages as $i => $message) {
        echo ($i + 1) . ') ' . $message . "\n\n";
    }
}

printf(
    "Integration scenarios: %d, Passed: %d, Failed: %d, Skipped: %d\n",
    count($results),
    $passed,
    $failed,
    $skipped,
);
it_remove($site);
exit($failed === 0 ? 0 : 1);
