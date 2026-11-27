<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Gravity;
use App\Domain\Mode;
use App\Domain\ResizeParams;
use App\Domain\SvgRasterizer;
use App\Exception\ProcessingException;
use App\Tests\Support\Factory;
use App\Tests\Support\FakeRasterizer;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SvgRasterizerTest extends TestCase
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

    private function params(int $w = 0, int $h = 0): ResizeParams
    {
        return new ResizeParams($w, $h, Mode::Fit, Gravity::Center, null);
    }

    private function rasterizer(FakeRasterizer $adapter): SvgRasterizer
    {
        return new SvgRasterizer(new ImageManager(new Driver()), $adapter);
    }

    public static function densities(): array
    {
        return [
            'no size' => [0, 0, 72],
            'small' => [40, 0, 72],
            'medium' => [400, 0, 200],
            'height only' => [0, 600, 300],
            'clamped to max' => [5000, 5000, 600],
        ];
    }

    /** Аудит P-08: плотность зависит от целевого размера и ограничена сверху. */
    #[DataProvider('densities')]
    public function testDensity(int $w, int $h, int $expected): void
    {
        self::assertSame($expected, SvgRasterizer::density($this->params($w, $h)));
    }

    public function testUnavailableAdapterProducesClearError(): void
    {
        $this->expectException(ProcessingException::class);
        $this->expectExceptionMessage('Imagick');
        $this->rasterizer(new FakeRasterizer(available: false))
            ->rasterize($this->site . '/icon.svg', $this->params(50));
    }

    public function testSuccessfulRasterizationReturnsImage(): void
    {
        $adapter = new FakeRasterizer(bytes: FakeRasterizer::pngBytes(30));
        $image = $this->rasterizer($adapter)->rasterize($this->site . '/icon.svg', $this->params(60, 40));

        self::assertSame(30, $image->width());
        self::assertSame(1, $adapter->calls);
        self::assertSame(72, $adapter->density);
        self::assertSame(60, $adapter->width);
        self::assertSame(40, $adapter->height);
        self::assertSame($this->site . '/icon.svg', $adapter->path);
    }

    public function testAdapterFailureIsWrapped(): void
    {
        $adapter = new FakeRasterizer(failure: new \RuntimeException('bad vector'));

        $this->expectException(ProcessingException::class);
        $this->expectExceptionMessage('SVG rasterization failed: bad vector');
        $this->rasterizer($adapter)->rasterize($this->site . '/icon.svg', $this->params());
    }

    public function testDomainExceptionFromAdapterIsPreserved(): void
    {
        $adapter = new FakeRasterizer(failure: new ProcessingException('custom reason'));

        try {
            $this->rasterizer($adapter)->rasterize($this->site . '/icon.svg', $this->params());
            self::fail('Expected ProcessingException');
        } catch (ProcessingException $e) {
            self::assertSame('custom reason', $e->reason());
        }
    }

    public function testEmptyResultIsRejected(): void
    {
        $this->expectException(ProcessingException::class);
        $this->expectExceptionMessage('produced no data');
        $this->rasterizer(new FakeRasterizer(bytes: ''))
            ->rasterize($this->site . '/icon.svg', $this->params());
    }

    public function testUndecodableResultIsRejected(): void
    {
        $this->expectException(ProcessingException::class);
        $this->expectExceptionMessage('could not be decoded');
        $this->rasterizer(new FakeRasterizer(bytes: 'not-a-png'))
            ->rasterize($this->site . '/icon.svg', $this->params());
    }
}
