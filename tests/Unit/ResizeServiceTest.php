<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\CropParam;
use App\Domain\Format;
use App\Domain\Gravity;
use App\Domain\Mode;
use App\Domain\ResizeParams;
use App\Domain\ResizeService;
use App\Domain\Side;
use App\Domain\SvgRasterizer;
use App\Exception\InputTooLargeException;
use App\Exception\ProcessingException;
use App\Exception\UnsupportedFormatException;
use App\Tests\Support\Factory;
use App\Tests\Support\FakeRasterizer;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use PHPUnit\Framework\TestCase;

final class ResizeServiceTest extends TestCase
{
    private string $site;

    protected function setUp(): void
    {
        $this->site = Factory::site();
    }

    protected function tearDown(): void
    {
        Factory::cleanup();
    }

    private function service(array $overrides = [], ?ImageManager $manager = null): ResizeService
    {
        return new ResizeService(Factory::config($this->site, $overrides), $manager);
    }

    private function params(
        int $w = 0,
        int $h = 0,
        Mode $mode = Mode::Fit,
        Gravity $g = Gravity::Center,
        ?CropParam $crop = null,
    ): ResizeParams {
        return new ResizeParams($w, $h, $mode, $g, $crop);
    }

    /** @return array{0:int,1:int} */
    private function size(string $bytes): array
    {
        $im = imagecreatefromstring($bytes);
        self::assertNotSame(false, $im);
        $size = [imagesx($im), imagesy($im)];
        imagedestroy($im);

        return $size;
    }

    private function pixel(string $bytes, int $x, int $y): int
    {
        $im = imagecreatefromstring($bytes);
        // Для палитровых PNG imagecolorat() возвращает индекс, а не RGB.
        imagepalettetotruecolor($im);
        $color = imagecolorat($im, $x, $y) & 0xFFFFFF;
        imagedestroy($im);

        return $color;
    }

    public function testFitByWidth(): void
    {
        $bytes = $this->service()->render($this->site . '/photo.jpg', $this->params(300), Format::Jpg);

        self::assertSame([300, 200], $this->size($bytes));
    }

    public function testFitByHeight(): void
    {
        $bytes = $this->service()->render($this->site . '/photo.jpg', $this->params(0, 100), Format::Jpg);

        self::assertSame([150, 100], $this->size($bytes));
    }

    public function testFitDoesNotUpscale(): void
    {
        $bytes = $this->service()->render($this->site . '/photo.jpg', $this->params(5000, 5000), Format::Jpg);

        self::assertSame([1200, 800], $this->size($bytes));
    }

    public function testCoverExactDimensions(): void
    {
        $bytes = $this->service()->render(
            $this->site . '/photo.jpg',
            $this->params(200, 200, Mode::Cover),
            Format::Jpg,
        );

        self::assertSame([200, 200], $this->size($bytes));
    }

    public function testCoverWithSingleDimensionIsSquare(): void
    {
        $bytes = $this->service()->render(
            $this->site . '/photo.jpg',
            $this->params(150, 0, Mode::Cover),
            Format::Jpg,
        );

        self::assertSame([150, 150], $this->size($bytes));
    }

    public function testCoverWithHeightOnly(): void
    {
        $bytes = $this->service()->render(
            $this->site . '/photo.jpg',
            $this->params(0, 120, Mode::Cover, Gravity::Top),
            Format::Jpg,
        );

        self::assertSame([120, 120], $this->size($bytes));
    }

    public function testResizeModeStretchesExactly(): void
    {
        $bytes = $this->service()->render(
            $this->site . '/photo.jpg',
            $this->params(300, 100, Mode::Resize),
            Format::Jpg,
        );

        self::assertSame([300, 100], $this->size($bytes));
    }

    /** m=resize растягивает точно; при одной размерности вторая остаётся исходной (как в v1). */
    public function testResizeWithWidthOnly(): void
    {
        $bytes = $this->service()->render(
            $this->site . '/photo.jpg',
            $this->params(240, 0, Mode::Resize),
            Format::Jpg,
        );

        self::assertSame([240, 800], $this->size($bytes));
    }

    public function testNoDimensionsKeepsSize(): void
    {
        $bytes = $this->service()->render($this->site . '/photo.jpg', $this->params(), Format::Jpg);

        self::assertSame([1200, 800], $this->size($bytes));
    }

