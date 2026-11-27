<?php

declare(strict_types=1);

namespace App\Domain;

use App\Exception\IarException;
use App\Exception\ProcessingException;
use App\Infrastructure\RasterizerInterface;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Растеризация SVG: выбор плотности, проверка результата, единые ошибки.
 *
 * Плотность зависит от целевого размера — иначе вектор «мылится»
 * (аудит P-08). Логика не зависит от расширения и покрыта тестами.
 */
final class SvgRasterizer
{
    public const MIN_DENSITY = 72;

    public const MAX_DENSITY = 600;

    public function __construct(
        private readonly ImageManager $manager,
        private readonly RasterizerInterface $adapter,
    ) {
    }

    public function rasterize(string $svgPath, ResizeParams $params): ImageInterface
    {
        if (!$this->adapter->isAvailable()) {
            throw new ProcessingException('SVG rasterization requires the Imagick extension.');
        }

        try {
            $png = $this->adapter->toPng($svgPath, self::density($params), $params->width, $params->height);
        } catch (IarException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ProcessingException('SVG rasterization failed: ' . $e->getMessage(), $e);
        }

        if ($png === '') {
            throw new ProcessingException('SVG rasterization produced no data.');
        }

        try {
            return $this->manager->read($png);
        } catch (\Throwable $e) {
            throw new ProcessingException('Rasterized SVG could not be decoded: ' . $e->getMessage(), $e);
        }
    }

    public static function density(ResizeParams $params): int
    {
        if ($params->width <= 0 && $params->height <= 0) {
            return self::MIN_DENSITY;
        }

        return (int) min(self::MAX_DENSITY, max(self::MIN_DENSITY, max($params->width, $params->height) / 2));
    }
}
