<?php

/**
 * IAR — резервный движок: PHP 7.0+, только GD, ноль внешних зависимостей.
 *
 * Зачем он нужен (политики продукта):
 *   1. «Гарантированно работает на PHP 7+». Основной движок (src/) использует
 *      Intervention Image 3.x, который требует PHP >= 8.1. Этот файл не имеет
 *      зависимостей и написан на синтаксисе PHP 7.0, поэтому IAR работает
 *      и на PHP 7.0, и на 8.x.
 *   2. «Минимум зависимостей / положил папку в корень и забыл». Если Composer
 *      не запускался и vendor/ отсутствует, картинки всё равно отдаются:
 *      index.php автоматически выбирает этот движок.
 *   3. «Всегда вернуть файл». Любой сбой, включая фатальный, приводит к отдаче
 *      оригинала, а не к пустому ответу.
 *
 * Совместимость с основным движком полная:
 *   - тот же URL API (те же параметры, те же ответы);
 *   - ТОТ ЖЕ кеш: имена файлов строятся по тем же правилам (см. CacheKey),
 *     поэтому оба движка читают и пишут одни и те же варианты, а tools/cache.php
 *     одинаково чистит кеш любого из них;
 *   - та же политика заголовков (ETag из ключа, no-cache, Vary, 304, HEAD);
 *   - тот же формат отладочного лога (JSON, одна строка на событие).
 *
 * Отличия от основного движка (сознательные, ради совместимости с PHP 7.0):
 *   - анимированные GIF/WebP отдаются как оригинал: GD умеет читать только
 *     первый кадр, а терять анимацию политика запрещает;
 *   - SVG отдаётся как есть (растеризация требует Imagick; если он есть —
 *     растеризуем);
 *   - AVIF/WebP доступны только если соответствующая функция есть в GD.
 *
 * Файл не подключает ничего из src/ и не требует автозагрузчика.
 */