    public function testCropTopPixels(): void
    {
        $bytes = $this->service()->render(
            $this->site . '/photo.jpg',
            $this->params(0, 0, Mode::Fit, Gravity::Center, CropParam::parse(Side::Top, '100')),
            Format::Png,
        );

        self::assertSame([1200, 700], $this->size($bytes));
    }

    public function testCropBottomPercent(): void
    {
        $bytes = $this->service()->render(
            $this->site . '/photo.jpg',
            $this->params(0, 0, Mode::Fit, Gravity::Center, CropParam::parse(Side::Bottom, '25p')),
            Format::Png,
        );

        self::assertSame([1200, 600], $this->size($bytes));
    }

    public function testCropLeftPixels(): void
    {
        $bytes = $this->service()->render(
            $this->site . '/photo.jpg',
            $this->params(0, 0, Mode::Fit, Gravity::Center, CropParam::parse(Side::Left, '200')),
            Format::Png,
        );

        self::assertSame([1000, 800], $this->size($bytes));
    }

    public function testCropRightPercent(): void
    {
        $bytes = $this->service()->render(
            $this->site . '/photo.jpg',
            $this->params(0, 0, Mode::Fit, Gravity::Center, CropParam::parse(Side::Right, '10p')),
            Format::Png,
        );

        self::assertSame([1080, 800], $this->size($bytes));
    }

    /**
     * Аудит B-05: раньше clamp до 10% не сдвигал offset и давал чёрные полосы.
     * Теперь область всегда внутри оригинала.
     */
    public function testLargeCropProducesNoBlackBars(): void
    {
        $bytes = $this->service()->render(
            $this->site . '/photo.jpg',
            $this->params(0, 0, Mode::Fit, Gravity::Center, CropParam::parse(Side::Top, '95p')),
            Format::Png,
        );

        self::assertSame([1200, 40], $this->size($bytes));
        // Нижняя строка должна содержать реальные пиксели, а не чёрную заливку.
        self::assertNotSame(0x000000, $this->pixel($bytes, 600, 39));
        self::assertNotSame(0x000000, $this->pixel($bytes, 1199, 39));
    }

    public function testHundredPercentCropKeepsOnePixel(): void
    {
        $bytes = $this->service()->render(
            $this->site . '/photo.jpg',
            $this->params(0, 0, Mode::Fit, Gravity::Center, CropParam::parse(Side::Top, '100p')),
            Format::Png,
        );

        self::assertSame([1200, 1], $this->size($bytes));
    }

    public function testCropLargerThanImageIsClamped(): void
    {
        $bytes = $this->service()->render(
            $this->site . '/photo.jpg',
            $this->params(0, 0, Mode::Fit, Gravity::Center, CropParam::parse(Side::Left, '99999')),
            Format::Png,
        );

        self::assertSame([1, 800], $this->size($bytes));
    }

    public function testCropThenResize(): void
    {
        $bytes = $this->service()->render(
            $this->site . '/photo.jpg',
            $this->params(300, 0, Mode::Fit, Gravity::Center, CropParam::parse(Side::Bottom, '50p')),
            Format::Jpg,
        );

        self::assertSame([300, 100], $this->size($bytes));
    }

    public function testEncodePng(): void
    {
        $bytes = $this->service()->render($this->site . '/photo.jpg', $this->params(100), Format::Png);

        self::assertSame("\x89PNG", substr($bytes, 0, 4));
    }

    public function testEncodeJpegAndJpegAlias(): void
    {
        foreach ([Format::Jpg, Format::Jpeg] as $format) {
            $bytes = $this->service()->render($this->site . '/photo.jpg', $this->params(100), $format);
            self::assertSame("\xFF\xD8", substr($bytes, 0, 2));
        }
    }

    public function testEncodeGif(): void
    {
        $bytes = $this->service()->render($this->site . '/photo.jpg', $this->params(100), Format::Gif);

        self::assertSame('GIF', substr($bytes, 0, 3));
    }

    public function testEncodeWebpWhenAvailable(): void
    {
        if (!function_exists('imagewebp')) {
            self::markTestSkipped('GD WebP support is not available');
        }

        $bytes = $this->service()->render($this->site . '/photo.jpg', $this->params(100), Format::Webp);

        self::assertSame('RIFF', substr($bytes, 0, 4));
        self::assertSame('WEBP', substr($bytes, 8, 4));
    }

