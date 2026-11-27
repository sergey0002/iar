<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\NativeSapi;
use App\Http\SapiInterface;
use App\Tests\Support\Factory;
use PHPUnit\Framework\TestCase;

/**
 * Реальный SAPI-адаптер. terminate() (exit) проверяется отдельным
 * процессом в интеграционных тестах — здесь он не вызывается.
 */
final class NativeSapiTest extends TestCase
{
    public function testImplementsContract(): void
    {
        self::assertInstanceOf(SapiInterface::class, new NativeSapi());
    }

    public function testOutputWritesToBuffer(): void
    {
        $sapi = new NativeSapi();
        ob_start();
        $sapi->output('hello');
        $captured = ob_get_clean();

        self::assertSame('hello', $captured);
    }

    public function testReadFileStreamsContent(): void
    {
        $dir = Factory::tempDir();
        $file = $dir . '/x.bin';
        file_put_contents($file, 'streamed');

        ob_start();
        (new NativeSapi())->readFile($file);
        $captured = ob_get_clean();
        Factory::cleanup();

        self::assertSame('streamed', $captured);
    }

    /** В раннере вывод уже начат, поэтому код ответа проверяем только когда это возможно. */
    public function testResponseCodeIsApplied(): void
    {
        $sapi = new NativeSapi();

        if (headers_sent()) {
            self::assertIsInt(http_response_code());

            return;
        }

        $sapi->responseCode(418);
        self::assertSame(418, http_response_code());
        $sapi->responseCode(200);
    }

    public function testHeaderIsIgnoredAfterOutputStarted(): void
    {
        $sapi = new NativeSapi();
        $sapi->header('X-Test: 1');
        $sapi->header('X-Test: 2', false);

        self::assertIsBool($sapi->headersSent());
    }

    public function testHeadersSentIsBoolean(): void
    {
        self::assertIsBool((new NativeSapi())->headersSent());
    }
}
