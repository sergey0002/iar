<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\CropParam;
use App\Domain\Side;
use App\Domain\Unit;
use App\Exception\BadRequestException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CropParamTest extends TestCase
{
    public function testPixelsAreParsed(): void
    {
        $crop = CropParam::parse(Side::Top, '50');

        self::assertSame(50.0, $crop->value);
        self::assertSame(Unit::Pixels, $crop->unit);
        self::assertSame(Side::Top, $crop->side);
    }

    public function testPercentSuffixP(): void
    {
        $crop = CropParam::parse(Side::Bottom, '15p');

        self::assertSame(15.0, $crop->value);
        self::assertSame(Unit::Percent, $crop->unit);
    }

    public function testPercentSuffixSign(): void
    {
        $crop = CropParam::parse(Side::Left, '20%');

        self::assertSame(20.0, $crop->value);
        self::assertSame(Unit::Percent, $crop->unit);
    }

    public function testFractionalValues(): void
    {
        $crop = CropParam::parse(Side::Right, '12.5');

        self::assertSame(12.5, $crop->value);
    }

    public function testWhitespaceIsTrimmed(): void
    {
        self::assertSame(10.0, CropParam::parse(Side::Top, '  10  ')->value);
    }

    public function testEmptyValueReturnsNull(): void
    {
        self::assertNull(CropParam::parse(Side::Top, ''));
        self::assertNull(CropParam::parse(Side::Top, '   '));
    }

    public function testZeroIsAllowed(): void
    {
        self::assertSame(0.0, CropParam::parse(Side::Top, '0')->value);
    }

    public function testHundredPercentIsAllowed(): void
    {
        self::assertSame(100.0, CropParam::parse(Side::Top, '100p')->value);
    }

    /** Аудит B-05: отрицательные значения отклоняются. */
    public function testNegativeValueRejected(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('negative');
        CropParam::parse(Side::Top, '-50');
    }

    public function testNegativePercentRejected(): void
    {
        $this->expectException(BadRequestException::class);
        CropParam::parse(Side::Top, '-10p');
    }

    /** Аудит B-05: проценты больше 100 отклоняются. */
    public function testPercentAboveHundredRejected(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('100');
        CropParam::parse(Side::Top, '150p');
    }

    public function testAbsurdPixelValueRejected(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('too large');
        CropParam::parse(Side::Top, '999999');
    }

    public static function nonNumeric(): array
    {
        return [['abc'], ['p'], ['%'], ['12abc'], ['1e'], ['--5']];
    }

    #[DataProvider('nonNumeric')]
    public function testNonNumericRejected(string $raw): void
    {
        $this->expectException(BadRequestException::class);
        CropParam::parse(Side::Top, $raw);
    }

    public function testSuffixForPixels(): void
    {
        self::assertSame('_ct50', CropParam::parse(Side::Top, '50')->suffix());
    }

    public function testSuffixForPercent(): void
    {
        self::assertSame('_cb15p', CropParam::parse(Side::Bottom, '15p')->suffix());
    }

    public function testSuffixTrimsTrailingZeros(): void
    {
        self::assertSame('_cl12.5', CropParam::parse(Side::Left, '12.5000')->suffix());
        self::assertSame('_cr0', CropParam::parse(Side::Right, '0')->suffix());
    }
}
