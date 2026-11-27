<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Infrastructure\RasterizerInterface;

/** Подменный адаптер растеризации: проверяем логику без Imagick. */
final class FakeRasterizer implements RasterizerInterface
{
    public ?int $density = null;

    public ?int $width = null;

    public ?int $height = null;

    public ?string $path = null;

    public int $calls = 0;

    public function __construct(
        public bool $available = true,
        public string $bytes = 'PNG-BYTES',
        public ?\Throwable $failure = null,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function toPng(string $svgPath, int $density, int $width, int $height): string
    {
        $this->calls++;
        $this->path = $svgPath;
        $this->density = $density;
        $this->width = $width;
        $this->height = $height;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->bytes;
    }

    /** Реальный PNG средствами GD — чтобы ImageManager смог его прочитать. */
    public static function pngBytes(int $size = 20): string
    {
        $im = imagecreatetruecolor($size, $size);
        imagefill($im, 0, 0, imagecolorallocate($im, 10, 20, 30));
        ob_start();
        imagepng($im);
        $data = (string) ob_get_clean();
        imagedestroy($im);

        return $data;
    }
}
