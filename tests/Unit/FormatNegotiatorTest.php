<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Format;
use App\Domain\FormatNegotiator;
use App\Exception\UnsupportedFormatException;
use App\Config;
use App\Tests\Support\Factory;
use PHPUnit\Framework\TestCase;

final class FormatNegotiatorTest extends TestCase
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

    private function config(array $overrides = []): Config
    {
        return Factory::config($this->site, $overrides);
    }

    private function negotiator(array $overrides = []): FormatNegotiator
    {
        return new FormatNegotiator($this->config($overrides));
    }

    public function testExplicitFormatWins(): void
    {
        $n = $this->negotiator();

        self::assertSame(Format::Png, $n->negotiate(Format::Jpg, Format::Png, 'image/webp'));
    }

    /** Аудит B-04: fm=svg для растра запрещён. */
    public function testSvgOutputForRasterIsRejected(): void
    {
        $this->expectException(UnsupportedFormatException::class);
        $this->negotiator()->negotiate(Format::Jpg, Format::Svg, '');
    }

    public function testSvgSourceStaysSvgWithoutExplicitFormat(): void
    {
        self::assertSame(Format::Svg, $this->negotiator()->negotiate(Format::Svg, null, 'image/webp'));
    }

    public function testSvgSourceCanBeRasterizedExplicitly(): void
    {
        self::assertSame(Format::Png, $this->negotiator()->negotiate(Format::Svg, Format::Png, ''));
    }

    public function testSvgToSvgExplicitIsAllowed(): void
    {
        self::assertSame(Format::Svg, $this->negotiator()->negotiate(Format::Svg, Format::Svg, ''));
    }

    /** Аудит B-07: GIF не конвертируется автоматически — анимация сохраняется. */
    public function testGifIsNeverAutoConverted(): void
    {
        $n = $this->negotiator();

        self::assertSame(Format::Gif, $n->negotiate(Format::Gif, null, 'image/avif,image/webp'));
    }

    public function testGifCanBeConvertedExplicitly(): void
    {
        self::assertSame(Format::Webp, $this->negotiator()->negotiate(Format::Gif, Format::Webp, ''));
    }

    public function testAutoFormatDisabledKeepsSource(): void
    {
        $n = $this->negotiator(['auto_format' => false]);

        self::assertSame(Format::Jpg, $n->negotiate(Format::Jpg, null, 'image/webp'));
    }

    public function testWebpChosenWhenAcceptedAndAvailable(): void
    {
        $n = $this->negotiator();
        $expected = function_exists('imagewebp') ? Format::Webp : Format::Jpg;

        self::assertSame($expected, $n->negotiate(Format::Jpg, null, 'image/webp,image/*'));
    }

    public function testAvifPreferredOverWebpWhenAvailable(): void
    {
        $n = $this->negotiator();
        $accept = 'image/avif,image/webp';

        if (function_exists('imageavif')) {
            self::assertSame(Format::Avif, $n->negotiate(Format::Jpg, null, $accept));
        } elseif (function_exists('imagewebp')) {
            self::assertSame(Format::Webp, $n->negotiate(Format::Jpg, null, $accept));
        } else {
            self::assertSame(Format::Jpg, $n->negotiate(Format::Jpg, null, $accept));
        }
    }

    public function testNoAcceptKeepsSource(): void
    {
        self::assertSame(Format::Png, $this->negotiator()->negotiate(Format::Png, null, ''));
    }

    public function testFormatDisallowedByConfigIsNotAutoUsed(): void
    {
        $n = $this->negotiator(['allowed_extensions' => ['jpg', 'jpeg', 'png']]);

        self::assertSame(Format::Jpg, $n->negotiate(Format::Jpg, null, 'image/webp,image/avif'));
    }

    /** Аудит H-03: Vary только при авто-формате и растровом источнике. */
    public function testVaryOnAcceptWhenAutoFormat(): void
    {
        self::assertTrue($this->negotiator()->variesOnAccept(Format::Jpg, null));
    }

    public function testNoVaryWhenExplicitFormat(): void
    {
        self::assertFalse($this->negotiator()->variesOnAccept(Format::Jpg, Format::Png));
    }

    public function testNoVaryWhenAutoFormatDisabled(): void
    {
        self::assertFalse($this->negotiator(['auto_format' => false])->variesOnAccept(Format::Jpg, null));
    }

    public function testNoVaryForSvgSource(): void
    {
        self::assertFalse($this->negotiator()->variesOnAccept(Format::Svg, null));
    }

    /** GIF не зависит от Accept — Vary не нужен. */
    public function testNoVaryForGifSource(): void
    {
        self::assertFalse($this->negotiator()->variesOnAccept(Format::Gif, null));
    }

    /** Ветка AVIF проверяется и без расширения — через пробник энкодеров. */
    public function testAvifBranchWithInjectedProbe(): void
    {
        $n = new FormatNegotiator($this->config(), static fn (Format $f): bool => true);

        self::assertSame(Format::Avif, $n->negotiate(Format::Jpg, null, 'image/avif,image/webp'));
    }

    public function testProbeCanDisableAllEncoders(): void
    {
        $n = new FormatNegotiator($this->config(), static fn (Format $f): bool => false);

        self::assertSame(Format::Jpg, $n->negotiate(Format::Jpg, null, 'image/avif,image/webp'));
    }

    public function testProbeRespectsAllowedFormats(): void
    {
        $n = new FormatNegotiator(
            $this->config(['allowed_extensions' => ['jpg', 'webp']]),
            static fn (Format $f): bool => true,
        );

        self::assertSame(Format::Webp, $n->negotiate(Format::Jpg, null, 'image/avif,image/webp'));
    }

    public function testProbeIsNotConsultedWithoutAccept(): void
    {
        $n = new FormatNegotiator($this->config(), static function (Format $f): bool {
            throw new \RuntimeException('must not be called');
        });

        self::assertSame(Format::Jpg, $n->negotiate(Format::Jpg, null, ''));
    }
}
