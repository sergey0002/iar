<?php

/**
 * Кросс-версионная проверка резервного движка: PHP 7.0 … 8.4.
 *
 * Запуск: php tests/legacy-compat.php
 *
 * Не требует ни Composer, ни PHPUnit, ни автозагрузчика — только GD.
 * Проверяет, что legacy.php:
 *   - разбирается и работает на PHP 7.0 (синтаксис, функции, поведение);
 *   - отдаёт одинаковые результаты на всех версиях;
 *   - соблюдает политики продукта: мгновенный cache hit, точечная очистка,
 *     всегда возвращает файл, анимация не теряется, лог выключен по умолчанию.
 *
 * Код намеренно написан на синтаксисе PHP 7.0.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once dirname(__DIR__) . '/legacy.php';

$GLOBALS['iar_checks'] = 0;
$GLOBALS['iar_failed'] = array();

function iar_ok($condition, $label)
{
    $GLOBALS['iar_checks']++;
    if (!$condition) {
        $GLOBALS['iar_failed'][] = $label;
        echo "FAIL: " . $label . "\n";
    }
}

function iar_same($expected, $actual, $label)
{
    iar_ok($expected === $actual, $label . ' (ожидалось ' . var_export($expected, true) . ', получено ' . var_export($actual, true) . ')');
}

function iar_header($response, $name)
{
    foreach ($response['headers'] as $pair) {
        if (strcasecmp($pair[0], $name) === 0) {
            return $pair[1];
        }
    }

    return null;
}

function iar_body($response)
{
    if ($response['file'] !== null) {
        return (string) file_get_contents($response['file']);
    }

    return (string) $response['body'];
}

function iar_size($bytes)
{
    $im = @imagecreatefromstring($bytes);
    if ($im === false) {
        return array(0, 0);
    }
    $size = array(imagesx($im), imagesy($im));
    imagedestroy($im);

    return $size;
}

function iar_rrmdir($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            iar_rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

// ---------------------------------------------------------------------------
// Окружение
// ---------------------------------------------------------------------------

$site = sys_get_temp_dir() . '/iar_compat_' . bin2hex(random_bytes(5));
mkdir($site, 0777, true);
mkdir($site . '/nested', 0777, true);

$photo = imagecreatetruecolor(120, 80);
imagefill($photo, 0, 0, imagecolorallocate($photo, 200, 40, 40));
imagejpeg($photo, $site . '/photo.jpg', 90);
imagepng($photo, $site . '/small.png');
imagedestroy($photo);

$static = imagecreatetruecolor(8, 6);
imagefill($static, 0, 0, imagecolorallocate($static, 20, 90, 160));
imagetruecolortopalette($static, false, 16);
imagegif($static, $site . '/static.gif');
imagedestroy($static);

// Два кадра GIF: анимация определяется по количеству Graphic Control Extension.
$frame = "\x21\xF9\x04\x00\x0A\x00\x00\x00" . "\x2C\x00\x00\x00\x00\x02\x00\x02\x00\x00" . "\x02\x02\x44\x01\x00";
file_put_contents(
    $site . '/anim.gif',
    "GIF89a\x02\x00\x02\x00\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF" . "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00" . $frame . $frame . "\x3B"
);
file_put_contents($site . '/corrupt.jpg', 'this is not an image');
file_put_contents($site . '/secret.txt', 'secret');
copy($site . '/photo.jpg', $site . '/nested/photo.jpg');

$configFile = $site . '/config.php';
$writeConfig = function ($overrides = array()) use ($site, $configFile) {
    $raw = array_merge(array(
        'images_base_dir' => $site,
        'cache_dir' => $site . '/cache',
        'log_dir' => $site . '/log',
        'redirect_to_cache' => false,
    ), $overrides);
    file_put_contents($configFile, '<?php return ' . var_export($raw, true) . ';');

    return iar_legacy_config($configFile);
};

$server = array(
    'REQUEST_METHOD' => 'GET',
    'SCRIPT_NAME' => '/iar/index.php',
    'REQUEST_URI' => '/iar/index.php',
    'DOCUMENT_ROOT' => $site,
    'HTTP_ACCEPT' => '',
);

$plan = function ($get, $cfg, $extra = array()) use ($server) {
    return iar_legacy_plan($get, array_merge($server, $extra), $cfg);
};

echo 'PHP ' . PHP_VERSION . ' | GD ' . (extension_loaded('gd') ? 'yes' : 'no')
    . ' | webp ' . (function_exists('imagewebp') ? 'yes' : 'no')
    . ' | avif ' . (function_exists('imageavif') ? 'yes' : 'no') . "\n";

// ---------------------------------------------------------------------------
// Политики
// ---------------------------------------------------------------------------

$cfg = $writeConfig();

// 1. Генерация и мгновенная отдача из кеша.
$miss = $plan(array('name' => 'photo.jpg', 'w' => '60'), $cfg);
$hit = $plan(array('name' => 'photo.jpg', 'w' => '60'), $cfg);
iar_same(200, $miss['status'], 'первый запрос отдаёт файл');
iar_same('miss', iar_header($miss, 'X-Iar-Cache'), 'первый запрос — miss');
iar_same('hit', iar_header($hit, 'X-Iar-Cache'), 'повторный запрос — hit');
iar_same(array(60, 40), iar_size(iar_body($hit)), 'размер результата');
iar_same(iar_body($miss), iar_body($hit), 'кеш отдаёт те же байты');
iar_same('legacy', iar_header($hit, 'X-Iar-Engine'), 'движок обозначен в заголовке');

// 2. ETag из ключа + 304 без чтения файла.
$etag = iar_header($hit, 'ETag');
iar_ok(is_string($etag) && strlen($etag) === 34, 'ETag выводится из ключа');
$notModified = $plan(array('name' => 'photo.jpg', 'w' => '60'), $cfg, array('HTTP_IF_NONE_MATCH' => $etag));
iar_same(304, $notModified['status'], 'повторный запрос с ETag — 304');
iar_same(null, $notModified['file'], '304 без тела');

// 3. Разные размеры актуальной версии живут вместе (srcset не ломает кеш).
$plan(array('name' => 'photo.jpg', 'w' => '30'), $cfg);
$again = $plan(array('name' => 'photo.jpg', 'w' => '60'), $cfg);
iar_same('hit', iar_header($again, 'X-Iar-Cache'), 'другой размер не вытеснил предыдущий');
iar_same(2, count(glob($site . '/cache/photo__*')), 'оба размера в кеше');

// 4. Изменение оригинала: новая версия, старые варианты всех размеров удалены.
touch($site . '/photo.jpg', time() + 500);
clearstatcache();
$changed = $plan(array('name' => 'photo.jpg', 'w' => '60'), $cfg);
iar_same('miss', iar_header($changed, 'X-Iar-Cache'), 'изменённый оригинал пересоздан');
iar_ok(iar_header($changed, 'ETag') !== $etag, 'ETag сменился вместе с оригиналом');
iar_same(1, count(glob($site . '/cache/photo__*')), 'устаревшие размеры удалены точечно');

// 5. Кеш полностью удаляемый.
iar_rrmdir($site . '/cache');
$rebuilt = $plan(array('name' => 'photo.jpg', 'w' => '60'), $cfg);
iar_same('miss', iar_header($rebuilt, 'X-Iar-Cache'), 'кеш восстановлен по первому URL');
iar_same(array(60, 40), iar_size(iar_body($rebuilt)), 'восстановленный вариант корректен');

// 6. Режимы и обрезка.
iar_same(array(120, 80), iar_size(iar_body($plan(array('name' => 'photo.jpg', 'w' => '9999'), $cfg))), 'fit не увеличивает');
iar_same(array(40, 40), iar_size(iar_body($plan(array('name' => 'photo.jpg', 'w' => '40', 'h' => '40', 'm' => 'cover'), $cfg))), 'cover даёт точный размер');
iar_same(array(40, 20), iar_size(iar_body($plan(array('name' => 'photo.jpg', 'w' => '40', 'h' => '20', 'm' => 'resize'), $cfg))), 'resize растягивает');
iar_same(array(120, 60), iar_size(iar_body($plan(array('name' => 'photo.jpg', 'ct' => '20'), $cfg))), 'обрезка сверху в пикселях');
iar_same(array(120, 72), iar_size(iar_body($plan(array('name' => 'photo.jpg', 'ct' => '10p'), $cfg))), 'обрезка сверху в процентах');
iar_same(array(1, 80), iar_size(iar_body($plan(array('name' => 'photo.jpg', 'cl' => '99999'), $cfg))), 'обрезка не выходит за границы');

// 7. Форматы.
iar_same('image/png', iar_header($plan(array('name' => 'photo.jpg', 'w' => '40', 'fm' => 'png'), $cfg), 'Content-Type'), 'явный PNG');
iar_same('image/gif', iar_header($plan(array('name' => 'static.gif', 'w' => '4'), $cfg), 'Content-Type'), 'статический GIF');
if (function_exists('imagewebp')) {
    $webp = $plan(array('name' => 'photo.jpg', 'w' => '40'), $cfg, array('HTTP_ACCEPT' => 'image/webp'));
    iar_same('image/webp', iar_header($webp, 'Content-Type'), 'авто-WebP по Accept');
    iar_same('Accept', iar_header($webp, 'Vary'), 'Vary: Accept для авто-формата');
}

// 8. Анимация не теряется: оригинал вместо покадровой порчи.
$anim = $plan(array('name' => 'anim.gif', 'w' => '2'), $cfg);
iar_same(302, $anim['status'], 'анимированный GIF отдаётся оригиналом');
iar_same('/anim.gif', iar_header($anim, 'Location'), 'редирект на оригинал');
iar_same('original', iar_header($anim, 'X-Iar-Fallback'), 'заголовок аварийной отдачи');
iar_same('no-store', iar_header($anim, 'Cache-Control'), 'оригинал не кешируется');
$animCopy = $plan(array('name' => 'anim.gif'), $cfg);
iar_same(file_get_contents($site . '/anim.gif'), iar_body($animCopy), 'анимация без операций копируется побайтово');

// 9. Любой сбой → файл, а не пустота.
$corrupt = $plan(array('name' => 'corrupt.jpg', 'w' => '40'), $cfg);
iar_same(302, $corrupt['status'], 'битый оригинал → редирект на оригинал');
$fallback = iar_legacy_fallback(array('name' => 'photo.jpg'), $server, $cfg, false, 'искусственный сбой');
iar_same(200, $fallback['status'], 'аварийный путь отдаёт оригинал');
iar_same(file_get_contents($site . '/photo.jpg'), iar_body($fallback), 'байты оригинала');
iar_same('no-store', iar_header($fallback, 'Cache-Control'), 'аварийный ответ не кешируется');
$denied = iar_legacy_fallback(array('name' => 'secret.txt'), $server, $cfg, false, 'сбой');
iar_same(500, $denied['status'], 'белый список расширений не обходится');

// 10. Лимиты входа.
$tooBig = $plan(array('name' => 'photo.jpg', 'w' => '40'), $writeConfig(array('max_input_bytes' => 10)));
iar_same(413, $tooBig['status'], 'лимит байтов соблюдается');

// 11. Валидация и безопасность.
$cfg = $writeConfig();
foreach (array(
    array(array('name' => 'nope.jpg'), 404, 'нет файла'),
    array(array('name' => 'secret.txt'), 403, 'запрещённое расширение'),
    array(array('name' => 'photo.jpg', 'w' => '-1'), 400, 'отрицательный размер'),
    array(array('name' => 'photo.jpg', 'ct' => '150p'), 400, 'обрезка больше 100%'),
    array(array('name' => 'photo.jpg', 'fm' => 'svg'), 400, 'растр нельзя в SVG'),
) as $case) {
    try {
        $response = $plan($case[0], $cfg);
        iar_same($case[1], $response['status'], $case[2]);
    } catch (IarLegacyError $e) {
        iar_same($case[1], $e->status(), $case[2]);
    }
}
try {
    $plan(array('name' => 'photo.jpg'), $cfg, array('REQUEST_METHOD' => 'POST'));
    iar_ok(false, 'POST запрещён');
} catch (IarLegacyError $e) {
    iar_same(405, $e->status(), 'POST запрещён');
}

// 12. Редирект на статику и immutable для версионированных файлов.
$redirect = $plan(array('name' => 'photo.jpg', 'w' => '45'), $writeConfig(array('redirect_to_cache' => true)));
iar_same(302, $redirect['status'], 'редирект на файл кеша');
iar_ok(strpos(iar_header($redirect, 'Location'), '/cache/photo__45x0') === 0, 'URL кеша ведёт в cache/');
iar_same('no-cache', iar_header($redirect, 'Cache-Control'), 'редирект не кешируется навсегда');
$staticMode = $plan(array('name' => 'photo.jpg', 'w' => '46'), $writeConfig(array('static_mode' => true)));
iar_same('public, max-age=31536000, immutable', iar_header($staticMode, 'Cache-Control'), 'static_mode отдаёт immutable');

// 13. Лог: выключен по умолчанию, на уровне error молчит при успехе.
$cfg = $writeConfig();
iar_ok(!is_dir($site . '/log'), 'лог выключен по умолчанию');
$cfg = $writeConfig(array('logging' => true));
$plan(array('name' => 'photo.jpg', 'w' => '47'), $cfg);
iar_ok(!is_dir($site . '/log'), 'уровень error не пишет успешные запросы');
$plan(array('name' => 'corrupt.jpg', 'w' => '40'), $cfg);
$logs = glob($site . '/log/*.log');
iar_ok(count($logs) === 1, 'сбой записан в лог');
$record = json_decode(trim(file_get_contents($logs[0])), true);
iar_same('fail', $record['event'], 'событие сбоя');
iar_ok(isset($record['reason']) && $record['reason'] !== '', 'причина сбоя в логе');
iar_rrmdir($site . '/log');
$cfg = $writeConfig(array('logging' => true, 'log_level' => 'info'));
$plan(array('name' => 'photo.jpg', 'w' => '48'), $cfg);
$plan(array('name' => 'photo.jpg', 'w' => '48'), $cfg);
$lines = array_values(array_filter(explode("\n", file_get_contents(glob($site . '/log/*.log')[0]))));
iar_same(2, count($lines), 'уровень info пишет hit и miss');
iar_same('miss', json_decode($lines[0], true)['event'], 'первое событие — miss');
iar_same('hit', json_decode($lines[1], true)['event'], 'второе событие — hit');
iar_ok(!isset(json_decode($lines[1], true)['params']), 'параметры запроса только на уровне debug');

// 14. Точечная очистка чужих файлов не касается.
$cfg = $writeConfig();
$dir = $site . '/cache';
file_put_contents($dir . '/other__10x0__' . md5('other.jpg') . '_1.jpg', 'x');
$before = count(glob($dir . '/*'));
iar_legacy_clean_old($dir, 'photo__', '__' . md5('photo.jpg') . '_', 'keep.jpg', '');
iar_ok(count(glob($dir . '/*')) < $before, 'точечная очистка удалила варианты оригинала');
iar_ok(is_file($dir . '/other__10x0__' . md5('other.jpg') . '_1.jpg'), 'чужие файлы не тронуты');

// 15. Сквозной вызов через index.php (выбор движка по версии PHP).
$_GET = array('name' => 'photo.jpg', 'w' => '55');
$_SERVER = array_merge($server, array('REQUEST_URI' => '/iar/photo.jpg?w=55'));
define('IAR_CONFIG', $configFile);
ob_start();
require dirname(__DIR__) . '/index.php';
$body = ob_get_clean();
iar_same(array(55, 37), iar_size($body), 'index.php отдаёт обработанное изображение');

// ---------------------------------------------------------------------------

iar_rrmdir($site);

echo "\nPHP " . PHP_VERSION . ': проверок ' . $GLOBALS['iar_checks'] . ', провалено ' . count($GLOBALS['iar_failed']) . "\n";
if ($GLOBALS['iar_failed'] !== array()) {
    echo "ПРОВАЛЕНО\n";
    exit(1);
}
echo "OK\n";
exit(0);
