<?php

declare(strict_types=1);

namespace App;

use App\Domain\ResizeService;
use App\Exception\IarException;
use App\Http\ErrorResponse;
use App\Http\Request;
use App\Http\Response;
use App\Http\SapiInterface;
use App\Infrastructure\CacheStore;
use App\Infrastructure\DebugLogger;
use App\Infrastructure\NullLogger;

/**
 * Сборка зависимостей и обработка ошибок верхнего уровня.
 *
 * Исправления аудита:
 *   S-04/E-01 — ошибки не выводятся в ответ, только в лог;
 *   E-03      — единый нейтральный формат ошибок;
 *   E-05      — shutdown-обработчик для фатальных ошибок (например OOM);
 *   A-01      — entry point стал тонким, вся логика тестируема.
 */
final class Bootstrap
{
    /**
     * @param array<string,mixed> $get
     * @param array<string,mixed> $server
     */
    public static function run(SapiInterface $sapi, array $get, array $server, ?string $configFile = null): void
    {
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);

        self::registerFatalHandler($sapi);

        $headOnly = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')) === 'HEAD';

        try {
            $config = Config::load($configFile ?? dirname(__DIR__) . '/config.php');
            $logger = $config->logging ? new DebugLogger($config->logDir) : new NullLogger();

            $application = new Application(
                $config,
                new ResizeService($config),
                new CacheStore($config->cacheDir),
                $logger,
            );

            $response = $application->handle(Request::fromArray($get, $server));
        } catch (IarException $e) {
            $response = ErrorResponse::from($e, $headOnly);
        } catch (\Throwable $e) {
            error_log('[iar] ' . $e->getMessage());
            $response = Response::text(500, "Internal error.\n", [
                ['Content-Type', 'text/plain; charset=UTF-8'],
                ['Cache-Control', 'no-store'],
                ['X-Content-Type-Options', 'nosniff'],
            ], $headOnly);
        }

        $response->send($sapi);
    }

    /**
     * Фатальные ошибки (E_ERROR, например исчерпание памяти) не перехватываются
     * try/catch. Отдаём нейтральный 500 вместо вывода путей на диске.
     */
    private static function registerFatalHandler(SapiInterface $sapi): void
    {
        register_shutdown_function(self::fatalHandler($sapi));
    }

    /**
     * Замыкание shutdown-обработчика. Выделено отдельно, чтобы его тело
     * можно было вызвать в тестах, не завершая процесс.
     *
     * @return \Closure():void
     */
    public static function fatalHandler(SapiInterface $sapi): \Closure
    {
        return static function () use ($sapi): void {
            self::handleFatal($sapi);
        };
    }

    /**
     * Обработчик фатальной ошибки. Параметр $error — тестовый шов:
     * в production берётся error_get_last().
     *
     * @param array{type:int,message:string,file?:string,line?:int}|null $error
     */
    public static function handleFatal(SapiInterface $sapi, ?array $error = null): void
    {
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
            Response::text(500, "Internal error.\n", [
                ['Content-Type', 'text/plain; charset=UTF-8'],
                ['Cache-Control', 'no-store'],
                ['X-Content-Type-Options', 'nosniff'],
            ])->send($sapi);
        } catch (\Throwable) {
            // Завершение запроса внутри shutdown ожидаемо (exit).
        }
    }
}
