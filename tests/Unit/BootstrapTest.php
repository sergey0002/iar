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

    /** Аудит S-03: некорректный конфиг → 500, а не отключение защиты. */
    public function testInvalidConfigProducesInternalError(): void
    {
        $this->writeConfig(['images_base_dir' => $this->site . '/does-not-exist']);
        $sapi = $this->run(['w' => '120']);

        self::assertSame(500, $sapi->status);
        self::assertSame("Configuration error.\n", $sapi->body);
        self::assertSame('no-store', $sapi->headerValue('Cache-Control'));
    }

    public function testUnexpectedThrowableIsLoggedAndHidden(): void
    {
        file_put_contents($this->configFile, '<?php throw new RuntimeException("secret path /var/www");');
        $sapi = $this->run(['w' => '120']);

        self::assertSame(500, $sapi->status);
        self::assertSame("Internal error.\n", $sapi->body);
        self::assertStringNotContainsString('secret path', $sapi->body);
    }

    public function testLoggingEnabledWritesDebugLog(): void
    {
        $this->writeConfig(['logging' => true]);
        $this->run(['w' => '120']);

        self::assertFileExists($this->site . '/log/' . date('Y-m-d') . '.log');
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
}
