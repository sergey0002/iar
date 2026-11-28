<?php

declare(strict_types=1);

/**
 * Интеграционные сценарии: реальные HTTP-запросы к index.php
 * (NativeSapi, реальный GD, реальный кеш на диске).
 *
 * Данные, а не код — чтобы один и тот же набор исполнялся и обычным
 * PHP-драйвером (tools/integration.php), и внешним раннером.
 *
 * Плейсхолдеры: {SITE} — корень тестового сайта, {IAR} — корень проекта.
 *
 * Ключи ожиданий:
 *   status, headers, headers_absent, header_present, header_contains, header_matches,
 *   header_same_as_previous, body (empty|nonempty), body_contains,
 *   body_not_contains, body_equals_source, body_differs_from_previous,
 *   image_size, cache_files, log_dir_exists, log_lines, log_contains.
 * Ключи шага: get, server, action, use_previous_etag.
 * Действия: wipe_cache, touch_source, lower_quality, break_vendor.
 * requires: '' | 'webp' | 'avif' | 'imagick' — сценарий пропускается без расширения.
 */

$scenario = static function (string $name, array $steps, array $config = [], string $requires = ''): array {
    return ['name' => $name, 'config' => $config, 'steps' => $steps, 'requires' => $requires];
};

$step = static function (array $get, array $expect, array $server = [], ?string $action = null, bool $etag = false): array {
    return [
        'get' => $get,
        'server' => $server,
        'expect' => $expect,
        'action' => $action,
        'use_previous_etag' => $etag,
    ];
};