if (!function_exists('iar_legacy_run')) {

    /** Ошибка с HTTP-статусом: аналог App\Exception\IarException. */
    class IarLegacyError extends Exception
    {
        private $status;
        private $publicMessage;

        public function __construct($status, $publicMessage, $reason)
        {
            parent::__construct($reason);
            $this->status = $status;
            $this->publicMessage = $publicMessage;
        }

        public function status()
        {
            return $this->status;
        }

        public function publicMessage()
        {
            return $this->publicMessage;
        }
    }

    // =====================================================================
    // Точка входа
    // =====================================================================

    /**
     * Обработка запроса: план ответа + отправка. Никогда не бросает наружу.
     *
     * @param array $get
     * @param array $server
     * @param string|null $configFile
     */
    function iar_legacy_run($get, $server, $configFile = null, $planner = null)
    {
        @ini_set('display_errors', '0');
        @ini_set('log_errors', '1');

        $file = $configFile !== null ? $configFile : dirname(__FILE__) . '/config.php';
        $cfg = iar_legacy_config($file);
        $head = strtoupper(iar_legacy_str($server, 'REQUEST_METHOD', 'GET')) === 'HEAD';

        register_shutdown_function('iar_legacy_shutdown', $get, $server, $cfg, $head);

        $plan = $planner === null ? 'iar_legacy_plan' : $planner;

        try {
            $response = $plan($get, $server, $cfg);
        } catch (IarLegacyError $e) {
            if ($e->status() >= 500) {
                $response = iar_legacy_fallback($get, $server, $cfg, $head, $e->getMessage());
            } else {
                $response = iar_legacy_error_response($e, $head);
            }
        } catch (Throwable $e) {
            // Throwable, а не Exception: на PHP 7+ сюда попадают и Error
            // (TypeError, DivisionByZeroError) — картинка всё равно отдаётся.
            $response = iar_legacy_fallback($get, $server, $cfg, $head, $e->getMessage());
        }

        iar_legacy_emit($response);
    }

    /**
     * Фатальная ошибка (в том числе нехватка памяти) не перехватывается
     * try/catch — последний рубеж отдаёт оригинал.
     */
    function iar_legacy_shutdown($get, $server, $cfg, $head, $error = null, $sent = null)
    {
        $error = $error === null ? error_get_last() : $error;
        $sent = $sent === null ? headers_sent() : $sent;
        if ($error === null || $sent) {
            return;
        }

        $fatal = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
        if (!in_array($error['type'], $fatal, true)) {
            return;
        }

        error_log('[iar] fatal: ' . $error['message']);
        iar_legacy_log($cfg, 'fail', array(
            'reason' => $error['message'],
            'status' => 200,
            'url' => iar_legacy_str($server, 'REQUEST_URI', ''),
            'stage' => 'fatal',
        ));

        iar_legacy_emit(iar_legacy_fallback($get, $server, $cfg, $head, $error['message'], false));
    }

    // =====================================================================
    // Конфигурация (толерантная: невалидное значение = дефолт + запись в лог)
    // =====================================================================

    /** @return array */
    function iar_legacy_config($file)
    {
        $raw = array();
        if (is_string($file) && is_file($file)) {
            $loaded = include $file;
            if (is_array($loaded)) {
                $raw = $loaded;
            }
            $local = dirname($file) . '/config.local.php';
            if (is_file($local)) {
                $override = include $local;
                if (is_array($override)) {
                    $raw = array_merge($raw, $override);
                }
            }
        }

        $base = iar_legacy_str($raw, 'images_base_dir', dirname(dirname(__FILE__)));
        $real = $base === '' ? false : realpath($base);
        if ($real === false || !is_dir($real)) {
            // Политика надёжности важнее fail-fast: корень сайта вместо
            // несуществующего каталога, причина уходит в лог.
            $real = realpath(dirname(dirname(__FILE__)));
            $raw['iar_base_dir_problem'] = true;
        }

        $extensions = isset($raw['allowed_extensions']) && is_array($raw['allowed_extensions'])
            ? $raw['allowed_extensions']
            : array('jpg', 'jpeg', 'gif', 'png', 'webp', 'avif', 'svg');
        $allowed = array();
        foreach ($extensions as $ext) {
            if (is_string($ext) && $ext !== '') {
                $allowed[] = strtolower($ext);
            }
        }
        if ($allowed === array()) {
            $allowed = array('jpg', 'jpeg', 'gif', 'png', 'webp', 'avif', 'svg');
        }

        $level = iar_legacy_str($raw, 'log_level', 'error');
        if (!in_array($level, array('error', 'info', 'debug'), true)) {
            $level = 'error';
        }

        $invalidation = iar_legacy_str($raw, 'cache_invalidation', 'mtime');
        if (!in_array($invalidation, array('mtime', 'off'), true)) {
            $invalidation = 'mtime';
        }

        $mode = strtolower(iar_legacy_str($raw, 'default_mode', 'fit'));
        if (!in_array($mode, array('fit', 'cover', 'resize'), true)) {
            $mode = 'fit';
        }

        $gravity = strtolower(iar_legacy_str($raw, 'default_gravity', 'center'));
        if (!in_array($gravity, iar_legacy_gravities(), true)) {
            $gravity = 'center';
        }

        $dir = dirname(__FILE__);

        return array(
            'base' => rtrim($real, '/\\') . DIRECTORY_SEPARATOR,
            'base_problem' => isset($raw['iar_base_dir_problem']),
            'allowed' => array_values(array_unique($allowed)),
            'max_width' => iar_legacy_int($raw, 'max_width', 2000, 1),
            'max_height' => iar_legacy_int($raw, 'max_height', 2000, 1),
            'jpeg_quality' => iar_legacy_int($raw, 'jpeg_quality', 82, 1, 100),
            'webp_quality' => iar_legacy_int($raw, 'webp_quality', 85, 1, 100),
            'avif_quality' => iar_legacy_int($raw, 'avif_quality', 75, 1, 100),
            'png_indexed' => iar_legacy_bool($raw, 'png_indexed', true),
            'png_indexed_threshold' => iar_legacy_int($raw, 'png_indexed_threshold', 200, 0),
            'jpeg_progressive' => iar_legacy_bool($raw, 'jpeg_progressive', true),
            'webp_strip_metadata' => iar_legacy_bool($raw, 'webp_strip_metadata', true),
            'lossless' => iar_legacy_bool($raw, 'lossless', false),
            'auto_format' => iar_legacy_bool($raw, 'auto_format', true),
            'default_mode' => $mode,
            'default_gravity' => $gravity,
            'cache_invalidation' => $invalidation,
            'cache_auto_clean' => iar_legacy_bool($raw, 'cache_auto_clean', true),
            'redirect_to_cache' => iar_legacy_bool($raw, 'redirect_to_cache', true),
            'static_mode' => iar_legacy_bool($raw, 'static_mode', false),
            'disable_cache' => iar_legacy_bool($raw, 'disable_cache', false),
            'cache_key_config_hash' => iar_legacy_bool($raw, 'cache_key_config_hash', true),
            'max_input_megapixels' => iar_legacy_int($raw, 'max_input_megapixels', 40, 1),
            'max_input_bytes' => iar_legacy_int($raw, 'max_input_bytes', 20000000, 1),
            'cache_dir' => iar_legacy_str($raw, 'cache_dir', $dir . '/cache'),
            'aliases_file' => iar_legacy_str($raw, 'aliases_file', $dir . '/aliases.json'),
            'log_dir' => iar_legacy_str($raw, 'log_dir', $dir . '/log'),
            'logging' => iar_legacy_bool($raw, 'logging', false),
            'log_level' => $level,
        );
    }

    /** Отпечаток настроек качества — побайтово как App\Config::qualityFingerprint(). */
    function iar_legacy_fingerprint($cfg)
    {
        if (!$cfg['cache_key_config_hash']) {
            return '';
        }

        return '_' . substr(md5(implode('|', array(
            $cfg['jpeg_quality'], $cfg['webp_quality'], $cfg['avif_quality'],
            (int) $cfg['lossless'], (int) $cfg['png_indexed'], $cfg['png_indexed_threshold'],
            (int) $cfg['jpeg_progressive'], (int) $cfg['webp_strip_metadata'],
        ))), 0, 8);
    }


    // =====================================================================
    // Маршруты из aliases.json: псевдонимы каталогов, пресеты и макросы
    // последней папки. Правила те же, что в App\Http\PathRouter.
    // =====================================================================

    /** Загрузка JSON маршрутов; сломанный файл не роняет отдачу картинок. */
    function iar_legacy_router($file, $base)
    {
        $empty = array('aliases' => array(), 'presets' => array(), 'macros' => false,
            'width' => 'x-', 'height' => 'y-', 'base' => $base, 'error' => null);

        if (!is_string($file) || !is_file($file)) {
            return $empty;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            $empty['error'] = 'Aliases file is not readable.';

            return $empty;
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            $empty['error'] = 'Aliases file must contain a JSON object.';

            return $empty;
        }

        if (isset($json['enabled']) && $json['enabled'] !== true) {
            return $empty;
        }

        $aliases = array();
        foreach (iar_legacy_section($json, 'aliases') as $key => $value) {
            $name = iar_legacy_norm_alias((string) $key);
            $target = iar_legacy_norm_target($value);
            if ($name !== null && $target !== null) {
                $aliases[$name] = $target;
            }
        }
        // Длинные префиксы важнее коротких: "a/b" побеждает "a".
        uksort($aliases, 'iar_legacy_alias_order');

        $presets = array();
        foreach (iar_legacy_section($json, 'presets') as $key => $value) {
            $name = (string) $key;
            if ($name === '' || strpos($name, '_') === 0 || strpos($name, '/') !== false || !is_array($value)) {
                continue;
            }
            $params = iar_legacy_norm_params($value);
            if ($params !== array()) {
                $presets[$name] = $params;
            }
        }

        $macros = iar_legacy_section($json, 'macros');

        return array(
            'aliases' => $aliases,
            'presets' => $presets,
            'macros' => !isset($macros['enabled']) || $macros['enabled'] === true,
            'width' => iar_legacy_prefix(isset($macros['width']) ? $macros['width'] : 'x-', 'x-'),
            'height' => iar_legacy_prefix(isset($macros['height']) ? $macros['height'] : 'y-', 'y-'),
            'base' => $base,
            'error' => null,
        );
    }

    function iar_legacy_alias_order($a, $b)
    {
        $diff = substr_count($b, '/') - substr_count($a, '/');

        return $diff !== 0 ? $diff : strcmp($a, $b);
    }

    /**
     * Разбор пути: сначала пресет/макро последней папки, затем псевдоним
     * каталога. Реальный файл всегда важнее виртуального маршрута.
     */
    function iar_legacy_route($router, $relative)
    {
        $plain = array('name' => $relative, 'original' => $relative, 'params' => array(),
            'alias' => null, 'preset' => null);

        // Разделители нормализуем до срезания крайних слэшей.
        $path = ltrim(str_replace('\\', '/', $relative), '/');
        $empty = $router['aliases'] === array() && $router['presets'] === array() && !$router['macros'];
        // Файл в корне не может содержать папку-пресет или префикс псевдонима.
        if ($empty || strpos($path, '/') === false || strpos($path, "\0") !== false) {
            return $plain;
        }

        $segments = explode('/', $path);
        $params = array();
        $preset = null;

        if (count($segments) >= 2) {
            $folder = $segments[count($segments) - 2];
            $found = iar_legacy_folder_params($router, $folder);
            if ($found !== null) {
                $params = $found[0];
                $preset = $found[1];
                array_splice($segments, count($segments) - 2, 1);
            }
        }

        $rest = implode('/', $segments);
        $alias = null;
        $name = $rest;
        foreach ($router['aliases'] as $key => $target) {
            if (strpos($rest, $key . '/') === 0) {
                $name = $target . substr($rest, strlen($key) + 1);
                $alias = $key;
                break;
            }
        }

        if ($params === array() && $alias === null) {
            return $plain;
        }

        // Обратная совместимость: буквальный путь выигрывает у маршрута.
        if ($router['base'] !== null && is_file($router['base'] . $path)) {
            return $plain;
        }

        return array('name' => $name, 'original' => $relative, 'params' => $params,
            'alias' => $alias, 'preset' => $preset);
    }

    /** Пресет важнее маски; макрос даёт ровно один параметр. */
    function iar_legacy_folder_params($router, $folder)
    {
        if (isset($router['presets'][$folder])) {
            return array($router['presets'][$folder], $folder);
        }

        if (!$router['macros']) {
            return null;
        }

        foreach (array(array($router['width'], 'w'), array($router['height'], 'h')) as $pair) {
            list($prefix, $key) = $pair;
            if (strpos($folder, $prefix) !== 0) {
                continue;
            }
            $number = substr($folder, strlen($prefix));
            if ($number === '' || strlen($number) > 6 || !ctype_digit($number) || (int) $number < 1) {
                continue;
            }

            return array(array($key => $number), $folder);
        }

        return null;
    }

    function iar_legacy_section($json, $key)
    {
        return isset($json[$key]) && is_array($json[$key]) ? $json[$key] : array();
    }

    function iar_legacy_safe_path($path)
    {
        if (strpos($path, "\0") !== false || strpos($path, '://') !== false
            || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' || $segment === '.') {
                return false;
            }
        }

        return true;
    }

    function iar_legacy_norm_alias($key)
    {
        $key = trim(str_replace('\\', '/', $key), '/');
        if ($key === '' || strpos($key, '_') === 0 || !iar_legacy_safe_path($key)) {
            return null;
        }

        return $key;
    }

    function iar_legacy_norm_target($value)
    {
        if (!is_string($value)) {
            return null;
        }
        $target = trim(str_replace('\\', '/', $value), '/');
        if ($target === '') {
            return '';
        }
        if (!iar_legacy_safe_path($target)) {
            return null;
        }

        return $target . '/';
    }

    /** Только белый список параметров URL API и только скаляры. */
    function iar_legacy_norm_params($value)
    {
        $allowed = array('w', 'h', 'm', 'g', 'fm', 'ct', 'cr', 'cb', 'cl');
        $params = array();
        foreach ($value as $key => $item) {
            if (!in_array((string) $key, $allowed, true) || !is_scalar($item)) {
                continue;
            }
            $params[(string) $key] = (string) $item;
        }

        return $params;
    }

    function iar_legacy_prefix($value, $default)
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{1,16}$/', $value) !== 1) {
            return $default;
        }

        return $value;
    }

    /** Параметры пути — значения по умолчанию: явный ?w= всегда важнее. */
    function iar_legacy_merge_params($get, $path)
    {
        foreach ($path as $key => $value) {
            $current = isset($get[$key]) ? $get[$key] : null;
            if (!array_key_exists($key, $get) || (is_string($current) && trim($current) === '')) {
                $get[$key] = $value;
            }
        }

        return $get;
    }

    // =====================================================================
    // Разбор запроса
    // =====================================================================

    /** Имя файла из URI (приоритет) или ?name=. NULL, если имени нет. */
    function iar_legacy_name($get, $server)
    {
        $scriptDir = rtrim(str_replace('\\', '/', dirname(iar_legacy_str($server, 'SCRIPT_NAME', '/index.php'))), '/');
        $path = parse_url(iar_legacy_str($server, 'REQUEST_URI', ''), PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '';

        $relative = '';
        if ($scriptDir !== '' && $scriptDir !== '/' && strpos($path, $scriptDir . '/') === 0) {
            $relative = substr($path, strlen($scriptDir) + 1);
        } elseif ($path !== '') {
            $relative = ltrim($path, '/');
        }

        $isImage = $relative !== ''
            && substr(strtolower($relative), -4) !== '.php'
            && strpos($relative, '.') !== false;

        if (!$isImage) {
            $raw = isset($get['name']) ? $get['name'] : null;
            $relative = is_string($raw) ? $raw : '';
        }

        if ($relative === '' || strpos($relative, "\0") !== false) {
            return null;
        }

        return $relative;
    }

    /** Путь к оригиналу внутри корня оригиналов; только разрешённые расширения. */
    function iar_legacy_source($cfg, $name)
    {
        $resolved = realpath($cfg['base'] . ltrim($name, '/\\'));
        if ($resolved === false) {
            throw new IarLegacyError(404, 'File not found.', 'Source file not found: ' . $name);
        }
        if (strpos($resolved, $cfg['base']) !== 0) {
            throw new IarLegacyError(403, 'Access denied.', 'Path is outside the images base directory.');
        }
        if (!is_file($resolved)) {
            throw new IarLegacyError(404, 'File not found.', 'Source is not a file.');
        }

        $ext = strtolower((string) pathinfo($resolved, PATHINFO_EXTENSION));
        if (!in_array($ext, $cfg['allowed'], true)) {
            throw new IarLegacyError(403, 'Access denied.', 'Extension is not allowed: ' . $ext);
        }

        return $resolved;
    }

    /** Параметры обработки; бросает IarLegacyError(400) на невалидный ввод. */
    function iar_legacy_params($get, $cfg)
    {
        $crop = null;
        $found = 0;
        foreach (array('ct', 'cr', 'cb', 'cl') as $side) {
            if (!array_key_exists($side, $get)) {
                continue;
            }
            $raw = $get[$side];
            if (!is_string($raw) && !is_int($raw) && !is_float($raw)) {
                throw new IarLegacyError(400, 'Bad request.', 'Parameter "' . $side . '" must be scalar.');
            }
            $raw = trim((string) $raw);
            if ($raw === '') {
                continue;
            }
            $found++;
            if ($crop === null) {
                $crop = iar_legacy_crop($side, $raw);
            }
        }

        $mode = iar_legacy_enum($get, 'm', array('fit', 'cover', 'resize'));
        $gravity = iar_legacy_enum($get, 'g', iar_legacy_gravities());
        $format = iar_legacy_enum($get, 'fm', array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'));

        return array(
            'width' => min(iar_legacy_dimension($get, 'w'), $cfg['max_width']),
            'height' => min(iar_legacy_dimension($get, 'h'), $cfg['max_height']),
            'mode' => $mode !== null ? $mode : $cfg['default_mode'],
            'gravity' => $gravity !== null ? $gravity : $cfg['default_gravity'],
            'format' => $format,
            'crop' => $crop,
            'multiple_crop' => $found > 1,
        );
    }

    /** Разбор значения обрезки — правила как в App\Domain\CropParam. */
    function iar_legacy_crop($side, $raw)
    {
        $raw = trim($raw);
        $isPercent = substr($raw, -1) === 'p' || substr($raw, -1) === '%';
        $number = rtrim($raw, 'p%');

        if ($number === '' || !is_numeric($number)) {
            throw new IarLegacyError(400, 'Bad request.', 'Crop value must be numeric.');
        }

        $value = (float) $number;
        if ($value < 0) {
            throw new IarLegacyError(400, 'Bad request.', 'Crop value must not be negative.');
        }
        if ($isPercent && $value > 100) {
            throw new IarLegacyError(400, 'Bad request.', 'Crop percent must not exceed 100.');
        }
        if (!$isPercent && $value > 100000) {
            throw new IarLegacyError(400, 'Bad request.', 'Crop value is too large.');
        }

        return array('side' => $side, 'value' => $value, 'percent' => $isPercent);
    }

    function iar_legacy_dimension($get, $key)
    {
        if (!array_key_exists($key, $get)) {
            return 0;
        }
        $raw = $get[$key];
        if (!is_string($raw) && !is_int($raw)) {
            throw new IarLegacyError(400, 'Bad request.', 'Parameter "' . $key . '" must be an integer.');
        }
        if (is_string($raw) && trim($raw) === '') {
            return 0;
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if ($value === false) {
            throw new IarLegacyError(400, 'Bad request.', 'Parameter "' . $key . '" must be an integer.');
        }
        if ($value < 0) {
            throw new IarLegacyError(400, 'Bad request.', 'Parameter "' . $key . '" must not be negative.');
        }

        return $value;
    }

    function iar_legacy_enum($get, $key, $allowed)
    {
        if (!array_key_exists($key, $get)) {
            return null;
        }
        $raw = $get[$key];
        if (!is_string($raw)) {
            throw new IarLegacyError(400, 'Bad request.', 'Parameter "' . $key . '" must be a string.');
        }
        $raw = strtolower(trim($raw));
        if ($raw === '') {
            return null;
        }

        return in_array($raw, $allowed, true) ? $raw : null;
    }

    function iar_legacy_gravities()
    {
        return array('center', 'top', 'bottom', 'left', 'right',
            'top-left', 'top-right', 'bottom-left', 'bottom-right');
    }

    // =====================================================================
    // Кеш-ключ: те же правила, что в App\Infrastructure\CacheKey
    // =====================================================================

    function iar_legacy_relative($path, $base)
    {
        $relative = strpos($path, $base) === 0 ? substr($path, strlen($base)) : $path;

        return str_replace('\\', '/', $relative);
    }

    function iar_legacy_crop_suffix($crop)
    {
        if ($crop === null) {
            return '';
        }
        $num = rtrim(rtrim(number_format($crop['value'], 4, '.', ''), '0'), '.');

        return '_' . $crop['side'] . $num . ($crop['percent'] ? 'p' : '');
    }

    function iar_legacy_mode_suffix($params)
    {
        if ($params['mode'] === 'fit') {
            return '';
        }
        $suffix = '_m-' . $params['mode'];
        if ($params['mode'] === 'cover' && $params['gravity'] !== 'center') {
            $suffix .= '-g-' . $params['gravity'];
        }

        return $suffix;
    }

    function iar_legacy_key($source, $cfg, $params, $mtime)
    {
        return pathinfo($source, PATHINFO_FILENAME)
            . '__' . $params['width'] . 'x' . $params['height']
            . iar_legacy_crop_suffix($params['crop'])
            . iar_legacy_mode_suffix($params)
            . '__' . md5(iar_legacy_relative($source, $cfg['base']))
            . '_' . $mtime
            . iar_legacy_fingerprint($cfg);
    }

    function iar_legacy_hash_marker($source, $base)
    {
        return '__' . md5(iar_legacy_relative($source, $base)) . '_';
    }

    /** jpeg → jpg, как Format::cacheExtension(). */
    function iar_legacy_cache_ext($format)
    {
        return $format === 'jpeg' ? 'jpg' : $format;
    }

    function iar_legacy_mime($format)
    {
        $map = array(
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif',
            'svg' => 'image/svg+xml',
        );

        return isset($map[$format]) ? $map[$format] : 'application/octet-stream';
    }

    // =====================================================================
    // Выбор формата — правила как в App\Domain\FormatNegotiator
    // =====================================================================

    function iar_legacy_target($cfg, $sourceExt, $explicit, $accept, $probe = null)
    {
        if ($explicit !== null) {
            if ($sourceExt !== 'svg' && $explicit === 'svg') {
                throw new IarLegacyError(400, 'Unsupported output format.', 'SVG is not a valid output format for raster sources.');
            }

            return $explicit;
        }

        if ($sourceExt === 'svg' || $sourceExt === 'gif') {
            // SVG не конвертируем автоматически, GIF — чтобы не потерять анимацию.
            return $sourceExt;
        }

        if (!$cfg['auto_format']) {
            return $sourceExt;
        }

        $available = $probe === null ? 'function_exists' : $probe;

        if (strpos($accept, 'image/avif') !== false && $available('imageavif')
            && in_array('avif', $cfg['allowed'], true)) {
            return 'avif';
        }

        if (strpos($accept, 'image/webp') !== false && $available('imagewebp')
            && in_array('webp', $cfg['allowed'], true)) {
            return 'webp';
        }

        return $sourceExt;
    }

    function iar_legacy_varies($cfg, $sourceExt, $explicit)
    {
        return $cfg['auto_format'] && $explicit === null
            && $sourceExt !== 'svg' && $sourceExt !== 'gif';
    }

    // =====================================================================
    // Основной сценарий: план ответа (без побочных эффектов HTTP)
    // =====================================================================

    /**
     * @return array{status:int,headers:array,file:?string,body:?string,head:bool}
     */
    function iar_legacy_plan($get, $server, $cfg)
    {
        $started = microtime(true);
        $method = strtoupper(iar_legacy_str($server, 'REQUEST_METHOD', 'GET'));
        $head = $method === 'HEAD';

        if ($method !== 'GET' && $method !== 'HEAD') {
            throw new IarLegacyError(405, 'Method not allowed.', 'Only GET and HEAD are supported.');
        }

        $name = iar_legacy_name($get, $server);
        if ($name === null) {
            throw new IarLegacyError(400, 'Bad request.', "Parameter 'name' is required and must be a string.");
        }

        if ($cfg['base_problem']) {
            iar_legacy_log($cfg, 'fail', array(
                'reason' => 'images_base_dir does not exist or is not a directory.',
                'status' => 0,
                'url' => iar_legacy_str($server, 'REQUEST_URI', ''),
                'stage' => 'config',
            ));
        }

        $router = iar_legacy_router($cfg['aliases_file'], $cfg['base']);
        if ($router['error'] !== null) {
            iar_legacy_log($cfg, 'fail', array(
                'reason' => $router['error'],
                'status' => 0,
                'url' => iar_legacy_str($server, 'REQUEST_URI', ''),
                'stage' => 'aliases',
            ));
        }

        $route = iar_legacy_route($router, $name);
        $get = iar_legacy_merge_params($get, $route['params']);

        $source = iar_legacy_source($cfg, $route['name']);
        $sourceExt = strtolower((string) pathinfo($source, PATHINFO_EXTENSION));
        $params = iar_legacy_params($get, $cfg);

        try {
            $target = iar_legacy_target($cfg, $sourceExt, $params['format'], iar_legacy_str($server, 'HTTP_ACCEPT', ''));
        } catch (IarLegacyError $e) {
            return iar_legacy_error_response($e, $head);
        }

        $mtime = ($cfg['static_mode'] || $cfg['cache_invalidation'] !== 'mtime')
            ? 0
            : (int) @filemtime($source);

        $key = iar_legacy_key($source, $cfg, $params, $mtime);
        $ext = iar_legacy_cache_ext($target);
        $cachePath = $cfg['cache_dir'] . DIRECTORY_SEPARATOR . $key . '.' . $ext;

        $hit = !$cfg['disable_cache'] && is_file($cachePath);
        if (!$hit) {
            try {
                $hit = !iar_legacy_generate($source, $sourceExt, $cachePath, $key, $params, $target, $cfg, $mtime);
            } catch (IarLegacyError $e) {
                if ($e->status() < 500) {
                    return iar_legacy_error_response($e, $head);
                }

                return iar_legacy_original_redirect($get, $server, $cfg, $head, $e->getMessage(), $started, $route);
            }
        }

        $response = iar_legacy_cache_response($server, $cfg, $params, $target, $sourceExt, $cachePath, $key, $head, $hit);

        if ($cfg['logging'] && $cfg['log_level'] !== 'error') {
            $origBytes = (int) @filesize($source);
            $newBytes = (int) @filesize($cachePath);
            $context = array(
                'status' => $response['status'],
                'url' => iar_legacy_str($server, 'REQUEST_URI', ''),
                'orig_bytes' => $origBytes,
                'new_bytes' => $newBytes,
                'saved_pct' => $origBytes > 0 ? round(($origBytes - $newBytes) / $origBytes * 100, 2) : 0.0,
                'duration_ms' => round((microtime(true) - $started) * 1000, 2),
                'engine' => 'legacy',
                'alias' => $route['alias'],
                'preset' => $route['preset'],
            );
            if ($cfg['log_level'] === 'debug') {
                $context['params'] = $get;
            }
            iar_legacy_log($cfg, $hit ? 'hit' : 'miss', $context);
        }

        return $response;
    }

    /** Заголовки варианта: ETag из ключа, no-cache/immutable, Vary, 304, редирект. */
    function iar_legacy_cache_response($server, $cfg, $params, $target, $sourceExt, $cachePath, $key, $head, $hit)
    {
        $headers = array(
            array('X-Content-Type-Options', 'nosniff'),
            array('X-Iar-Engine', 'legacy'),
            array('X-Iar-Cache', $hit ? 'hit' : 'miss'),
        );
        if ($params['multiple_crop']) {
            $headers[] = array('X-Iar-Warning', 'multiple crop parameters; only the highest priority one is applied');
        }
        if ($target === 'svg') {
            $headers[] = array('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
        }

        $vary = iar_legacy_varies($cfg, $sourceExt, $params['format']) ? array(array('Vary', 'Accept')) : array();

        if ($cfg['disable_cache']) {
            $headers[] = array('Cache-Control', 'no-cache, no-store, must-revalidate');
            $headers[] = array('Pragma', 'no-cache');
            $headers[] = array('Expires', '0');
            $headers = array_merge($headers, $vary, iar_legacy_content_headers($cachePath, $target));

            return iar_legacy_file_response($cachePath, $headers, $head);
        }

        $etag = '"' . md5($key . '.' . iar_legacy_cache_ext($target)) . '"';
        $control = $cfg['static_mode'] ? 'public, max-age=31536000, immutable' : 'no-cache';
        $conditional = array_merge($headers, array(array('ETag', $etag), array('Cache-Control', $control)), $vary);

        $inm = isset($server['HTTP_IF_NONE_MATCH']) && is_string($server['HTTP_IF_NONE_MATCH'])
            ? trim($server['HTTP_IF_NONE_MATCH'])
            : null;
        if ($inm !== null && $inm === $etag) {
            return array('status' => 304, 'headers' => $conditional, 'file' => null, 'body' => null, 'head' => $head);
        }

        $url = $cfg['redirect_to_cache'] ? iar_legacy_cache_url($cachePath, $cfg, $server) : null;
        if ($url !== null) {
            $redirectControl = $cfg['static_mode'] ? 'public, max-age=31536000' : 'no-cache';
            $redirectHeaders = array_merge(
                $headers,
                array(array('ETag', $etag), array('Cache-Control', $redirectControl), array('Location', $url)),
                $vary
            );

            return array(
                'status' => $cfg['static_mode'] ? 301 : 302,
                'headers' => $redirectHeaders,
                'file' => null,
                'body' => null,
                'head' => $head,
            );
        }

        return iar_legacy_file_response($cachePath, array_merge($conditional, iar_legacy_content_headers($cachePath, $target)), $head);
    }

    function iar_legacy_content_headers($cachePath, $target)
    {
        $headers = array(array('Content-Type', iar_legacy_mime($target)));

        $compression = ini_get('zlib.output_compression');
        if ($compression === false || $compression === '' || $compression === '0') {
            $size = @filesize($cachePath);
            if ($size !== false) {
                $headers[] = array('Content-Length', (string) $size);
            }
        }

        return $headers;
    }

    /** URL кеш-файла относительно DOCUMENT_ROOT; вне корня — редиректа нет. */
    function iar_legacy_cache_url($cachePath, $cfg, $server)
    {
        $documentRoot = iar_legacy_str($server, 'DOCUMENT_ROOT', '');
        if ($documentRoot === '') {
            return null;
        }
        $rootReal = realpath($documentRoot);
        $cacheReal = realpath($cfg['cache_dir']);
        if ($rootReal === false || $cacheReal === false) {
            return null;
        }

        $root = rtrim(str_replace('\\', '/', $rootReal), '/') . '/';
        $cacheDir = str_replace('\\', '/', $cacheReal);
        if (strpos($cacheDir . '/', $root) !== 0) {
            return null;
        }

        $relative = substr($cacheDir, strlen($root));

        return '/' . ($relative === '' ? '' : $relative . '/') . basename($cachePath);
    }

    // =====================================================================
    // Генерация варианта
    // =====================================================================

    /** @return bool true — файл создан этим запросом, false — уже существовал. */
    function iar_legacy_generate($source, $sourceExt, $cachePath, $key, $params, $target, $cfg, $mtime, $renderer = null)
    {
        iar_legacy_ensure_dir($cfg['cache_dir']);

        if ($cfg['cache_auto_clean'] && $mtime > 0) {
            iar_legacy_clean_old(
                $cfg['cache_dir'],
                pathinfo($source, PATHINFO_FILENAME) . '__',
                iar_legacy_hash_marker($source, $cfg['base']),
                basename($cachePath),
                $mtime . iar_legacy_fingerprint($cfg) . '.'
            );
        }

        $lock = iar_legacy_lock($cfg['cache_dir'], $key);

        if (!$cfg['disable_cache'] && is_file($cachePath)) {
            iar_legacy_unlock($lock);

            return false;
        }

        try {
            $hasOperations = $params['width'] > 0 || $params['height'] > 0
                || $params['crop'] !== null || $params['mode'] !== 'fit';

            if (!$hasOperations && $target === $sourceExt) {
                $data = @file_get_contents($source);
                if ($data === false) {
                    throw new IarLegacyError(500, 'Image processing failed.', 'Could not read source image.');
                }
            } else {
                $render = $renderer === null ? 'iar_legacy_render' : $renderer;
                $data = $render($source, $sourceExt, $params, $target, $cfg);
                if ($data === '') {
                    throw new IarLegacyError(500, 'Image processing failed.', 'Renderer produced an empty image.');
                }
            }

            iar_legacy_put($cfg['cache_dir'], $cachePath, $data);
        } catch (Throwable $e) {
            iar_legacy_unlock($lock);
            throw $e;
        }

        iar_legacy_unlock($lock);

        return true;
    }

    /**
     * Обработка средствами GD. Всё, что нельзя сделать без потери данных
     * (анимация, растеризация SVG без Imagick), приводит к отдаче оригинала.
     */
    function iar_legacy_render($source, $sourceExt, $params, $target, $cfg)
    {
        if ($sourceExt === 'svg') {
            return iar_legacy_render_svg($source, $params, $target, $cfg);
        }

        $bytes = @filesize($source);
        if ($bytes === false) {
            throw new IarLegacyError(500, 'Image processing failed.', 'Could not stat source image.');
        }
        if ($bytes > $cfg['max_input_bytes']) {
            throw new IarLegacyError(413, 'Source image is too large.', 'Source file exceeds max_input_bytes.');
        }

        $info = @getimagesize($source);
        if ($info === false) {
            throw new IarLegacyError(500, 'Image processing failed.', 'Source is not a readable image.');
        }
        if ($info[0] * $info[1] > $cfg['max_input_megapixels'] * 1000000) {
            throw new IarLegacyError(413, 'Source image is too large.', 'Source image exceeds max_input_megapixels.');
        }

        if (iar_legacy_animated($source, $sourceExt)) {
            throw new IarLegacyError(
                500,
                'Image processing failed.',
                'Animated ' . $sourceExt . ' cannot be processed by GD without losing frames.'
            );
        }

        $image = iar_legacy_decode($source, $sourceExt);
        if (!is_resource($image) && !($image instanceof GdImage)) {
            throw new IarLegacyError(500, 'Image processing failed.', 'GD could not decode the source image.');
        }

        try {
            $image = iar_legacy_apply_crop($image, $params['crop']);
            $image = iar_legacy_apply_resize($image, $params);
            $data = iar_legacy_encode($image, $target, $cfg);
        } catch (Throwable $e) {
            imagedestroy($image);
            throw $e;
        }

        imagedestroy($image);

        return $data;
    }

    /** @return resource|GdImage */
    function iar_legacy_decode($source, $ext)
    {
        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                return @imagecreatefromjpeg($source);
            case 'png':
                return @imagecreatefrompng($source);
            case 'gif':
                return @imagecreatefromgif($source);
            case 'webp':
                return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false;
            case 'avif':
                // @codeCoverageIgnoreStart — требует GD с AVIF (проверяется в CI).
                return function_exists('imagecreatefromavif') ? @imagecreatefromavif($source) : false;
                // @codeCoverageIgnoreStop
        }

        return false;
    }

    /** Анимация: несколько кадров GIF или чанк ANMF у WebP. */
    function iar_legacy_animated($source, $ext)
    {
        if ($ext === 'gif') {
            $data = @file_get_contents($source);
            if ($data === false) {
                return false;
            }
            // Перед каждым кадром идёт Graphic Control Extension (21 F9 04).
            // Больше одного — значит кадров несколько.
            return preg_match_all("/\x21\xF9\x04/", $data) > 1;
        }

        if ($ext === 'webp') {
            $header = (string) @file_get_contents($source, false, null, 0, 4096);

            return strpos($header, 'ANMF') !== false;
        }

        return false;
    }

    /** Обрезка края: область всегда внутри оригинала, минимум 1 пиксель. */
    function iar_legacy_apply_crop($image, $crop)
    {
        if ($crop === null) {
            return $image;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $vertical = $crop['side'] === 'ct' || $crop['side'] === 'cb';
        $dimension = $vertical ? $height : $width;
        $amount = $crop['percent'] ? $dimension * $crop['value'] / 100 : $crop['value'];
        $amount = (int) max(0, min($amount, $dimension - 1));

        $x = 0;
        $y = 0;
        $newWidth = $width;
        $newHeight = $height;

        if ($crop['side'] === 'ct') {
            $y = $amount;
            $newHeight = $height - $amount;
        } elseif ($crop['side'] === 'cb') {
            $newHeight = $height - $amount;
        } elseif ($crop['side'] === 'cl') {
            $x = $amount;
            $newWidth = $width - $amount;
        } else {
            $newWidth = $width - $amount;
        }

        $cropped = iar_legacy_canvas($newWidth, $newHeight);
        imagecopy($cropped, $image, 0, 0, $x, $y, $newWidth, $newHeight);
        imagedestroy($image);

        return $cropped;
    }

    /** fit — уменьшить без увеличения; cover — точный размер с обрезкой; resize — растянуть. */
    function iar_legacy_apply_resize($image, $params)
    {
        $w = $params['width'];
        $h = $params['height'];
        if ($w === 0 && $h === 0) {
            return $image;
        }

        $srcW = imagesx($image);
        $srcH = imagesy($image);
        $mode = $params['mode'];

        if ($mode === 'resize') {
            $dstW = $w > 0 ? $w : $srcW;
            $dstH = $h > 0 ? $h : $srcH;
            $canvas = iar_legacy_canvas($dstW, $dstH);
            imagecopyresampled($canvas, $image, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
            imagedestroy($image);

            return $canvas;
        }

        if ($mode === 'cover') {
            $dstW = $w > 0 ? $w : $h;
            $dstH = $h > 0 ? $h : $w;
            $ratio = max($dstW / $srcW, $dstH / $srcH);
            $scaledW = (int) max(1, round($srcW * $ratio));
            $scaledH = (int) max(1, round($srcH * $ratio));
            $offset = iar_legacy_gravity_offset($params['gravity'], $scaledW - $dstW, $scaledH - $dstH);

            $canvas = iar_legacy_canvas($dstW, $dstH);
            imagecopyresampled($canvas, $image, -$offset[0], -$offset[1], 0, 0, $scaledW, $scaledH, $srcW, $srcH);
            imagedestroy($image);

            return $canvas;
        }

        // fit (scaleDown): уменьшаем, но никогда не увеличиваем.
        $ratio = 1.0;
        if ($w > 0 && $srcW > $w) {
            $ratio = $w / $srcW;
        }
        if ($h > 0 && $srcH * $ratio > $h) {
            $ratio = $h / $srcH;
        }
        if ($ratio >= 1.0) {
            return $image;
        }

        $dstW = (int) max(1, round($srcW * $ratio));
        $dstH = (int) max(1, round($srcH * $ratio));
        $canvas = iar_legacy_canvas($dstW, $dstH);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
        imagedestroy($image);

        return $canvas;
    }

    function iar_legacy_gravity_offset($gravity, $dx, $dy)
    {
        $x = (int) round($dx / 2);
        $y = (int) round($dy / 2);

        if (strpos($gravity, 'left') !== false) {
            $x = 0;
        } elseif (strpos($gravity, 'right') !== false) {
            $x = $dx;
        }
        if (strpos($gravity, 'top') !== false) {
            $y = 0;
        } elseif (strpos($gravity, 'bottom') !== false) {
            $y = $dy;
        }

        return array(max(0, $x), max(0, $y));
    }

    /** Холст с сохранённой прозрачностью. */
    function iar_legacy_canvas($width, $height)
    {
        $canvas = imagecreatetruecolor(max(1, $width), max(1, $height));
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, max(1, $width), max(1, $height), $transparent);
        imagealphablending($canvas, true);

        return $canvas;
    }

    function iar_legacy_encode($image, $target, $cfg)
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        $ok = false;
        $quality = $cfg['lossless'] ? 100 : null;

        switch ($target) {
            case 'jpg':
            case 'jpeg':
                if ($cfg['jpeg_progressive']) {
                    imageinterlace($image, true);
                }
                $ok = imagejpeg($image, null, $quality !== null ? $quality : $cfg['jpeg_quality']);
                break;
            case 'png':
                $threshold = $cfg['png_indexed_threshold'];
                if ($cfg['png_indexed'] && !$cfg['lossless'] && ($threshold === 0 || imagesx($image) <= $threshold)) {
                    imagetruecolortopalette($image, true, 255);
                }
                $ok = imagepng($image, null, 6);
                break;
            case 'gif':
                imagetruecolortopalette($image, true, 255);
                $ok = imagegif($image);
                break;
            case 'webp':
                if (function_exists('imagewebp')) {
                    $ok = imagewebp($image, null, $quality !== null ? $quality : $cfg['webp_quality']);
                }
                break;
            case 'avif':
                // @codeCoverageIgnoreStart — требует GD с AVIF (проверяется в CI).
                if (function_exists('imageavif')) {
                    $ok = imageavif($image, null, $quality !== null ? $quality : $cfg['avif_quality']);
                }
                // @codeCoverageIgnoreStop
                break;
        }

        $data = ob_get_clean();

        if (!$ok || !is_string($data) || $data === '') {
            throw new IarLegacyError(500, 'Image processing failed.', 'Encoder for ' . $target . ' is not available or failed.');
        }

        return $data;
    }

    /** SVG: как есть; растеризация — только если есть Imagick. */
    function iar_legacy_render_svg($source, $params, $target, $cfg)
    {
        if ($target === 'svg') {
            $data = @file_get_contents($source);
            if ($data === false) {
                throw new IarLegacyError(500, 'Image processing failed.', 'Could not read SVG source.');
            }

            return $data;
        }

        if (!extension_loaded('imagick') || !class_exists('Imagick')) {
            throw new IarLegacyError(500, 'Image processing failed.', 'SVG rasterization requires Imagick.');
        }

        // @codeCoverageIgnoreStart — требует расширение Imagick.
        try {
            $imagick = new Imagick();
            $imagick->setResolution(150, 150);
            $imagick->readImage($source);
            $imagick->setImageFormat($target === 'jpeg' ? 'jpg' : $target);
            $data = $imagick->getImageBlob();
            $imagick->clear();
            $imagick->destroy();
        } catch (Throwable $e) {
            throw new IarLegacyError(500, 'Image processing failed.', 'Imagick: ' . $e->getMessage());
        }

        return $data;
        // @codeCoverageIgnoreStop
    }

    // =====================================================================
    // Файловый кеш: атомарная запись, блокировка, точечная очистка
    // =====================================================================

    function iar_legacy_ensure_dir($dir)
    {
        if (is_dir($dir)) {
            return;
        }
        @mkdir($dir, 0755, true);
        if (!is_dir($dir)) {
            throw new IarLegacyError(500, 'Image processing failed.', 'Cache directory could not be created: ' . $dir);
        }
    }

    function iar_legacy_put($dir, $path, $data)
    {
        iar_legacy_ensure_dir($dir);

        $temp = @tempnam($dir, 'iar_');
        // @codeCoverageIgnoreStart — отказы tempnam/записи (нет места, нет прав)
        // не воспроизводятся переносимо; те же ветки основного движка проверены
        // инъекцией отказов в tests/Support/FaultyCacheStore.php.
        if ($temp === false) {
            throw new IarLegacyError(500, 'Image processing failed.', 'Could not create temporary cache file.');
        }

        $written = @file_put_contents($temp, $data);
        if ($written === false || $written !== strlen($data)) {
            @unlink($temp);
            throw new IarLegacyError(500, 'Image processing failed.', 'Could not write cache file.');
        }
        // @codeCoverageIgnoreStop

        if (!@rename($temp, $path)) {
            @unlink($temp);
            throw new IarLegacyError(500, 'Image processing failed.', 'Could not publish cache file.');
        }
    }

    function iar_legacy_lock($dir, $key)
    {
        $handle = @fopen($dir . DIRECTORY_SEPARATOR . '.' . md5($key) . '.lock', 'c');
        if ($handle === false || !@flock($handle, LOCK_EX)) {
            // @codeCoverageIgnoreStart — отказ flock не воспроизводится
            // переносимо; ветка проверена в основном движке (FaultyCacheStore).
            if (is_resource($handle)) {
                fclose($handle);
            }
            // @codeCoverageIgnoreStop

            return null;
        }

        return $handle;
    }

    function iar_legacy_unlock($handle)
    {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * Удаление устаревших вариантов ОДНОГО оригинала (без чтения файлов).
     * Актуальные варианты всех размеров сохраняются.
     */
    function iar_legacy_clean_old($dir, $prefix, $marker, $keep, $currentVersion = '')
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $entries = @scandir($dir);
        // @codeCoverageIgnoreStart — отказ scandir не воспроизводится переносимо.
        if ($entries === false) {
            return 0;
        }
        // @codeCoverageIgnoreStop

        $removed = 0;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === $keep || substr($entry, -5) === '.lock') {
                continue;
            }
            if (strpos($entry, $prefix) !== 0 || strpos($entry, $marker) === false) {
                continue;
            }
            if ($currentVersion !== '' && strpos($entry, $marker . $currentVersion) !== false) {
                continue;
            }
            if (@unlink($dir . DIRECTORY_SEPARATOR . $entry)) {
                $removed++;
            }
        }

        return $removed;
    }

    // =====================================================================
    // Ответы
    // =====================================================================

    function iar_legacy_file_response($path, $headers, $head)
    {
        return array('status' => 200, 'headers' => $headers, 'file' => $path, 'body' => null, 'head' => $head);
    }

    function iar_legacy_error_response($e, $head)
    {
        $headers = array(
            array('Content-Type', 'text/plain; charset=UTF-8'),
            array('X-Content-Type-Options', 'nosniff'),
            array('Cache-Control', 'no-store'),
            array('X-Iar-Engine', 'legacy'),
        );
        if ($e->status() === 405) {
            $headers[] = array('Allow', 'GET, HEAD');
        }

        return array(
            'status' => $e->status(),
            'headers' => $headers,
            'file' => null,
            'body' => $e->publicMessage() . "\n",
            'head' => $head,
        );
    }

    /** Сбой обработки → редирект на оригинал (картинка не исчезает). */
    function iar_legacy_original_redirect($get, $server, $cfg, $head, $reason, $started, $route = null)
    {
        $scriptDir = rtrim(str_replace('\\', '/', dirname(iar_legacy_str($server, 'SCRIPT_NAME', '/index.php'))), '/');
        $parentDir = rtrim(str_replace('\\', '/', dirname($scriptDir)), '/');
        $name = $route === null ? iar_legacy_name($get, $server) : $route['name'];
        $url = $parentDir . '/' . ltrim((string) $name, '/\\');
        $alias = $route === null ? null : $route['alias'];

        // Псевдоним скрывает реальный путь: редиректить некуда, отдаём тело.
        if ($alias !== null) {
            iar_legacy_log($cfg, 'fail', array(
                'reason' => $reason,
                'status' => 200,
                'url' => iar_legacy_str($server, 'REQUEST_URI', ''),
                'alias' => $alias,
                'preset' => $route['preset'],
                'duration_ms' => round((microtime(true) - $started) * 1000, 2),
                'engine' => 'legacy',
            ));

            $original = iar_legacy_source($cfg, $route['name']);
            $response = iar_legacy_original_body($original, $head);
            $response['headers'][] = array('X-Iar-Engine', 'legacy');

            return $response;
        }

        iar_legacy_log($cfg, 'fail', array(
            'reason' => $reason,
            'status' => 302,
            'url' => iar_legacy_str($server, 'REQUEST_URI', ''),
            'redirect' => $url,
            'preset' => $route === null ? null : $route['preset'],
            'duration_ms' => round((microtime(true) - $started) * 1000, 2),
            'engine' => 'legacy',
        ));

        return array(
            'status' => 302,
            'headers' => array(
                array('Cache-Control', 'no-store'),
                array('X-Content-Type-Options', 'nosniff'),
                array('X-Iar-Engine', 'legacy'),
                array('X-Iar-Fallback', 'original'),
                array('Location', $url),
            ),
            'file' => null,
            'body' => null,
            'head' => $head,
        );
    }

    /**
     * Последний рубеж: сам оригинал в теле ответа. Используется при сбое
     * конфигурации/зависимостей и при фатальной ошибке.
     */
    function iar_legacy_fallback($get, $server, $cfg, $head, $reason, $log = true)
    {
        if ($log) {
            error_log('[iar] ' . $reason);
        }

        $name = iar_legacy_name($get, $server);
        $path = null;
        if ($name !== null) {
            $root = iar_legacy_str($server, 'DOCUMENT_ROOT', '');
            $root = $root === '' ? dirname(dirname(__FILE__)) : $root;
            $base = realpath($root);
            if ($base !== false) {
                // Путь к маршрутам берём из конфига (он терпим к ошибкам),
                // иначе — файл рядом с index.php.
                $aliasesFile = isset($cfg['aliases_file']) && is_string($cfg['aliases_file']) && $cfg['aliases_file'] !== ''
                    ? $cfg['aliases_file']
                    : dirname(__FILE__) . '/aliases.json';
                $router = iar_legacy_router($aliasesFile, $base . DIRECTORY_SEPARATOR);
                $routed = iar_legacy_route($router, $name);
                $candidate = realpath($base . '/' . ltrim($routed['name'], '/\\'));
                $allowed = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg');
                if ($candidate !== false && strpos($candidate, $base . DIRECTORY_SEPARATOR) === 0
                    && is_file($candidate)
                    && in_array(strtolower((string) pathinfo($candidate, PATHINFO_EXTENSION)), $allowed, true)) {
                    $path = $candidate;
                }
            }
        }

        if ($log) {
            iar_legacy_log($cfg, 'fail', array(
                'reason' => $reason,
                'status' => $path === null ? 500 : 200,
                'url' => iar_legacy_str($server, 'REQUEST_URI', ''),
                'fallback' => $path,
                'stage' => 'bootstrap',
                'engine' => 'legacy',
            ));
        }

        if ($path === null) {
            return array(
                'status' => 500,
                'headers' => array(
                    array('Content-Type', 'text/plain; charset=UTF-8'),
                    array('Cache-Control', 'no-store'),
                    array('X-Content-Type-Options', 'nosniff'),
                    array('X-Iar-Engine', 'legacy'),
                ),
                'file' => null,
                'body' => "Internal error.\n",
                'head' => $head,
            );
        }

        $response = iar_legacy_original_body($path, $head);
        $response['headers'][] = array('X-Iar-Engine', 'legacy');

        return $response;
    }

    /** Тело оригинала с заголовками безопасности — общий код для обоих путей. */
    function iar_legacy_original_body($path, $head)
    {
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $headers = array(
            array('Content-Type', iar_legacy_mime($ext)),
            array('Cache-Control', 'no-store'),
            array('X-Content-Type-Options', 'nosniff'),
            array('X-Iar-Fallback', 'original'),
        );
        if ($ext === 'svg') {
            $headers[] = array('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
        }
        $size = @filesize($path);
        if ($size !== false) {
            $headers[] = array('Content-Length', (string) $size);
        }

        return iar_legacy_file_response($path, $headers, $head);
    }

    /** Единственная функция с побочными эффектами HTTP. */
    function iar_legacy_emit($response)
    {
        if (!headers_sent()) {
            http_response_code($response['status']);
            foreach ($response['headers'] as $header) {
                header($header[0] . ': ' . $header[1]);
            }
        }

        if ($response['head'] || $response['status'] === 304) {
            return;
        }

        if ($response['file'] !== null) {
            @readfile($response['file']);
        } elseif ($response['body'] !== null) {
            echo $response['body'];
        }
    }

    // =====================================================================
    // Отладочный лог: тот же формат, что у App\Infrastructure\DebugLogger
    // =====================================================================

    function iar_legacy_log($cfg, $event, $context)
    {
        if (!$cfg['logging']) {
            return;
        }
        if ($event !== 'fail' && $cfg['log_level'] === 'error') {
            return;
        }
        if ($cfg['log_level'] !== 'debug' && isset($context['params'])) {
            unset($context['params']);
        }

        $record = array(
            'ts' => date('Y-m-d H:i:s'),
            'event' => iar_legacy_clean($event),
            'level' => $cfg['log_level'],
        );
        foreach ($context as $key => $value) {
            $record[$key] = iar_legacy_sanitize($key, $value);
        }

        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        if (!is_dir($cfg['log_dir'])) {
            @mkdir($cfg['log_dir'], 0755, true);
            if (!is_dir($cfg['log_dir'])) {
                return;
            }
        }

        @file_put_contents($cfg['log_dir'] . DIRECTORY_SEPARATOR . date('Y-m-d') . '.log', $json . "\n", FILE_APPEND | LOCK_EX);
    }

    function iar_legacy_sanitize($key, $value)
    {
        if (preg_match('/(token|pass|secret|auth|api_?key|session)/i', (string) $key) === 1) {
            return '[redacted]';
        }
        if (is_string($value)) {
            return iar_legacy_clean($value);
        }
        if (is_array($value)) {
            $out = array();
            foreach ($value as $k => $v) {
                $out[$k] = iar_legacy_sanitize($k, $v);
            }

            return $out;
        }
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return '[non-scalar]';
    }

    function iar_legacy_clean($value)
    {
        return str_replace(array("\r", "\n"), array('\\r', '\\n'), $value);
    }

    // =====================================================================
    // Мелкие помощники чтения массивов (PHP 7.0: без typed properties)
    // =====================================================================

    function iar_legacy_str($array, $key, $default)
    {
        return isset($array[$key]) && is_string($array[$key]) ? $array[$key] : $default;
    }

    function iar_legacy_bool($array, $key, $default)
    {
        return isset($array[$key]) && is_bool($array[$key]) ? $array[$key] : $default;
    }

    function iar_legacy_int($array, $key, $default, $min, $max = null)
    {
        if (!isset($array[$key]) || !is_int($array[$key])) {
            return $default;
        }
        $value = $array[$key];
        if ($value < $min || ($max !== null && $value > $max)) {
            return $default;
        }

        return $value;
    }
}