    public function testEncodeAvifWhenAvailable(): void
    {
        if (!function_exists('imageavif')) {
            self::markTestSkipped('GD AVIF support is not available');
        }

        $bytes = $this->service()->render($this->site . '/photo.jpg', $this->params(100), Format::Avif);

        self::assertStringContainsString('ftyp', substr($bytes, 0, 32));
    }

    /** Аудит B-06: png_compression удалена; indexed-порог продолжает работать. */
    public function testSmallPngIsIndexed(): void
    {
        $bytes = $this->service()->render($this->site . '/small.png', $this->params(50), Format::Png);

        // Цветовой тип PNG находится в байте 25 (IHDR): 3 = палитра.
        self::assertSame(3, ord($bytes[25]));
    }

    public function testWidePngStaysTruecolor(): void
    {
        $bytes = $this->service()->render($this->site . '/wide.png', $this->params(300), Format::Png);

        self::assertSame(6, ord($bytes[25]));
    }

    public function testIndexedThresholdZeroMeansAlways(): void
    {
        $bytes = $this->service(['png_indexed_threshold' => 0])
            ->render($this->site . '/wide.png', $this->params(300), Format::Png);

        self::assertSame(3, ord($bytes[25]));
    }

    public function testIndexedDisabledByConfig(): void
    {
        $bytes = $this->service(['png_indexed' => false])
            ->render($this->site . '/small.png', $this->params(50), Format::Png);

        self::assertSame(6, ord($bytes[25]));
    }

    public function testLosslessDisablesIndexed(): void
    {
        $bytes = $this->service(['lossless' => true, 'png_indexed_threshold' => 0])
            ->render($this->site . '/small.png', $this->params(50), Format::Png);

        self::assertSame(6, ord($bytes[25]));
    }

    public function testLosslessProducesLargerJpeg(): void
    {
        $normal = $this->service()->render($this->site . '/photo.jpg', $this->params(400), Format::Jpg);
        $lossless = $this->service(['lossless' => true])
            ->render($this->site . '/photo.jpg', $this->params(400), Format::Jpg);

        self::assertGreaterThan(strlen($normal), strlen($lossless));
    }

    /** Анимация GIF сохраняется при ресайзе gif → gif. */
    public function testAnimatedGifKeepsFrames(): void
    {
        $original = (string) file_get_contents($this->site . '/anim.gif');
        $bytes = $this->service()->render($this->site . '/anim.gif', $this->params(4), Format::Gif);

        self::assertSame(
            substr_count($original, "\x21\xF9\x04"),
            substr_count($bytes, "\x21\xF9\x04"),
        );
        self::assertGreaterThan(1, substr_count($bytes, "\x21\xF9\x04"));
    }

    public function testSvgIsCopiedVerbatim(): void
    {
        $original = (string) file_get_contents($this->site . '/icon.svg');
        $bytes = $this->service()->render($this->site . '/icon.svg', $this->params(10), Format::Svg);

        self::assertSame($original, $bytes);
    }

    public function testSvgRasterizationWithoutImagickFails(): void
    {
        $service = new ResizeService(
            Factory::config($this->site),
            null,
            new SvgRasterizer(new ImageManager(new Driver()), new FakeRasterizer(available: false)),
        );

        $this->expectException(ProcessingException::class);
        $this->expectExceptionMessage('Imagick');
        $service->render($this->site . '/icon.svg', $this->params(10), Format::Png);
    }

    public function testSvgRasterizationToPng(): void
    {
        $bytes = $this->service()->render($this->site . '/icon.svg', $this->params(40, 40), Format::Png);

        self::assertSame("\x89PNG", substr($bytes, 0, 4));
        self::assertSame([40, 40], $this->size($bytes));
    }

    public function testSvgRasterizationToJpeg(): void
    {
        $bytes = $this->service()->render($this->site . '/icon.svg', $this->params(30, 30), Format::Jpg);

        self::assertSame("\xFF\xD8", substr($bytes, 0, 2));
    }

    public function testUnreadableSvgSourceFails(): void
    {
        $this->expectException(ProcessingException::class);
        $this->expectExceptionMessage('Could not read SVG source');
        $this->service()->render($this->site . '/missing.svg', $this->params(10), Format::Svg);
    }

