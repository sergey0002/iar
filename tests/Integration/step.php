<?php

declare(strict_types=1);

/**
 * Один HTTP-запрос к index.php.
 *
 * Два режима:
 *   1) CLI: php tests/Integration/step.php <payload.json> — отдельный процесс
 *      на запрос (так проверяются exit, фатальные ошибки, изоляция кеша);
 *   2) роутер встроенного сервера: php -S ... tests/Integration/step.php,
 *      задание берётся из окружения IAR_STEP_PAYLOAD.
 *
 * Формат задания: {entry, get, server, config, coverage, no_autoload}.
 */

$payloadFile = $argv[1] ?? (string) getenv('IAR_STEP_PAYLOAD');
if ($payloadFile === '' || !is_file($payloadFile)) {
    echo "Usage: php tests/Integration/step.php <payload.json>\n";
    exit(1);
}

/** @var array{entry:string,get:array,server:array,config:?string,coverage:?string,no_autoload?:bool} $payload */
$payload = (array) json_decode((string) file_get_contents($payloadFile), true);

// Статику (в том числе файлы кеша) во встроенном сервере отдаёт сам сервер.
if (PHP_SAPI === 'cli-server') {
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $file = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . $path;
    if ($path !== '/' && is_file($file) && !str_ends_with(strtolower($path), '.php')) {
        return false;
    }
    $_SERVER['SCRIPT_NAME'] = '/iar/index.php';
}

if (($payload['coverage'] ?? null) !== null) {
    $covDir = (string) $payload['coverage'];
    require_once $covDir . '/collector.php';
    IarCoverage::enable($covDir . '/hits');

    if (!($payload['no_autoload'] ?? false)) {
        define('IAR_AUTOLOAD', $covDir . '/autoload.php');
    }
}

if (($payload['config'] ?? null) !== null) {
    define('IAR_CONFIG', (string) $payload['config']);
}

$_GET = (array) ($payload['get'] ?? []);
$_SERVER = array_merge($_SERVER, (array) ($payload['server'] ?? []));
$_REQUEST = $_GET;

require (string) $payload['entry'];