return [
    $scenario('basic resize', [
        $step(['name' => 'photo.jpg', 'w' => '300'], [
            'status' => 200,
            'headers' => [
                'Content-Type' => 'image/jpeg',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-cache',
                'Vary' => 'Accept',
            ],
            'body' => 'nonempty',
            'image_size' => [300, 200],
            'cache_files' => ['prefix' => 'photo__300x0', 'count' => 1],
        ]),
    ]),

    $scenario('second request is a cache hit', [
        $step(['name' => 'photo.jpg', 'w' => '250'], ['status' => 200]),
        $step(['name' => 'photo.jpg', 'w' => '250'], [
            'status' => 200,
            'header_same_as_previous' => ['ETag' => 'ETag'],
            'cache_files' => ['prefix' => 'photo__250x0', 'count' => 1],
        ]),
    ]),

    $scenario('conditional request returns full 304', [
        $step(['name' => 'photo.jpg', 'w' => '200'], ['status' => 200]),
        $step(['name' => 'photo.jpg', 'w' => '200'], [
            'status' => 304,
            'body' => 'empty',
            'header_same_as_previous' => ['ETag' => 'ETag'],
            'headers' => ['Cache-Control' => 'no-cache', 'Vary' => 'Accept'],
        ], [], null, true),
    ]),

    $scenario('HEAD has headers but no body', [
        $step(['name' => 'photo.jpg', 'w' => '200'], [
            'status' => 200,
            'body' => 'empty',
            'headers' => ['Content-Type' => 'image/jpeg'],
            'header_present' => ['Content-Length'],
        ], ['REQUEST_METHOD' => 'HEAD']),
    ]),

    $scenario('redirect to cache uses correct relative url', [
        $step(['name' => 'photo.jpg', 'w' => '180'], [
            'status' => 302,
            'header_matches' => ['Location' => '#^/cache/photo__180x0.*\.jpg$#'],
            'headers' => ['Cache-Control' => 'no-cache'],
            'body' => 'empty',
        ]),
    ], ['redirect_to_cache' => true]),

    $scenario('static mode redirect is 301 without immutable', [
        $step(['name' => 'photo.jpg', 'w' => '180'], [
            'status' => 301,
            'headers' => ['Cache-Control' => 'public, max-age=31536000'],
        ]),
    ], ['redirect_to_cache' => true, 'static_mode' => true]),

    $scenario('static mode direct response is immutable', [
        $step(['name' => 'photo.jpg', 'w' => '180'], [
            'status' => 200,
            'headers' => ['Cache-Control' => 'public, max-age=31536000, immutable'],
        ]),
    ], ['static_mode' => true]),

    $scenario('disabled cache sends no-store', [
        $step(['name' => 'photo.jpg', 'w' => '120'], [
            'status' => 200,
            'headers' => [
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ],
            'headers_absent' => ['ETag'],
        ]),
    ], ['disable_cache' => true]),

    $scenario('svg is served with restrictive CSP', [
        $step(['name' => 'icon.svg'], [
            'status' => 200,
            'headers' => ['Content-Type' => 'image/svg+xml'],
            'header_contains' => ['Content-Security-Policy' => "default-src 'none'"],
            'body_contains' => '<svg',
        ]),
    ]),

    $scenario('svg rasterization falls back to original without Imagick', [
        $step(['name' => 'icon.svg', 'fm' => 'png', 'w' => '50'], [
            'status' => 302,
            'headers' => ['Location' => '/icon.svg', 'Cache-Control' => 'no-store'],
        ]),
    ]),

    $scenario('raster to svg is rejected', [
        $step(['name' => 'photo.jpg', 'fm' => 'svg'], [
            'status' => 400,
            'body_contains' => 'Unsupported output format.',
        ]),
    ]),

    $scenario('animated gif keeps its format and frames', [
        $step(['name' => 'anim.gif', 'w' => '4'], [
            'status' => 200,
            'headers' => ['Content-Type' => 'image/gif'],
            'body_contains' => 'GIF89a',
            'headers_absent' => ['Vary'],
        ], ['HTTP_ACCEPT' => 'image/avif,image/webp,*/*']),
    ]),

    $scenario('animated gif refuses lossy format conversion', [
        $step(['name' => 'anim.gif', 'w' => '4', 'fm' => 'webp'], [
            'status' => 302,
            'headers' => [
                'Location' => '/anim.gif',
                'Cache-Control' => 'no-store',
                'X-Iar-Fallback' => 'original',
            ],
        ]),
    ]),

    $scenario('static gif is converted normally', [
        $step(['name' => 'static.gif', 'w' => '4'], [
            'status' => 200,
            'headers' => ['Content-Type' => 'image/gif'],
            'body_contains' => 'GIF8',
            'image_size' => [4, 3],
        ]),
    ]),

    $scenario('webp negotiation by Accept header', [
        $step(['name' => 'photo.jpg', 'w' => '120'], [
            'status' => 200,
            'headers' => ['Content-Type' => 'image/webp', 'Vary' => 'Accept'],
        ], ['HTTP_ACCEPT' => 'image/webp,image/*']),
    ], [], 'webp'),

    $scenario('avif falls back to webp when unsupported', [
        $step(['name' => 'photo.jpg', 'w' => '120'], [
            'status' => 200,
            'headers' => ['Content-Type' => 'image/webp'],
        ], ['HTTP_ACCEPT' => 'image/avif,image/webp']),
    ], [], 'webp'),

    $scenario('uppercase format is normalized', [
        $step(['name' => 'photo.jpg', 'fm' => 'PNG', 'w' => '60'], [
            'status' => 200,
            'headers' => ['Content-Type' => 'image/png'],
            'image_size' => [60, 40],
        ]),
    ]),

    $scenario('array parameter yields neutral 400', [
        $step(['name' => 'photo.jpg', 'w' => ['10']], [
            'status' => 400,
            'body_contains' => 'Bad request.',
            'body_not_contains' => '/www',
            'headers' => ['Cache-Control' => 'no-store'],
        ]),
    ]),

    $scenario('negative dimension yields 400', [
        $step(['name' => 'photo.jpg', 'w' => '-5'], ['status' => 400]),
    ]),

    $scenario('POST is not allowed', [
        $step(['name' => 'photo.jpg'], [
            'status' => 405,
            'headers' => ['Allow' => 'GET, HEAD'],
        ], ['REQUEST_METHOD' => 'POST']),
    ]),

    $scenario('path traversal is blocked', [
        $step(['name' => '../../../etc/passwd'], ['status' => 404]),
    ]),

    $scenario('disallowed extension is forbidden', [
        $step(['name' => 'secret.txt'], ['status' => 403, 'body_contains' => 'Access denied.']),
    ]),

    $scenario('extension list from config is enforced', [
        $step(['name' => 'icon.svg'], ['status' => 403]),
    ], ['allowed_extensions' => ['jpg', 'jpeg', 'png']]),

    $scenario('missing file yields 404', [
        $step(['name' => 'nope.jpg'], ['status' => 404, 'body_contains' => 'File not found.']),
    ]),

    $scenario('oversized megapixels are rejected before decoding', [
        $step(['name' => 'huge.jpg', 'w' => '100'], [
            'status' => 413,
            'body_contains' => 'Source image is too large.',
        ]),
    ]),

    $scenario('oversized bytes are rejected', [
        $step(['name' => 'photo.jpg', 'w' => '100'], ['status' => 413]),
    ], ['max_input_bytes' => 10]),

    $scenario('crop never produces black bars', [
        $step(['name' => 'photo.jpg', 'ct' => '95p', 'fm' => 'png'], [
            'status' => 200,
            'image_size' => [1200, 40],
        ]),
    ]),

    $scenario('crop percent above hundred is rejected', [
        $step(['name' => 'photo.jpg', 'ct' => '150p', 'fm' => 'png'], ['status' => 400]),
    ]),

    $scenario('crop of hundred percent keeps a single pixel row', [
        $step(['name' => 'photo.jpg', 'ct' => '100p', 'fm' => 'png'], [
            'status' => 200,
            'image_size' => [1200, 1],
        ]),
    ]),

    $scenario('crop in pixels above the side length is clamped', [
        $step(['name' => 'photo.jpg', 'cl' => '99999', 'fm' => 'png'], [
            'status' => 200,
            'image_size' => [1, 800],
        ]),
    ]),

    $scenario('dimensions are clamped and never upscaled', [
        $step(['name' => 'photo.jpg', 'w' => '99999'], [
            'status' => 200,
            'image_size' => [1200, 800],
        ]),
    ]),

    $scenario('no-op does not re-encode the original', [
        $step(['name' => 'photo.jpg'], [
            'status' => 200,
            'body_equals_source' => 'photo.jpg',
        ]),
    ]),

    $scenario('wiped cache is regenerated on first request', [
        $step(['name' => 'photo.jpg', 'w' => '150'], ['status' => 200]),
        $step(['name' => 'photo.jpg', 'w' => '150'], [
            'status' => 200,
            'body' => 'nonempty',
            'image_size' => [150, 100],
            'cache_files' => ['prefix' => 'photo__150x0', 'count' => 1],
        ], [], 'wipe_cache'),
    ]),

    $scenario('mtime change invalidates and cleans old variant', [
        $step(['name' => 'photo.jpg', 'w' => '150'], ['status' => 200]),
        $step(['name' => 'photo.jpg', 'w' => '150'], [
            'status' => 200,
            'cache_files' => ['prefix' => 'photo__150x0', 'count' => 1],
        ], [], 'touch_source'),
    ]),

    $scenario('auto clean handles names with brackets', [
        $step(['name' => 'br[1].jpg', 'w' => '100'], ['status' => 200]),
        $step(['name' => 'br[1].jpg', 'w' => '100'], [
            'status' => 200,
            'cache_files' => ['prefix' => 'br[1]__100x0', 'count' => 1],
        ], [], 'touch_source'),
    ]),

    $scenario('unicode file name', [
        $step(['name' => 'фотка.jpg', 'w' => '100'], ['status' => 200, 'image_size' => [100, 67]]),
    ]),

    $scenario('ampersand in file name', [
        $step(['name' => 'a&b.jpg', 'w' => '100'], ['status' => 200]),
    ]),

    $scenario('spaces in file name', [
        $step(['name' => 'photo copy.jpg', 'w' => '100'], ['status' => 200]),
    ]),

    $scenario('uri path beats query name', [
        $step(['name' => 'private.png'], [
            'status' => 200,
            'headers' => ['Content-Type' => 'image/jpeg'],
        ], ['REQUEST_URI' => '/iar/photo.jpg?name=private.png&w=100']),
    ]),

    $scenario('redirect is skipped without document root', [
        $step(['name' => 'photo.jpg', 'w' => '100'], [
            'status' => 200,
            'headers_absent' => ['Location'],
        ], ['DOCUMENT_ROOT' => '']),
    ], ['redirect_to_cache' => true]),

    $scenario('same basename in different directories does not collide', [
        $step(['name' => 'photo.jpg', 'w' => '100'], ['status' => 200]),
        $step(['name' => 'nested/www/photo.jpg', 'w' => '100'], [
            'status' => 200,
            'cache_files' => ['prefix' => 'photo__100x0', 'count' => 2],
        ]),
    ]),

    $scenario('quality change produces a new cache entry', [
        $step(['name' => 'photo.jpg', 'w' => '300'], ['status' => 200]),
        $step(['name' => 'photo.jpg', 'w' => '300'], [
            'status' => 200,
            'body_differs_from_previous' => true,
            'cache_files' => ['prefix' => 'photo__300x0', 'count' => 1],
        ], [], 'lower_quality'),
    ]),

    $scenario('invalidation off keeps the same cache entry', [
        $step(['name' => 'photo.jpg', 'w' => '150'], ['status' => 200]),
        $step(['name' => 'photo.jpg', 'w' => '150'], [
            'status' => 200,
            'cache_files' => ['prefix' => 'photo__150x0', 'count' => 1],
        ], [], 'touch_source'),
    ], ['cache_invalidation' => 'off']),

    $scenario('indexed png for small thumbnails', [
        $step(['name' => 'small.png', 'w' => '50', 'fm' => 'png'], [
            'status' => 200,
            'headers' => ['Content-Type' => 'image/png'],
            'image_size' => [50, 50],
        ]),
    ]),

    $scenario('logging disabled creates no log directory', [
        $step(['name' => 'photo.jpg', 'w' => '100'], [
            'status' => 200,
            'log_dir_exists' => false,
        ]),
    ]),

    $scenario('logging at info level writes one json line per request', [
        $step(['name' => 'photo.jpg', 'w' => '100'], [
            'status' => 200,
            'log_dir_exists' => true,
            'log_lines' => 1,
            'log_contains' => '"event":"miss"',
        ]),
    ], ['logging' => true, 'log_level' => 'info']),

    $scenario('logging at error level stays silent on success', [
        $step(['name' => 'photo.jpg', 'w' => '100'], ['status' => 200]),
        $step(['name' => 'photo.jpg', 'w' => '100'], [
            'status' => 200,
            'headers' => ['X-Iar-Cache' => 'hit'],
            'log_dir_exists' => false,
        ]),
    ], ['logging' => true, 'log_level' => 'error']),

    $scenario('corrupt source falls back to the original', [
        $step(['name' => 'corrupt.jpg', 'w' => '100'], [
            'status' => 302,
            'headers' => ['Location' => '/corrupt.jpg', 'Cache-Control' => 'no-store'],
            'log_dir_exists' => true,
            'log_contains' => '"event":"fail"',
        ]),
    ], ['logging' => true]),

    $scenario('incomplete installation yields a neutral error', [
        $step(['name' => 'photo.jpg', 'w' => '100'], [
            'status' => 500,
            'body_contains' => 'installation is incomplete',
            'body_not_contains' => '/www',
        ], [], 'break_engine'),
    ]),

    $scenario('missing dependencies switch to the dependency-free engine', [
        $step(['name' => 'photo.jpg', 'w' => '100'], [
            'status' => 200,
            'headers' => ['Content-Type' => 'image/jpeg', 'X-Iar-Engine' => 'legacy'],
            'image_size' => [100, 67],
            'body_not_contains' => '/www',
        ], [], 'break_vendor'),
    ]),
];
