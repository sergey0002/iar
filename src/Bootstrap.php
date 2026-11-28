<?php

declare(strict_types=1);

namespace App;

use App\Domain\Format;
use App\Domain\ResizeService;
use App\Exception\IarException;
use App\Http\ErrorResponse;
use App\Http\Request;
use App\Http\Response;
use App\Http\SapiInterface;
use App\Infrastructure\CacheStore;
use App\Infrastructure\DebugLogger;
use App\Infrastructure\LoggerInterface;
use App\Infrastructure\NullLogger;

/**
 * Сборка зависимостей и обработка ошибок верхнего уровня.
 *
 * Политика надёжности: при серверном сбое (исключение, фатальная ошибка,
 * нехватка памяти) браузер всё равно получает картинку — оригинал
 * отдаётся напрямую, минуя обработку. Клиентские ошибки (400/403/404/405/413)
 * оригинал НЕ отдают: иначе белый список расширений можно было бы обойти.
 *
 * Исправления аудита: S-04/E-01 (ошибки не выводятся в ответ),
 * E-03 (единый формат), E-05 (shutdown-обработчик), A-01 (тонкий entry point).
 */
final class Bootstrap
{
    /** Расширения, которые допустимо отдать как «оригинал» при сбое. */
    private const FALLBACK_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'];

    /**
     * @param array<string,mixed> $get
     * @param array<string,mixed> $server
     */
    public static function run(SapiInterface $sapi, array $get, array $server, ?string $configFile = null): void
    {
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);

        $headOnly = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')) === 'HEAD';
        self::registerFatalHandler($sapi, $get, $server, $headOnly);

        $logger = new NullLogger();
        $configPath = $configFile ?? dirname(__DIR__) . '/config.php';

        try {
            $config = Config::load($configPath);
            $logger = $config->logging
                ? new DebugLogger($config->logDir, $config->logLevel)
                : new NullLogger();

            $application = new Application(
                $config,
                new ResizeService($config),
                new CacheStore($config->cacheDir),
                $logger,
            );

            $response = $application->handle(Request::fromArray($get, $server));
        } catch (IarException $e) {
            // Осмысленный статус (400/403/404/405/413) сохраняем.
            // Серверный сбой конфигурации — отдаём оригинал.
            if ($e->status() >= 500) {
                // Конфиг не прошёл валидацию, но настройки лога из него
                // прочитать можно — причина сбоя должна попасть в лог.
                $logger = self::emergencyLogger($configPath);
                $response = self::fallbackResponse($get, $server, $headOnly, $logger, $e->getMessage());
            } else {
                $response = ErrorResponse::from($e, $headOnly);
            }
        } catch (\Throwable $e) {
            $logger = self::emergencyLogger($configPath);
            $response = self::fallbackResponse($get, $server, $headOnly, $logger, $e->getMessage());
        }

