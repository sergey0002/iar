<?php

declare(strict_types=1);

/**
 * Юнит-тесты с портативным измерением покрытия.
 *
 *   php tools/coverage-portable.php build
 *   php tests/coverage-run.php
 *   php tools/coverage-portable.php report
 *
 * Работает без composer, PHPUnit, Xdebug и PCOV.
 */

$root = dirname(__DIR__);
$covDir = $root . '/.cov';

if (!is_file($covDir . '/autoload.php')) {
    echo "Instrumented tree is missing. Run: php tools/coverage-portable.php build\n";
    exit(1);
}

require_once $covDir . '/collector.php';
IarCoverage::enable($covDir . '/hits');

define('IAR_AUTOLOAD', $covDir . '/autoload.php');
define('IAR_LEGACY', $covDir . '/legacy.php');

require $root . '/tests/run.php';
