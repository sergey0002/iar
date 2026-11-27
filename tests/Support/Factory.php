<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application;
use App\Config;
use App\Domain\ResizeService;
use App\Domain\ResizerInterface;
use App\Http\Request;
use App\Infrastructure\CacheStore;
use App\Infrastructure\DebugLogger;
use App\Infrastructure\LoggerInterface;
use App\Infrastructure\NullLogger;

/** Фабрики тестовых окружений: изолированный «сайт», конфиг, приложение. */
final class Factory
{
    /** @var list<string> */
    private static array $dirs = [];

    public static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/iar_test_' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        self::$dirs[] = $dir;

        return $dir;
    }

    /** Изолированный корень сайта с фикстурами. */
    public static function site(): string
    {
        $dir = self::tempDir();
        Fixtures::ensure($dir);

        return $dir;
    }

    /** @param array<string,mixed> $overrides */
    public static function config(string $site, array $overrides = []): Config
    {
        return Config::fromArray(array_merge([
            'images_base_dir' => $site,
            'cache_dir' => $site . '/cache',
            'log_dir' => $site . '/log',
            'logging' => false,
            'redirect_to_cache' => false,
        ], $overrides));
    }

    /** @param array<string,mixed> $overrides */
    public static function app(
        string $site,
        array $overrides = [],
        ?LoggerInterface $logger = null,
        ?ResizerInterface $resizer = null,
    ): Application {
        $config = self::config($site, $overrides);

        return new Application(
            $config,
            $resizer ?? new ResizeService($config),
            new CacheStore($config->cacheDir),
            $logger ?? ($config->logging ? new DebugLogger($config->logDir) : new NullLogger()),
        );
    }

    /**
     * @param array<string,mixed> $get
     * @param array<string,mixed> $server
     */
    public static function request(string $site, array $get = [], array $server = []): Request
    {
        return Request::fromArray(
            array_merge(['name' => 'photo.jpg'], $get),
            array_merge([
                'REQUEST_METHOD' => 'GET',
                'SCRIPT_NAME' => '/iar/index.php',
                'REQUEST_URI' => '/iar/index.php?' . http_build_query($get),
                'DOCUMENT_ROOT' => $site,
                'HTTP_ACCEPT' => '',
            ], $server),
        );
    }

    public static function cleanup(): void
    {
        foreach (self::$dirs as $dir) {
            self::removeDir($dir);
        }
        self::$dirs = [];
    }

    public static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::removeDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    /** Отправляет ответ в TestSapi, перехватывая terminate(). */
    public static function send(\App\Http\Response $response): TestSapi
    {
        $sapi = new TestSapi();

        try {
            $response->send($sapi);
        } catch (ExitCalled) {
            // ожидаемое завершение запроса
        }

        return $sapi;
    }
}
