<?php

declare(strict_types=1);

/**
 * Портативный раннер тестов: `php tests/run.php`.
 *
 * Не требует composer/PHPUnit — использует минимальный polyfill.
 * Если настоящий PHPUnit установлен, запускайте `vendor/bin/phpunit`
 * (те же самые тесты, тот же API).
 */

$root = dirname(__DIR__);

if (is_file($root . '/vendor/phpunit/phpunit/src/Framework/TestCase.php')) {
    echo "PHPUnit installed — run: vendor/bin/phpunit\n";
    exit(0);
}

require_once __DIR__ . '/Support/phpunit-polyfill.php';
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\SkippedTestError;
use PHPUnit\Framework\TestCase;

// Ограничение набора файлов: IAR_TEST_FILES="LegacyTest.php" — грузить
// только перечисленные файлы (экономит память в ограниченных окружениях).
$fileFilter = defined('IAR_TEST_FILES') ? IAR_TEST_FILES : (string) getenv('IAR_TEST_FILES');
$onlyFiles = $fileFilter === '' ? [] : array_map('trim', explode(',', $fileFilter));

$files = [];
foreach (['Unit', 'Integration'] as $suite) {
    $dir = __DIR__ . '/' . $suite;
    if (!is_dir($dir)) {
        continue;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if (str_ends_with($entry, 'Test.php') && ($onlyFiles === [] || in_array($entry, $onlyFiles, true))) {
            $files[] = $dir . '/' . $entry;
        }
    }
}
sort($files);

// Фильтр классов: IAR_TEST_FILTER="LegacyTest,ConfigTest" — позволяет
// запускать набор частями (например, в WASM-окружении с ограниченной памятью).
$filter = defined('IAR_TEST_FILTER') ? IAR_TEST_FILTER : (string) getenv('IAR_TEST_FILTER');
$only = $filter === '' ? [] : array_map('trim', explode(',', $filter));

// Фильтр методов: позволяет дробить большие наборы (WASM-окружение).
$methodFilter = defined('IAR_TEST_METHOD_FILTER') ? IAR_TEST_METHOD_FILTER : (string) getenv('IAR_TEST_METHOD_FILTER');
$onlyMethods = $methodFilter === '' ? [] : array_map('trim', explode(',', $methodFilter));

$passed = 0;
$failed = 0;
$skipped = 0;
$failures = [];
$skips = [];
$started = microtime(true);

foreach ($files as $file) {
    require_once $file;
}

foreach (get_declared_classes() as $class) {
    if (!is_subclass_of($class, TestCase::class)) {
        continue;
    }

    $reflection = new ReflectionClass($class);
    if ($reflection->isAbstract()) {
        continue;
    }
    if ($only !== [] && !in_array($reflection->getShortName(), $only, true)) {
        continue;
    }

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (!str_starts_with($method->getName(), 'test')) {
            continue;
        }
        if ($onlyMethods !== [] && !in_array($method->getName(), $onlyMethods, true)) {
            continue;
        }

        $dataSets = [[]];
        $attributes = $method->getAttributes(DataProvider::class);
        if ($attributes !== []) {
            $providerName = $attributes[0]->newInstance()->methodName;
            $provider = $reflection->getMethod($providerName);
            $dataSets = $provider->isStatic() ? $provider->invoke(null) : $provider->invoke($reflection->newInstance());
        }

        foreach ($dataSets as $index => $args) {
            $label = $reflection->getShortName() . '::' . $method->getName();
            if ($dataSets !== [[]]) {
                $label .= ' #' . $index;
            }

            $test = $reflection->newInstance();

            try {
                $test->runSetUp();

                try {
                    $method->invokeArgs($test, is_array($args) ? $args : [$args]);
                    $expected = $test->expectedExceptionClass();
                    if ($expected !== null) {
                        throw new AssertionFailedError('Expected exception ' . $expected . ' was not thrown');
                    }
                    $passed++;
                } catch (SkippedTestError $e) {
                    $skipped++;
                    $skips[] = $label . ' — ' . $e->getMessage();
                    echo 'S';
                } catch (AssertionFailedError $e) {
                    $failed++;
                    $failures[] = $label . "\n    " . $e->getMessage();
                    echo 'F';
                } catch (Throwable $e) {
                    $expected = $test->expectedExceptionClass();
                    if ($expected !== null && $e instanceof $expected) {
                        $message = $test->expectedExceptionMessageText();
                        if ($message !== null && !str_contains($e->getMessage(), $message)) {
                            $failed++;
                            $failures[] = $label . "\n    Exception message mismatch: " . $e->getMessage();
                            echo 'F';
                        } else {
                            TestCase::$assertionCount++;
                            $passed++;
                            echo '.';
                        }
                    } else {
                        $failed++;
                        $failures[] = $label . "\n    " . get_class($e) . ': ' . $e->getMessage()
                            . "\n    at " . $e->getFile() . ':' . $e->getLine();
                        echo 'E';
                    }
                } finally {
                    $test->runTearDown();
                }
            } catch (Throwable $e) {
                $failed++;
                $failures[] = $label . " (setUp/tearDown)\n    " . $e->getMessage();
                echo 'E';
            }
        }
    }
}

$elapsed = round((microtime(true) - $started) * 1000);

echo "\n\n";
if ($failures !== []) {
    echo "FAILURES:\n\n";
    foreach ($failures as $i => $failure) {
        echo ($i + 1) . ') ' . $failure . "\n\n";
    }
}

if ($skips !== []) {
    echo "SKIPPED:\n";
    foreach ($skips as $i => $skip) {
        echo '  ' . ($i + 1) . ') ' . $skip . "\n";
    }
    echo "\n";
}

echo sprintf(
    "Tests: %d, Passed: %d, Failed: %d, Skipped: %d, Assertions: %d, Time: %d ms\n",
    $passed + $failed + $skipped,
    $passed,
    $failed,
    $skipped,
    TestCase::$assertionCount,
    $elapsed,
);

exit($failed === 0 ? 0 : 1);
