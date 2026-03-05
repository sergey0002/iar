<?php

/**
 * Modern Image Resizer Entry Point
 * Portability & Security First.
 *
 * Fixes applied (audit 2026-03-05):
 *   SEC-01 Path Traversal: baseDir normalized + trailing separator guard
 *   SEC-02 mode/gravity whitelist validation
 *   SEC-03 ETag via filemtime+filesize (no full-file read)
 *   SEC-04 fm (format) whitelist validation
 *   IMP-04 zero w/h guard passed to ResizeService
 *   IMP-12 crop value upper bound
 *   IMP Vary: Accept header for auto_format
 */

require_once __DIR__ . '/vendor/autoload.php';

$config = require __DIR__ . '/config.php';

use App\ResizeService;

// ============================================================
// 1. Получение и санирование параметра name
// ============================================================
$name = $_GET['name'] ?? null;
if (!$name) {
	http_response_code(400);
	die("Parameter 'name' is required.");
}

// ============================================================
// 2. SEC-01: Path Traversal защита (усиленная)
//    - baseDir нормализован через realpath()
//    - завершающий DIRECTORY_SEPARATOR гарантирует, что
//      /var/www/site не совпадёт с /var/www/site-evil/
// ============================================================
$baseDir = rtrim(realpath($config['images_base_dir']), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

// Убираем ведущие слэши из name чтобы не сломать realpath
$name = ltrim($name, '/\\');
$filePath = realpath($baseDir . $name);

if (!$filePath || strncmp($filePath, $baseDir, strlen($baseDir)) !== 0) {
	http_response_code(403);
	die("Access denied.");
}

if (!is_file($filePath)) {
	http_response_code(404);
	die("File not found.");
}

// ============================================================
// 3. Проверка расширения по белому списку
// ============================================================
$extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
if (!in_array($extension, $config['allowed_extensions'], true)) {
	http_response_code(403);
	die("Unsupported file extension.");
}

// ============================================================
// 4. SEC-02: Валидация режима и гравитации по белому списку
// ============================================================
$allowedModes = ['fit', 'cover', 'resize'];
$allowedGravity = [
	'center',
	'top',
	'bottom',
	'left',
	'right',
	'top-left',
	'top-right',
	'bottom-left',
	'bottom-right',
];

$rawMode = $_GET['m'] ?? '';
$rawGravity = $_GET['g'] ?? '';
$mode = in_array($rawMode, $allowedModes, true) ? $rawMode : $config['default_mode'];
$gravity = in_array($rawGravity, $allowedGravity, true) ? $rawGravity : $config['default_gravity'];

// ============================================================
// 5. Размеры (w, h) — целые числа, ограниченные max
// ============================================================
$w = isset($_GET['w']) ? (int) $_GET['w'] : 0;
$h = isset($_GET['h']) ? (int) $_GET['h'] : 0;

// IMP-04: нулевые size — передаём как 0 (ResizeService их обработает как «нет ресайза»)
$w = max(0, min($w, $config['max_width']));
$h = max(0, min($h, $config['max_height']));

// ============================================================
// 6. SEC-04: Валидация fm (output format) по белому списку
// ============================================================
$outputFormat = null;
if (isset($_GET['fm']) && $_GET['fm'] !== '') {
	$fm = strtolower(preg_replace('/[^a-z]/', '', (string) $_GET['fm']));
	if (in_array($fm, $config['allowed_extensions'], true)) {
		$outputFormat = $fm;
	}
}

// ============================================================
// 7. Ручная обрезка краёв (ct, cr, cb, cl) — одна сторона
// ============================================================
$cropParam = null;
$found = 0;
foreach (['ct', 'cr', 'cb', 'cl'] as $side) {
	if (isset($_GET[$side]) && $_GET[$side] !== '') {
		if ($found === 0) {
			$val = trim(urldecode((string) $_GET[$side]));
			$isPercent = (str_ends_with($val, 'p') || str_ends_with($val, '%'));
			// IMP-12: ограничение сверху (защита от ?ct=999999999)
			$numVal = min((float) rtrim($val, 'p%'), 10000);
			$cropParam = [
				'side' => $side,
				'value' => $numVal,
				'unit' => $isPercent ? 'p' : 'px',
			];
		}
		$found++;
	}
}
if ($found > 1) {
	error_log('[iar] Multiple crop params, using ' . ($cropParam['side'] ?? '-') . ' only.');
}

// ============================================================
// 8. Auto-Format / Content Negotiation
// ============================================================
$targetExt = $outputFormat ?: $extension;

if (($config['auto_format'] ?? true) && !$outputFormat) {
	$acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
	if (
		str_contains($acceptHeader, 'image/avif')
		&& function_exists('imageavif')
		&& in_array('avif', $config['allowed_extensions'], true)
	) {
		$targetExt = 'avif';
	} elseif (
		str_contains($acceptHeader, 'image/webp')
		&& in_array('webp', $config['allowed_extensions'], true)
	) {
		$targetExt = 'webp';
	}
}

// ============================================================
// 9. Формирование cache-ключа
//    Формат: [name]__[w]x[h][crop][mode]__[md5-path-10]_[mtime].[ext]
// ============================================================
$relativePath = ltrim(str_replace(rtrim($baseDir, DIRECTORY_SEPARATOR), '', $filePath), '/\\');
$pathHash = substr(md5($relativePath), 0, 10);
$mtime = ($config['cache_invalidation'] === 'mtime') ? filemtime($filePath) : 0;

// Суффикс кропа
$cropSuffix = '';
if ($cropParam) {
	$cropSuffix = '_' . $cropParam['side'] . $cropParam['value'] . ($cropParam['unit'] === 'p' ? 'p' : '');
}

// Суффикс режима/гравитации (только если задан явно)
$modeSuffix = '';
if ($mode !== 'fit') {
	$modeSuffix = '_m-' . $mode;
	if ($mode === 'cover' && $gravity !== 'center') {
		$modeSuffix .= '-g-' . $gravity;
	}
}

$cacheKey = pathinfo($filePath, PATHINFO_FILENAME)
	. '__' . $w . 'x' . $h
	. $cropSuffix
	. $modeSuffix
	. '__' . $pathHash
	. '_' . $mtime;

$cacheFile = rtrim($config['cache_dir'], '/\\') . DIRECTORY_SEPARATOR . $cacheKey . '.' . $targetExt;

// ============================================================
// 10. Cache Hit: отдача существующего кеша
// ============================================================
if (file_exists($cacheFile)) {
	serveFile($cacheFile, $targetExt, $config);
	exit;
}

// ============================================================
// 11. Cache Miss: генерация изображения
// ============================================================
if (!is_dir($config['cache_dir'])) {
	mkdir($config['cache_dir'], 0755, true);
}

// Авто-очистка старых версий кеша при обновлении оригинала
if (($config['cache_auto_clean'] ?? false) && $mtime > 0) {
	$pattern = rtrim($config['cache_dir'], '/\\') . DIRECTORY_SEPARATOR
		. pathinfo($filePath, PATHINFO_FILENAME) . '__' . $w . 'x' . $h . '*' . $pathHash . '_*';
	foreach (glob($pattern) as $oldCache) {
		@unlink($oldCache);
	}
}

$service = new ResizeService($config);
$params = [
	'w' => $w,
	'h' => $h,
	'mode' => $mode,
	'gravity' => $gravity,
	'crop' => $cropParam,
];

if ($service->process($filePath, $cacheFile, $params, $targetExt)) {
	serveFile($cacheFile, $targetExt, $config);
} else {
	http_response_code(500);
	die("Image processing failed.");
}

// ============================================================
// Функция отдачи файла с правильными заголовками
// ============================================================
function serveFile(string $path, string $ext, array $config): void
{
	$mimes = [
		'jpg' => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png' => 'image/png',
		'gif' => 'image/gif',
		'webp' => 'image/webp',
		'avif' => 'image/avif',
		'svg' => 'image/svg+xml',
	];
	$mime = $mimes[strtolower($ext)] ?? 'application/octet-stream';

	// SEC-03: ETag через mtime+size — без чтения файла целиком
	$etag = '"' . md5(filemtime($path) . filesize($path)) . '"';

	// Поддержка 304 Not Modified
	if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
		http_response_code(304);
		exit;
	}

	header('Content-Type: ' . $mime);
	header('Content-Length: ' . filesize($path));
	header('Cache-Control: public, max-age=31536000, immutable');
	header('ETag: ' . $etag);

	// Vary: Accept — сообщаем CDN/прокси, что ответ зависит от Accept-заголовка
	if ($config['auto_format'] ?? true) {
		header('Vary: Accept');
	}

	readfile($path);
}