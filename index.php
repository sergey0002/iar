<?php

declare(strict_types=1);

/**
 * IAR — Image Auto Resizer. Точка входа.
 *
 * Портативный обработчик изображений: положите папку в корень сайта,
 * обращайтесь к картинкам через неё (/iar/path/to/image.jpg?w=800).
 * Кеш полностью удаляемый — варианты пересоздаются при первом обращении.
 *
 * Вся логика вынесена в src/ и покрыта тестами (см. docs/audit.md,
 * docs/remediation-plan.md). Здесь только сборка и запуск.
 */

// Тестовый шов для измерения покрытия; в production константа не определена.
$iarAutoload = defined('IAR_AUTOLOAD') ? IAR_AUTOLOAD : __DIR__ . '/vendor/autoload.php';

if (!is_file($iarAutoload)) {
    // Зависимости не установлены: нейтральная ошибка вместо фатала с путями.
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo "IAR dependencies are not installed. Run \"composer install\" in the IAR directory.\n";

    return;
}

require_once $iarAutoload;

// Тестовые швы: в production ни глобал, ни константа не заданы.
$iarSapi = $GLOBALS['__iar_sapi'] ?? new App\Http\NativeSapi();
$iarConfig = defined('IAR_CONFIG') ? IAR_CONFIG : null;

App\Bootstrap::run($iarSapi, $_GET, $_SERVER, $iarConfig);
