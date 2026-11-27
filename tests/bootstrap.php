<?php

declare(strict_types=1);

/**
 * Bootstrap тестов. Использует composer-autoload, если он есть;
 * иначе подключает портативный PSR-4 автозагрузчик (концепция: тесты
 * запускаются и без composer — `php tests/run.php`).
 */

$composer = defined('IAR_AUTOLOAD') ? IAR_AUTOLOAD : dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composer)) {
    require_once $composer;
} else {
    spl_autoload_register(static function (string $class): void {
        $map = [
            'App\\Tests\\' => __DIR__ . '/',
            'App\\' => dirname(__DIR__) . '/src/',
            'Intervention\\Image\\' => dirname(__DIR__) . '/vendor/intervention/image/src/',
            'Intervention\\Gif\\' => dirname(__DIR__) . '/vendor/intervention/gif/src/',
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
}

// Заглушка Imagick подключается только при отсутствии расширения.
require_once __DIR__ . '/Support/imagick-stub.php';

require_once __DIR__ . '/Support/Fixtures.php';
require_once __DIR__ . '/Support/ExitCalled.php';
require_once __DIR__ . '/Support/TestSapi.php';