    public function testAvifWithoutEncoderProducesClearError(): void
    {
        if (function_exists('imageavif')) {
            self::markTestSkipped('AVIF encoder is available');
        }

        $this->expectException(ProcessingException::class);
        $this->expectExceptionMessage('AVIF encoder is not available');
        $this->service()->render($this->site . '/photo.jpg', $this->params(100), Format::Avif);
    }

    /** Аудит S-02: лимит мегапикселей срабатывает ДО декодирования. */
    public function testOversizedInputIsRejectedBeforeDecoding(): void
    {
        $this->expectException(InputTooLargeException::class);
        $this->expectExceptionMessage('megapixels');
        $this->service()->render($this->site . '/huge.jpg', $this->params(100), Format::Jpg);
    }

    public function testMegapixelLimitIsConfigurable(): void
    {
        $bytes = $this->service(['max_input_megapixels' => 200])
            ->render($this->site . '/photo.jpg', $this->params(100), Format::Jpg);

        self::assertSame([100, 67], $this->size($bytes));
    }

    public function testOversizedFileIsRejected(): void
    {
        $this->expectException(InputTooLargeException::class);
        $this->expectExceptionMessage('max_input_bytes');
        $this->service(['max_input_bytes' => 10])
            ->render($this->site . '/photo.jpg', $this->params(100), Format::Jpg);
    }

    public function testCorruptImageFails(): void
    {
        $this->expectException(ProcessingException::class);
        $this->service()->render($this->site . '/corrupt.jpg', $this->params(100), Format::Jpg);
    }

    public function testMissingFileFails(): void
    {
        $this->expectException(ProcessingException::class);
        $this->service()->render($this->site . '/missing.jpg', $this->params(100), Format::Jpg);
    }

    public function testUnknownSourceExtensionFails(): void
    {
        $path = $this->site . '/file.tiff';
        file_put_contents($path, 'x');

        $this->expectException(UnsupportedFormatException::class);
        $this->service()->render($path, $this->params(100), Format::Jpg);
    }

    /** Аудит B-04: растр нельзя закодировать в SVG. */
    public function testRasterCannotBeEncodedAsSvg(): void
    {
        $this->expectException(UnsupportedFormatException::class);
        $this->service()->render($this->site . '/photo.jpg', $this->params(100), Format::Svg);
    }

    public function testImageManagerCanBeInjected(): void
    {
        $manager = new ImageManager(new Driver());
        $bytes = $this->service([], $manager)->render($this->site . '/photo.jpg', $this->params(80), Format::Jpg);

        self::assertSame([80, 53], $this->size($bytes));
    }

    /** Гравитация реально смещает окно обрезки (wide.png меняется и по вертикали). */
    public function testGravityAffectsCoverResult(): void
    {
        $top = $this->service()->render(
            $this->site . '/wide.png',
            $this->params(100, 50, Mode::Cover, Gravity::Top),
            Format::Png,
        );
        $bottom = $this->service()->render(
            $this->site . '/wide.png',
            $this->params(100, 50, Mode::Cover, Gravity::Bottom),
            Format::Png,
        );

        self::assertSame([100, 50], $this->size($top));
        self::assertNotSame($this->pixel($top, 50, 25), $this->pixel($bottom, 50, 25));
    }

    public function testHorizontalGravityAffectsCoverResult(): void
    {
        $left = $this->service()->render(
            $this->site . '/wide.png',
            $this->params(100, 100, Mode::Cover, Gravity::Left),
            Format::Png,
        );
        $right = $this->service()->render(
            $this->site . '/wide.png',
            $this->params(100, 100, Mode::Cover, Gravity::Right),
            Format::Png,
        );

        self::assertNotSame($this->pixel($left, 50, 50), $this->pixel($right, 50, 50));
    }

    /** Аудит: fm=WEBP в верхнем регистре больше не превращается в JPEG. */
    public function testTargetFormatIsAlreadyNormalizedByEnum(): void
    {
        self::assertSame(Format::Webp, Format::fromName('WEBP'));
    }

    /** Ошибка декодера Intervention оборачивается в доменную ошибку. */
    public function testDecoderFailureIsWrapped(): void
    {
        $this->expectException(ProcessingException::class);
        $this->service(['max_input_megapixels' => 200])
            ->render($this->site . '/huge.jpg', $this->params(100), Format::Jpg);
    }
}
