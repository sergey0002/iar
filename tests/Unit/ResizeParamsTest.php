<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\CropParam;
use App\Domain\Gravity;
use App\Domain\Mode;
use App\Domain\ResizeParams;
use App\Domain\Side;
use PHPUnit\Framework\TestCase;

final class ResizeParamsTest extends TestCase
{
    public function testNoOperationsForDefaults(): void
    {
        $params = new ResizeParams(0, 0, Mode::Fit, Gravity::Center);

        self::assertFalse($params->hasOperations());
        self::assertSame('', $params->modeSuffix());
    }

    public function testWidthIsOperation(): void
    {
        self::assertTrue((new ResizeParams(100, 0, Mode::Fit, Gravity::Center))->hasOperations());
    }

    public function testHeightIsOperation(): void
    {
        self::assertTrue((new ResizeParams(0, 100, Mode::Fit, Gravity::Center))->hasOperations());
    }

    public function testCropIsOperation(): void
    {
        $params = new ResizeParams(0, 0, Mode::Fit, Gravity::Center, CropParam::parse(Side::Top, '10'));

        self::assertTrue($params->hasOperations());
    }

    public function testNonFitModeIsOperation(): void
    {
        self::assertTrue((new ResizeParams(0, 0, Mode::Cover, Gravity::Center))->hasOperations());
    }

    public function testModeSuffixForCover(): void
    {
        $params = new ResizeParams(100, 100, Mode::Cover, Gravity::Center);

        self::assertSame('_m-cover', $params->modeSuffix());
    }

    public function testModeSuffixIncludesGravity(): void
    {
        $params = new ResizeParams(100, 100, Mode::Cover, Gravity::TopLeft);

        self::assertSame('_m-cover-g-top-left', $params->modeSuffix());
    }

    public function testModeSuffixForResize(): void
    {
        $params = new ResizeParams(100, 100, Mode::Resize, Gravity::Top);

        self::assertSame('_m-resize', $params->modeSuffix());
    }
}
