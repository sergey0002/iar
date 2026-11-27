<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Format;
use App\Domain\Gravity;
use App\Domain\Mode;
use App\Domain\Side;
use App\Domain\Unit;
use PHPUnit\Framework\TestCase;

final class EnumsTest extends TestCase
{
    public function testFormatFromNameIsCaseInsensitive(): void
    {
        self::assertSame(Format::Jpg, Format::fromName('JPG'));
        self::assertSame(Format::Webp, Format::fromName(' WebP '));
        self::assertNull(Format::fromName('tiff'));
    }

    public function testFormatMimes(): void
    {
        self::assertSame('image/jpeg', Format::Jpg->mime());
        self::assertSame('image/jpeg', Format::Jpeg->mime());
        self::assertSame('image/png', Format::Png->mime());
        self::assertSame('image/gif', Format::Gif->mime());
        self::assertSame('image/webp', Format::Webp->mime());
        self::assertSame('image/avif', Format::Avif->mime());
        self::assertSame('image/svg+xml', Format::Svg->mime());
    }

    public function testRasterDetection(): void
    {
        self::assertTrue(Format::Jpg->isRaster());
        self::assertFalse(Format::Svg->isRaster());
    }

    public function testAnimationSupport(): void
    {
        self::assertTrue(Format::Gif->supportsAnimation());
        self::assertTrue(Format::Webp->supportsAnimation());
        self::assertFalse(Format::Jpg->supportsAnimation());
        self::assertFalse(Format::Png->supportsAnimation());
    }

    /** Аудит B-04: SVG не может быть выходом для растра. */
    public function testSvgIsNotAllowedAsRasterOutput(): void
    {
        self::assertFalse(Format::Svg->isAllowedRasterOutput());
        self::assertTrue(Format::Png->isAllowedRasterOutput());
        self::assertTrue(Format::Gif->isAllowedRasterOutput());
    }

    public function testCacheExtensionNormalizesJpeg(): void
    {
        self::assertSame('jpg', Format::Jpeg->cacheExtension());
        self::assertSame('jpg', Format::Jpg->cacheExtension());
        self::assertSame('webp', Format::Webp->cacheExtension());
    }

    public function testEncoderAvailability(): void
    {
        self::assertSame(function_exists('imagewebp'), Format::Webp->encoderAvailable());
        self::assertSame(function_exists('imageavif'), Format::Avif->encoderAvailable());
        self::assertTrue(Format::Jpg->encoderAvailable());
        self::assertTrue(Format::Png->encoderAvailable());
        self::assertTrue(Format::Gif->encoderAvailable());
        self::assertTrue(Format::Svg->encoderAvailable());
    }

    public function testModeFromName(): void
    {
        self::assertSame(Mode::Fit, Mode::fromName('FIT'));
        self::assertSame(Mode::Cover, Mode::fromName(' cover '));
        self::assertSame(Mode::Resize, Mode::fromName('resize'));
        self::assertNull(Mode::fromName('stretch'));
    }

    public function testGravityFromName(): void
    {
        self::assertSame(Gravity::Center, Gravity::fromName('center'));
        self::assertSame(Gravity::TopLeft, Gravity::fromName('TOP-LEFT'));
        self::assertSame(Gravity::BottomRight, Gravity::fromName('bottom-right'));
        self::assertNull(Gravity::fromName('middle'));
    }

    public function testAllGravityValuesExist(): void
    {
        self::assertCount(9, Gravity::cases());
    }

    public function testSidePriority(): void
    {
        self::assertSame([Side::Top, Side::Right, Side::Bottom, Side::Left], Side::priority());
    }

    public function testSideValues(): void
    {
        self::assertSame('ct', Side::Top->value);
        self::assertSame('cr', Side::Right->value);
        self::assertSame('cb', Side::Bottom->value);
        self::assertSame('cl', Side::Left->value);
    }

    public function testUnitValues(): void
    {
        self::assertSame('px', Unit::Pixels->value);
        self::assertSame('p', Unit::Percent->value);
    }
}
