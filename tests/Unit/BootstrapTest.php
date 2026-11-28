<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Bootstrap;
use App\Tests\Support\Factory;
use App\Tests\Support\TestSapi;
use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    private string $site;

    private string $configFile;

    protected function setUp(): void
    {
        $this->site = Factory::site();
        $this->configFile = Factory::tempDir() . '/config.php';
        $this->writeConfig();
    }

    protected function tearDown(): void
    {
        Factory::cleanup();
    }

    private function writeConfig(array $overrides = []): void
    {
        $config = array_merge([
            'images_base_dir' => $this->site,
            'cache_dir' => $this->site . '/cache',
            'log_dir' => $this->site . '/log',
            'logging' => false,
            'redirect_to_cache' => false,
        ], $overrides);

        file_put_contents($this->configFile, '<?php return ' . var_export($config, true) . ';');
    }

    private function run(array $get = [], array $server = []): TestSapi
    {
        $sapi = new TestSapi();

        try {
            Bootstrap::run(
                $sapi,
                array_merge(['name' => 'photo.jpg'], $get),
                array_merge([
                    'REQUEST_METHOD' => 'GET',
                    'SCRIPT_NAME' => '/iar/index.php',
                    'REQUEST_URI' => '/iar/index.php',
                    'DOCUMENT_ROOT' => $this->site,
                ], $server),
                $this->configFile,
            );
        } catch (\App\Tests\Support\ExitCalled) {
        }

        return $sapi;
    }

    public function testSuccessfulRequest(): void
    {
        $sapi = $this->run(['w' => '120']);

        self::assertSame(200, $sapi->status);
        self::assertSame('image/jpeg', $sapi->headerValue('Content-Type'));
        self::assertNotSame('', $sapi->body);
        self::assertTrue($sapi->terminated);
    }

    public function testDisplayErrorsIsDisabled(): void
    {
        $this->run(['w' => '120']);

        self::assertSame('0', ini_get('display_errors'));
    }

    /** Аудит E-01/E-03: ошибки наружу нейтральны и без путей. */
    public function testBadRequestProducesNeutralResponse(): void
    {
        $sapi = $this->run(['w' => 'abc']);

        self::assertSame(400, $sapi->status);
        self::assertSame("Bad request.\n", $sapi->body);
        self::assertStringNotContainsString($this->site, $sapi->body);
    }

    public function testMethodNotAllowed(): void
    {
        $sapi = $this->run([], ['REQUEST_METHOD' => 'POST']);

        self::assertSame(405, $sapi->status);
        self::assertSame('GET, HEAD', $sapi->headerValue('Allow'));
    }

    public function testNotFound(): void
    {
        $sapi = $this->run(['name' => 'missing.jpg']);

        self::assertSame(404, $sapi->status);
    }

    public function testHeadRequestProducesNoBody(): void
    {
        $sapi = $this->run(['w' => '120'], ['REQUEST_METHOD' => 'HEAD']);

        self::assertSame(200, $sapi->status);
        self::assertSame('', $sapi->body);
    }

    public function testHeadErrorProducesNoBody(): void
    {
        $sapi = $this->run(['w' => 'abc'], ['REQUEST_METHOD' => 'HEAD']);

        self::assertSame(400, $sapi->status);
        self::assertSame('', $sapi->body);
    }

    /**
     * Политика надёжности: сломанный конфиг не оставляет пользователя
     * без картинки — отдаётся оригинал (проверка пути при этом не ослабляется).
     */
    public function testInvalidConfigFallsBackToOriginal(): void
    {
        $this->writeConfig(['images_base_dir' => $this->site . '/does-not-exist']);
        $sapi = $this->run(['w' => '120']);

        self::assertSame(200, $sapi->status);
        self::assertSame('original', $sapi->headerValue('X-Iar-Fallback'));
        self::assertSame('image/jpeg', $sapi->headerValue('Content-Type'));
        self::assertSame('no-store', $sapi->headerValue('Cache-Control'));
        self::assertSame(file_get_contents($this->site . '/photo.jpg'), $sapi->body);
    }

    public function testInvalidConfigWithoutOriginalIsNeutral500(): void
    {
        $this->writeConfig(['images_base_dir' => $this->site . '/does-not-exist']);
        $sapi = $this->run(['name' => 'missing.jpg', 'w' => '120']);

        self::assertSame(500, $sapi->status);
        self::assertSame("Internal error.\n", $sapi->body);
    }

    public function testUnexpectedThrowableFallsBackToOriginalWithoutLeakingDetails(): void
    {
        file_put_contents($this->configFile, '<?php throw new RuntimeException("secret path /var/www");');
        $sapi = $this->run(['w' => '120']);

        self::assertSame(200, $sapi->status);
        self::assertSame('original', $sapi->headerValue('X-Iar-Fallback'));
        self::assertStringNotContainsString('secret path', $sapi->body);
    }

    /** Клиентские ошибки оригинал НЕ отдают: иначе обход белого списка. */
    public function testForbiddenExtensionIsNotReplacedByOriginal(): void
    {
        $sapi = $this->run(['name' => 'secret.txt']);

        self::assertSame(403, $sapi->status);
        self::assertNull($sapi->headerValue('X-Iar-Fallback'));
        self::assertStringNotContainsString('do not serve me', $sapi->body);
    }

    public function testLoggingAtInfoLevelWritesRequestEvents(): void
    {
        $this->writeConfig(['logging' => true, 'log_level' => 'info']);
        $this->run(['w' => '120']);

        self::assertFileExists($this->site . '/log/' . date('Y-m-d') . '.log');
    }

    public function testLoggingAtErrorLevelWritesNothingOnSuccess(): void
    {
        $this->writeConfig(['logging' => true]);
        $this->run(['w' => '120']);

        self::assertDirectoryDoesNotExist($this->site . '/log');
    }

    public function testLoggingAtErrorLevelWritesFailureWithReason(): void
    {
        $this->writeConfig(['logging' => true, 'images_base_dir' => $this->site . '/nope']);
        $this->run(['w' => '120']);

        $content = (string) file_get_contents($this->site . '/log/' . date('Y-m-d') . '.log');
        self::assertStringContainsString('"event":"fail"', $content);
        self::assertStringContainsString('images_base_dir', $content);
        self::assertStringContainsString('"stage":"bootstrap"', $content);
    }

    public function testLoggingDisabledWritesNothing(): void
    {
        $this->run(['w' => '120']);

        self::assertDirectoryDoesNotExist($this->site . '/log');
    }

    public function testFatalHandlerIgnoresMissingError(): void
    {
        $sapi = new TestSapi();
        Bootstrap::handleFatal($sapi, null);

        // error_get_last() может быть непустым из-за предыдущих тестов,
        // но не фатальным — ответ не должен формироваться.
        self::assertSame([], $sapi->headers);
        self::assertSame('', $sapi->body);
    }

    public function testFatalHandlerIgnoresNonFatalErrors(): void
    {
        $sapi = new TestSapi();
        Bootstrap::handleFatal($sapi, ['type' => E_WARNING, 'message' => 'just a warning']);

        self::assertSame('', $sapi->body);
        self::assertSame(200, $sapi->status);
    }

    public function testFatalHandlerSendsNeutralResponse(): void
    {
        $sapi = new TestSapi();

        try {
            Bootstrap::handleFatal($sapi, ['type' => E_ERROR, 'message' => 'Allowed memory size exhausted']);
        } catch (\App\Tests\Support\ExitCalled) {
        }

        self::assertSame(500, $sapi->status);
        self::assertSame("Internal error.\n", $sapi->body);
        self::assertSame('no-store', $sapi->headerValue('Cache-Control'));
        self::assertStringNotContainsString('memory', $sapi->body);
    }

    public function testFatalHandlerSkipsWhenHeadersAlreadySent(): void
    {
        $sapi = new TestSapi();
        $sapi->forceHeadersSent = true;
        Bootstrap::handleFatal($sapi, ['type' => E_ERROR, 'message' => 'boom']);

        self::assertSame('', $sapi->body);
    }

    public function testFatalHandlerToleratesTerminateException(): void
    {
        $sapi = new TestSapi();
        // TestSapi::terminate() бросает ExitCalled — обработчик не должен падать.
        Bootstrap::handleFatal($sapi, ['type' => E_USER_ERROR, 'message' => 'boom']);

        self::assertSame(500, $sapi->status);
    }

    /** Тело shutdown-обработчика вызывается напрямую, без завершения процесса. */
    public function testFatalHandlerClosureDelegates(): void
    {
        $sapi = new TestSapi();
        $handler = Bootstrap::fatalHandler($sapi);
        error_clear_last();
        $handler();

        self::assertSame('', $sapi->body);
        self::assertSame(200, $sapi->status);
    }

    public function testFatalHandlerWithoutAnyError(): void
    {
        error_clear_last();
        $sapi = new TestSapi();
        Bootstrap::handleFatal($sapi);

        self::assertSame('', $sapi->body);
        self::assertSame([], $sapi->headers);
    }

    /** Фатальная ошибка (например OOM) — браузер всё равно получает оригинал. */
    public function testFatalErrorServesTheOriginal(): void
    {
        $sapi = new TestSapi();

        try {
            Bootstrap::handleFatal(
                $sapi,
                ['type' => E_ERROR, 'message' => 'Allowed memory size exhausted'],
                ['name' => 'photo.jpg'],
                ['DOCUMENT_ROOT' => $this->site, 'SCRIPT_NAME' => '/iar/index.php', 'REQUEST_URI' => '/iar/photo.jpg?w=100'],
            );
        } catch (\App\Tests\Support\ExitCalled) {
        }

        self::assertSame(200, $sapi->status);
        self::assertSame('original', $sapi->headerValue('X-Iar-Fallback'));
        self::assertSame(file_get_contents($this->site . '/photo.jpg'), $sapi->body);
    }

    public function testFatalErrorWithoutOriginalIsNeutral500(): void
    {
        $sapi = new TestSapi();

        try {
            Bootstrap::handleFatal(
                $sapi,
                ['type' => E_ERROR, 'message' => 'boom'],
                ['name' => 'missing.jpg'],
                ['DOCUMENT_ROOT' => $this->site],
            );
        } catch (\App\Tests\Support\ExitCalled) {
        }

        self::assertSame(500, $sapi->status);
        self::assertSame("Internal error.\n", $sapi->body);
    }

    public function testFatalFallbackRespectsHead(): void
    {
        $sapi = new TestSapi();

        try {
            Bootstrap::handleFatal(
                $sapi,
                ['type' => E_ERROR, 'message' => 'boom'],
                ['name' => 'photo.jpg'],
                ['DOCUMENT_ROOT' => $this->site],
                true,
            );
        } catch (\App\Tests\Support\ExitCalled) {
        }

        self::assertSame(200, $sapi->status);
        self::assertSame('', $sapi->body);
    }

    /** Аварийный путь не должен обходить белый список расширений. */
    public function testOriginalFileRejectsNonImage(): void
    {
        self::assertNull(Bootstrap::originalFile(
            ['name' => 'secret.txt'],
            ['DOCUMENT_ROOT' => $this->site],
        ));
    }

    public function testOriginalFileRejectsTraversal(): void
    {
        self::assertNull(Bootstrap::originalFile(
            ['name' => '../../../etc/passwd'],
            ['DOCUMENT_ROOT' => $this->site],
        ));
    }

    public function testOriginalFileRejectsNullByte(): void
    {
        self::assertNull(Bootstrap::originalFile(
            ['name' => "photo.jpg\0.txt"],
            ['DOCUMENT_ROOT' => $this->site],
        ));
    }

    public function testOriginalFileRejectsMissingAndDirectory(): void
    {
        self::assertNull(Bootstrap::originalFile(['name' => 'nope.jpg'], ['DOCUMENT_ROOT' => $this->site]));
        self::assertNull(Bootstrap::originalFile(['name' => 'nested'], ['DOCUMENT_ROOT' => $this->site]));
    }

    public function testOriginalFileRejectsBadDocumentRoot(): void
    {
        self::assertNull(Bootstrap::originalFile(
            ['name' => 'photo.jpg'],
            ['DOCUMENT_ROOT' => $this->site . '/nope'],
        ));
    }

    public function testOriginalFileWithoutName(): void
    {
        self::assertNull(Bootstrap::originalFile([], ['DOCUMENT_ROOT' => $this->site]));
        self::assertNull(Bootstrap::originalFile(['name' => ['x']], ['DOCUMENT_ROOT' => $this->site]));
    }

    public function testOriginalFileFromUriPath(): void
    {
        $path = Bootstrap::originalFile(
            ['name' => 'private.png'],
            [
                'DOCUMENT_ROOT' => $this->site,
                'SCRIPT_NAME' => '/iar/index.php',
                'REQUEST_URI' => '/iar/nested/www/photo.jpg?w=10',
            ],
        );

        self::assertSame($this->site . '/nested/www/photo.jpg', $path);
    }

    public function testOriginalFileWithoutDocumentRootUsesParentOfIar(): void
    {
        // CLI-режим: DOCUMENT_ROOT отсутствует, корень берётся на уровень выше IAR.
        self::assertNull(Bootstrap::originalFile(['name' => 'definitely-missing-file.jpg'], []));
    }

    public function testOriginalResponseForSvgAddsCsp(): void
    {
        $sapi = Factory::send(Bootstrap::originalResponse($this->site . '/icon.svg'));

        self::assertSame('image/svg+xml', $sapi->headerValue('Content-Type'));
        self::assertStringContainsString("default-src 'none'", (string) $sapi->headerValue('Content-Security-Policy'));
        self::assertSame('no-store', $sapi->headerValue('Cache-Control'));
    }

    public function testOriginalResponseForUnknownExtension(): void
    {
        $file = $this->site . '/blob.bin';
        file_put_contents($file, 'x');
        $sapi = Factory::send(Bootstrap::originalResponse($file));

        self::assertSame('application/octet-stream', $sapi->headerValue('Content-Type'));
        self::assertSame('1', $sapi->headerValue('Content-Length'));
    }

    /** Конфиг не возвращает массив: аварийный логгер не падает. */
    public function testNonArrayConfigStillServesOriginal(): void
    {
        $file = $this->site . '/bad-config.php';
        file_put_contents($file, '<?php return 42;');
        $sapi = new TestSapi();

        try {
            Bootstrap::run(
                $sapi,
                ['name' => 'photo.jpg'],
                ['DOCUMENT_ROOT' => $this->site, 'REQUEST_URI' => '/iar/photo.jpg?w=10', 'SCRIPT_NAME' => '/iar/index.php'],
                $file,
            );
        } catch (\App\Tests\Support\ExitCalled) {
        }

        self::assertSame(200, $sapi->status);
        self::assertSame('original', $sapi->headerValue('X-Iar-Fallback'));
    }

    /** Невалидный конфиг + config.local.php: настройки лога берутся из обоих. */
    public function testEmergencyLoggerMergesLocalConfig(): void
    {
        $dir = $this->site . '/iar';
        mkdir($dir);
        file_put_contents($dir . '/config.php', '<?php return ["images_base_dir" => "/definitely/missing", "logging" => true];');
        file_put_contents($dir . '/config.local.php', '<?php return ["log_dir" => "' . $this->site . '/local-log"];');
        $sapi = new TestSapi();

        try {
            Bootstrap::run(
                $sapi,
                ['name' => 'photo.jpg'],
                ['DOCUMENT_ROOT' => $this->site, 'REQUEST_URI' => '/iar/photo.jpg', 'SCRIPT_NAME' => '/iar/index.php'],
                $dir . '/config.php',
            );
        } catch (\App\Tests\Support\ExitCalled) {
        }

        $logs = glob($this->site . '/local-log/*.log') ?: [];
        self::assertCount(1, $logs);
        $record = json_decode(trim((string) file_get_contents($logs[0])), true);
        self::assertSame('fail', $record['event']);
        self::assertSame('bootstrap', $record['stage']);
    }

    /** Локальный конфиг не массив — аварийный логгер это переживает. */
    public function testEmergencyLoggerToleratesNonArrayLocalConfig(): void
    {
        $dir = $this->site . '/iar2';
        mkdir($dir);
        file_put_contents($dir . '/config.php', '<?php return ["images_base_dir" => "/definitely/missing"];');
        file_put_contents($dir . '/config.local.php', '<?php return "nope";');
        $sapi = new TestSapi();

        try {
            Bootstrap::run($sapi, ['name' => 'photo.jpg'], ['DOCUMENT_ROOT' => $this->site], $dir . '/config.php');
        } catch (\App\Tests\Support\ExitCalled) {
        }

        self::assertSame(200, $sapi->status);
    }

    /** Скрипт в корне сайта: имя берётся из полного пути URI. */
    public function testOriginalFileWithScriptInDocumentRoot(): void
    {
        $path = Bootstrap::originalFile(
            [],
            [
                'DOCUMENT_ROOT' => $this->site,
                'SCRIPT_NAME' => '/index.php',
                'REQUEST_URI' => '/photo.jpg?w=10',
            ],
        );

        self::assertSame($this->site . '/photo.jpg', $path);
    }
}
