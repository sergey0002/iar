<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Infrastructure\DebugLogger;
use App\Infrastructure\NullLogger;
use App\Tests\Support\Factory;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Factory::tempDir() . '/log';
    }

    protected function tearDown(): void
    {
        Factory::cleanup();
    }

    private function logFile(): string
    {
        return $this->dir . '/' . date('Y-m-d') . '.log';
    }

    public function testNullLoggerWritesNothing(): void
    {
        (new NullLogger())->log('hit', ['status' => 200]);

        self::assertFalse(is_dir($this->dir));
    }

    public function testDebugLoggerCreatesDirectoryAndWritesJson(): void
    {
        (new DebugLogger($this->dir))->log('miss', ['status' => 200, 'duration_ms' => 12.5]);

        self::assertFileExists($this->logFile());
        $line = trim((string) file_get_contents($this->logFile()));
        $record = json_decode($line, true);

        self::assertIsArray($record);
        self::assertSame('miss', $record['event']);
        self::assertSame(200, $record['status']);
        self::assertSame(12.5, $record['duration_ms']);
        self::assertArrayHasKey('ts', $record);
    }

    public function testDebugLoggerAppendsLines(): void
    {
        $logger = new DebugLogger($this->dir);
        $logger->log('hit', ['a' => 1]);
        $logger->log('hit', ['a' => 2]);

        self::assertCount(2, file($this->logFile()));
    }

    /** Аудит L-04: чувствительные параметры редактируются. */
    public function testSensitiveKeysAreRedacted(): void
    {
        (new DebugLogger($this->dir))->log('hit', [
            'params' => ['token' => 'secret-value', 'password' => 'p', 'api_key' => 'k', 'w' => '100'],
        ]);

        $content = (string) file_get_contents($this->logFile());
        self::assertStringNotContainsString('secret-value', $content);
        self::assertStringContainsString('[redacted]', $content);
        self::assertStringContainsString('"w":"100"', $content);
    }

    /** Аудит L-07: переводы строк экранируются (защита от подделки строк лога). */
    public function testNewlinesAreEscaped(): void
    {
        (new DebugLogger($this->dir))->log("hit\nFAKE", ['url' => "/x.jpg\r\nFAKE LINE"]);

        $content = (string) file_get_contents($this->logFile());
        self::assertCount(1, file($this->logFile()));
        self::assertStringContainsString('\\n', $content);
        self::assertStringContainsString('\\r', $content);
    }

    public function testNonScalarValuesAreReplaced(): void
    {
        (new DebugLogger($this->dir))->log('hit', ['obj' => new \stdClass()]);

        self::assertStringContainsString('[non-scalar]', (string) file_get_contents($this->logFile()));
    }

    public function testNullAndBoolValuesArePreserved(): void
    {
        (new DebugLogger($this->dir))->log('hit', ['a' => null, 'b' => true, 'c' => 5]);

        $record = json_decode(trim((string) file_get_contents($this->logFile())), true);
        self::assertNull($record['a']);
        self::assertTrue($record['b']);
        self::assertSame(5, $record['c']);
    }

    public function testUnencodablePayloadIsSkipped(): void
    {
        // Некорректный UTF-8 приводит к ошибке json_encode — лог просто пропускается.
        (new DebugLogger($this->dir))->log('hit', ['bad' => "\xB1\x31"]);

        self::assertFalse(is_file($this->logFile()));
    }

    public function testUnwritableDirectoryIsSilentlySkipped(): void
    {
        $blocker = Factory::tempDir() . '/file';
        file_put_contents($blocker, 'x');

        (new DebugLogger($blocker . '/log'))->log('hit', ['a' => 1]);

        self::assertFalse(is_dir($blocker . '/log'));
    }

    public function testNestedArrayIsSanitizedRecursively(): void
    {
        (new DebugLogger($this->dir))->log('hit', ['params' => ['nested' => ['secret' => 'x', 'ok' => 'y']]]);

        $content = (string) file_get_contents($this->logFile());
        self::assertStringContainsString('[redacted]', $content);
        self::assertStringContainsString('"ok":"y"', $content);
    }
}
