<?php

declare(strict_types=1);

namespace App\Infrastructure;

/**
 * Тонкий адаптер к ext-imagick.
 *
 * Исправление аудита B-16: временные файлы больше не создаются вовсе —
 * PNG забирается из памяти (getImageBlob), поэтому терять нечего.
 * Ресурс освобождается в finally при любом исходе.
 *
 * В юнит-тестах вызовы проверяются на заглушке Imagick
 * (tests/Support/imagick-stub.php), в CI с расширением — на реальном.
 */
final class ImagickRasterizer implements RasterizerInterface
{
    public function isAvailable(): bool
    {
        return class_exists(\Imagick::class);
    }

    public function toPng(string $svgPath, int $density, int $width, int $height): string
    {
        $imagick = new \Imagick();

        try {
            $imagick->setBackgroundColor(new \ImagickPixel('transparent'));
            $imagick->setResolution($density, $density);
            $imagick->readImage($svgPath);
            $imagick->setImageFormat('png32');

            if ($width > 0 || $height > 0) {
                $imagick->thumbnailImage($width, $height, true, false);
            }

            return $imagick->getImageBlob();
        } finally {
            $imagick->clear();
        }
    }
}