        $response->send($sapi);
    }

    /**
     * Последний рубеж: отдать оригинал, а если это невозможно — нейтральный 500.
     *
     * @param array<string,mixed> $get
     * @param array<string,mixed> $server
     */
    private static function fallbackResponse(
        array $get,
        array $server,
        bool $headOnly,
        LoggerInterface $logger,
        string $reason,
    ): Response {
        error_log('[iar] ' . $reason);

        $original = self::originalFile($get, $server);

        $logger->log('fail', [
            'reason' => $reason,
            'status' => $original === null ? 500 : 200,
            'url' => (string) ($server['REQUEST_URI'] ?? ''),
            'fallback' => $original,
            'stage' => 'bootstrap',
        ]);

        if ($original === null) {
            return Response::text(500, "Internal error.\n", [
                ['Content-Type', 'text/plain; charset=UTF-8'],
                ['Cache-Control', 'no-store'],
                ['X-Content-Type-Options', 'nosniff'],
            ], $headOnly);
        }

        return self::originalResponse($original, $headOnly);
    }

    /**
     * Логгер для аварийного пути: настройки берутся из сырого массива
     * конфига, даже если сам конфиг невалиден. Вызывается только при сбое,
     * поэтому горячий путь не платит за повторное чтение файла.
     */
    private static function emergencyLogger(string $file): LoggerInterface
    {
        try {
            $raw = require $file;
            if (!is_array($raw)) {
                return new NullLogger();
            }

            $local = dirname($file) . '/config.local.php';
            if (is_file($local)) {
                $override = require $local;
                if (is_array($override)) {
                    $raw = array_merge($raw, $override);
                }
            }

            if (($raw['logging'] ?? false) !== true) {
                return new NullLogger();
            }

            $dir = is_string($raw['log_dir'] ?? null) ? $raw['log_dir'] : dirname($file) . '/log';
            $level = $raw['log_level'] ?? 'error';
            $level = in_array($level, ['error', 'info', 'debug'], true) ? $level : 'error';

            return new DebugLogger($dir, $level);
        } catch (\Throwable) {
            return new NullLogger();
        }
    }

    /** Ответ с оригиналом: без обработки, без кеша, с теми же заголовками безопасности. */
    public static function originalResponse(string $path, bool $headOnly = false): Response
    {
        $format = Format::fromName((string) pathinfo($path, PATHINFO_EXTENSION));
        $headers = [
            ['Content-Type', $format === null ? 'application/octet-stream' : $format->mime()],
            ['Cache-Control', 'no-store'],
            ['X-Content-Type-Options', 'nosniff'],
            ['X-Iar-Fallback', 'original'],
        ];

        if ($format === Format::Svg) {
            $headers[] = ['Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox"];
        }

        $size = @filesize($path);
        if ($size !== false) {
            $headers[] = ['Content-Length', (string) $size];
        }

        return Response::file($path, $headers, $headOnly);
    }

    /**
     * Безопасный путь к оригиналу: только внутри корня сайта, только
     * изображение из белого списка. Никаких обходов белого списка IAR.
     *
     * @param array<string,mixed> $get
     * @param array<string,mixed> $server
     */
    public static function originalFile(array $get, array $server, ?string $baseDir = null): ?string
    {
        $name = self::requestedName($get, $server);
        if ($name === null) {
            return null;
        }

        $root = $baseDir ?? (string) ($server['DOCUMENT_ROOT'] ?? '');
        if ($root === '') {
            // DOCUMENT_ROOT не задан (CLI) — корень сайта на уровень выше IAR.
            $root = dirname(__DIR__, 2);
        }

        $base = realpath($root);
        if ($base === false) {
            return null;
        }

        $path = realpath($base . '/' . ltrim($name, '/\\'));
        if ($path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
            return null;
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, self::FALLBACK_EXTENSIONS, true) ? $path : null;
    }

    /**
     * Имя файла из URI или ?name=; не бросает исключений — используется
     * в аварийном пути, где разбор запроса мог уже упасть.
     *
     * @param array<string,mixed> $get
     * @param array<string,mixed> $server
     */
    private static function requestedName(array $get, array $server): ?string
    {
        $scriptDir = rtrim(str_replace('\\', '/', dirname((string) ($server['SCRIPT_NAME'] ?? '/index.php'))), '/');
        $path = parse_url((string) ($server['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '';

        $relative = '';
        if ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($path, $scriptDir . '/')) {
            $relative = substr($path, strlen($scriptDir) + 1);
        } elseif ($path !== '') {
            $relative = ltrim($path, '/');
        }

        if ($relative === '' || str_ends_with(strtolower($relative), '.php') || !str_contains($relative, '.')) {
            $raw = $get['name'] ?? null;
            $relative = is_string($raw) ? $raw : '';
        }

        if ($relative === '' || str_contains($relative, "\0")) {
            return null;
        }

        return $relative;
    }

    /**
     * @param array<string,mixed> $get
     * @param array<string,mixed> $server
     */
    private static function registerFatalHandler(SapiInterface $sapi, array $get, array $server, bool $headOnly): void
    {
        register_shutdown_function(self::fatalHandler($sapi, $get, $server, $headOnly));
    }

    /**
     * Замыкание shutdown-обработчика. Выделено отдельно, чтобы его тело
     * можно было вызвать в тестах, не завершая процесс.
     *
     * @param array<string,mixed> $get
     * @param array<string,mixed> $server
     * @return \Closure():void
     */
    public static function fatalHandler(
        SapiInterface $sapi,
        array $get = [],
        array $server = [],
        bool $headOnly = false,
    ): \Closure {
        return static function () use ($sapi, $get, $server, $headOnly): void {
            self::handleFatal($sapi, null, $get, $server, $headOnly);
        };
    }

    /**
     * Фатальные ошибки (E_ERROR, например исчерпание памяти) не перехватываются
     * try/catch. Отдаём оригинал, а если его нет — нейтральный 500.
     *
     * @param array{type:int,message:string,file?:string,line?:int}|null $error
     * @param array<string,mixed> $get
     * @param array<string,mixed> $server
     */
    public static function handleFatal(
        SapiInterface $sapi,
        ?array $error = null,
        array $get = [],
        array $server = [],
        bool $headOnly = false,
    ): void {
        $error ??= error_get_last();
        if ($error === null) {
            return;
        }

        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($error['type'], $fatal, true) || $sapi->headersSent()) {
            return;
        }

        error_log('[iar] fatal: ' . $error['message']);

        try {
            $original = self::originalFile($get, $server);
            $response = $original === null
                ? Response::text(500, "Internal error.\n", [
                    ['Content-Type', 'text/plain; charset=UTF-8'],
                    ['Cache-Control', 'no-store'],
                    ['X-Content-Type-Options', 'nosniff'],
                ], $headOnly)
                : self::originalResponse($original, $headOnly);

            $response->send($sapi);
        } catch (\Throwable) {
            // Завершение запроса внутри shutdown ожидаемо (exit).
        }
    }
}
