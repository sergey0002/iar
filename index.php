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

// Выбор движка (политика «работает на PHP 7+ и без зависимостей»):
//   - PHP >= 8.1 и установленный Composer → основной движок (Intervention,
//     AVIF, растеризация SVG через Imagick);
//   - иначе → legacy.php: чистый GD, синтаксис PHP 7.0, ноль зависимостей.
// Оба движка используют один и тот же URL API, один и тот же кеш и одну
// и ту же политику заголовков, поэтому переключение незаметно для браузера.
if (PHP_VERSION_ID < 80100 || !is_file($iarAutoload)) {
    $iarLegacy = defined('IAR_LEGACY') ? IAR_LEGACY : __DIR__ . '/legacy.php';
    if (!is_file($iarLegacy)) {
        // Установка неполная: нейтральная ошибка вместо фатала с путями.
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo "IAR installation is incomplete: legacy.php is missing.\n";

        return;
    }

    require_once $iarLegacy;
    iar_legacy_run($_GET, $_SERVER, defined('IAR_CONFIG') ? IAR_CONFIG : null);

    return;
}

require_once $iarAutoload;

// Тестовые швы: в production ни глобал, ни константа не заданы.
$iarSapi = $GLOBALS['__iar_sapi'] ?? new App\Http\NativeSapi();
$iarConfig = defined('IAR_CONFIG') ? IAR_CONFIG : null;

App\Bootstrap::run($iarSapi, $_GET, $_SERVER, $iarConfig);
