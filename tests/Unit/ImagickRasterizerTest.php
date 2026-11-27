<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Infrastructure\ImagickRasterizer;
use App\Tests\Support\Factory;
use PHPUnit\Framework\TestCase;

/**
 * Проверяет адаптер к Imagick. Без расширения подключается заглушка
 * (tests/Support/imagick-stub.php) — проверяется последовательность
 * вызовов; с расширением те же тесты работают на реальном Imagick.
 */
final class ImagickRasterizerTest extends TestCase
{
    private string $site;

    private bool $stubbed;

    protected function setUp(): void
    {
        $this->site = Factory::site();
        $this->stubbed = !extension_loaded('imagick');
        if ($this->stubbed) {
            \Imagick::reset();
        }
    }

    protected function tearDown(): void
    {
        Factory::cleanup();
    }

    public function testAvailabilityMatchesExtension(): void
    {
        self::assertSame(class_exists(\Imagick::class), (new ImagickRasterizer())->isAvailable());
    }

    public function testRasterizationProducesPng(): void
    {
        $bytes = (new ImagickRasterizer())->toPng($this->site . '/icon.svg', 144, 40, 40);

        self::assertSame("\x89PNG", substr($bytes, 0, 4));
        $info = getimagesizefromstring($bytes);
        self::assertNotSame(false, $info);
        self::assertSame(40, $info[0]);
        self::assertSame(40, $info[1]);
    }

    public function testRasterizationWithoutTargetSizeKeepsNativeSize(): void
    {
        $bytes = (new ImagickRasterizer())->toPng($this->site . '/icon.svg', 72, 0, 0);

        self::assertSame("\x89PNG", substr($bytes, 0, 4));
        if ($this->stubbed) {
            self::assertNull(\Imagick::$lastThumbnail);
            self::assertSame(72, \Imagick::$lastDensity);
            self::assertSame($this->site . '/icon.svg', \Imagick::$lastFile);
        }
    }

    /** Аудит B-16: ресурс освобождается даже при ошибке чтения. */
    public function testResourceIsClearedOnFailure(): void
    {
        if (!$this->stubbed) {
            $missing = $this->site . '/missing.svg';
        } else {
            \Imagick::$failRead = true;
            $missing = $this->site . '/icon.svg';
        }

        try {
            (new ImagickRasterizer())->toPng($missing, 72, 10, 10);
            self::fail('Expected an exception');
        } catch (\Throwable $e) {
            self::assertNotSame('', $e->getMessage());
        }

        if ($this->stubbed) {
            self::assertTrue(\Imagick::$cleared);
        }
    }

    public function testResourceIsClearedOnSuccess(): void
    {
        (new ImagickRasterizer())->toPng($this->site . '/icon.svg', 72, 10, 10);

        if ($this->stubbed) {
            self::assertTrue(\Imagick::$cleared);
            self::assertSame([10, 10, true, false], \Imagick::$lastThumbnail);
        }
    }
}
