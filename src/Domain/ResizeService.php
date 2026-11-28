<?php

declare(strict_types=1);

namespace App\Domain;

use App\Config;
use App\Exception\IarException;
use App\Exception\InputTooLargeException;
use App\Exception\ProcessingException;
use App\Exception\UnsupportedFormatException;
use App\Infrastructure\ImagickRasterizer;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Обработка изображений (Intervention Image v3, GD).
 *
 * Исправления аудита:
 *   S-02/P-07 — лимит мегапикселей и байтов до декодирования;
 *   B-05      — кроп с корректным ограничением и без выхода за границы;
 *   B-06      — удалена мёртвая опция png_compression;
 *   B-04      — явный switch форматов вместо default → JPEG;
 *   B-16      — Imagick: очистка temp, проверка rename, setDensity;
 *   A-03/O-02 — инъекция ImageManager, типизация ImageInterface.
 */
final class ResizeService implements ResizerInterface
{
    private readonly ImageManager $manager;

    private readonly SvgRasterizer $svgRasterizer;

    public function __construct(
        private readonly Config $config,
        ?ImageManager $manager = null,
        ?SvgRasterizer $svgRasterizer = null,
    ) {
        $this->manager = $manager ?? new ImageManager(new Driver());
        $this->svgRasterizer = $svgRasterizer ?? new SvgRasterizer($this->manager, new ImagickRasterizer());
    }

    public function render(string $sourcePath, ResizeParams $params, Format $target): string
    {
        $sourceFormat = Format::fromName((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
        if ($sourceFormat === null) {
            throw new UnsupportedFormatException('Unknown source format.');
        }

        if ($sourceFormat === Format::Svg) {
            return $this->renderSvg($sourcePath, $params, $target);
        }

        $this->guardInputSize($sourcePath);

        // GD-драйвер читает у WebP только первый кадр, поэтому анимированный
        // WebP обрабатывать нельзя — отдаём оригинал (политика анимации).
        if ($sourceFormat === Format::Webp && self::isAnimatedWebp($sourcePath)) {
            throw new ProcessingException('Animated webp cannot be processed without losing frames.');
        }

        try {
            $image = $this->manager->read($sourcePath);

            // Политика анимации: GIF-кадры Intervention сохраняет (декодер
            // анимации + покадровый энкодер), но при смене формата они теряются.
            // Такую конвертацию не делаем — Application отдаст оригинал.
            if ($target !== $sourceFormat && $image->count() > 1) {
                throw new ProcessingException(sprintf(
                    'Animated %s cannot be converted to %s without losing frames.',
                    $sourceFormat->value,
                    $target->value,
                ));
            }

            $this->applyEdgeCrop($image, $params->crop);
            $this->applyResizePolicy($image, $params);

            return $this->encode($image, $target);
        } catch (IarException $e) {
            // Доменные ошибки (например, запрет SVG-выхода) сохраняют свой HTTP-статус.
            throw $e;
        } catch (\Throwable $e) {
            throw new ProcessingException($e->getMessage(), $e);
        }
    }

    /**
     * Лимит входа до декодирования: GD аллоцирует ~4 байта на пиксель,
     * поэтому 140 Мп файл весом 2 МБ вызывал фатальный OOM.
     */
    private function guardInputSize(string $sourcePath): void
    {
        $bytes = @filesize($sourcePath);
        if ($bytes === false) {
            throw new ProcessingException('Could not stat source image.');
        }
        if ($bytes > $this->config->maxInputBytes) {
            throw new InputTooLargeException('Source file exceeds max_input_bytes.');
        }

        $info = @getimagesize($sourcePath);
        if ($info === false) {
            throw new ProcessingException('Source is not a readable image.');
        }

        $pixels = $info[0] * $info[1];
        if ($pixels > $this->config->maxInputMegapixels * 1000000) {
            throw new InputTooLargeException('Source image exceeds max_input_megapixels.');
        }
    }

    /**
     * Признак анимации WebP: чанк ANMF в заголовке контейнера RIFF.
     * Читается только начало файла и только на генерации (не на cache hit).
     */
    private static function isAnimatedWebp(string $path): bool
    {
        $header = (string) @file_get_contents($path, false, null, 0, 4096);

        return str_contains($header, 'ANMF');
    }

    /**
     * Обрезка края. Область всегда остаётся внутри оригинала —
     * чёрных/белых полос больше нет (аудит B-05).
     */
    private function applyEdgeCrop(ImageInterface $image, ?CropParam $crop): void
    {
        if ($crop === null) {
            return;
        }

        $width = $image->width();
        $height = $image->height();

        $dimension = match ($crop->side) {
            Side::Top, Side::Bottom => $height,
            Side::Left, Side::Right => $width,
        };

        $amount = $crop->unit === Unit::Percent
            ? $dimension * $crop->value / 100
            : $crop->value;

        // Не более (dimension - 1): оставляем минимум 1 пиксель.
        $amount = (int) max(0, min($amount, $dimension - 1));

        $x = 0;
        $y = 0;
        $newWidth = $width;
        $newHeight = $height;

        switch ($crop->side) {
            case Side::Top:
                $y = $amount;
                $newHeight = $height - $amount;
                break;
            case Side::Bottom:
                $newHeight = $height - $amount;
                break;
            case Side::Left:
                $x = $amount;
                $newWidth = $width - $amount;
                break;
            case Side::Right:
                $newWidth = $width - $amount;
                break;
        }

        $image->crop($newWidth, $newHeight, $x, $y);
    }

    private function applyResizePolicy(ImageInterface $image, ResizeParams $params): void
    {
        $w = $params->width;
        $h = $params->height;

        if ($w === 0 && $h === 0) {
            return;
        }

        $wArg = $w > 0 ? $w : null;
        $hArg = $h > 0 ? $h : null;

        switch ($params->mode) {
            case Mode::Cover:
                $image->cover($wArg ?? $hArg, $hArg ?? $wArg, $params->gravity->value);
                break;
            case Mode::Resize:
                $image->resize($wArg, $hArg);
                break;
            case Mode::Fit:
                $image->scaleDown($wArg, $hArg);
                break;
        }
    }

    private function encode(ImageInterface $image, Format $target): string
    {
        $lossless = $this->config->lossless;

        switch ($target) {
            case Format::Png:
                $threshold = $this->config->pngIndexedThreshold;
                $indexed = $this->config->pngIndexed && !$lossless
                    && ($threshold === 0 || $image->width() <= $threshold);

                return $image->toPng(indexed: $indexed)->toString();

            case Format::Webp:
                return $image->toWebp(
                    quality: $lossless ? 100 : $this->config->webpQuality,
                    strip: $this->config->webpStripMetadata,
                )->toString();

            case Format::Avif:
                if (!Format::Avif->encoderAvailable()) {
                    throw new ProcessingException('AVIF encoder is not available in this PHP build.');
                }

                // @codeCoverageIgnoreStart — исполняется только при GD с AVIF;
                // проверяется джобом CI с поддержкой AVIF (IAR_STRICT_COVERAGE=1).
                return $image->toAvif(quality: $lossless ? 100 : $this->config->avifQuality)->toString();
                // @codeCoverageIgnoreStop

            case Format::Gif:
                return $image->toGif()->toString();

            case Format::Jpg:
            case Format::Jpeg:
                return $image->toJpeg(
                    quality: $lossless ? 100 : $this->config->jpegQuality,
                    progressive: $this->config->jpegProgressive,
                )->toString();

            case Format::Svg:
                throw new UnsupportedFormatException('Raster image cannot be encoded as SVG.');
        }
    }

    private function renderSvg(string $sourcePath, ResizeParams $params, Format $target): string
    {
        if ($target === Format::Svg) {
            $data = @file_get_contents($sourcePath);
            if ($data === false) {
                throw new ProcessingException('Could not read SVG source.');
            }

            return $data;
        }

        return $this->encode($this->svgRasterizer->rasterize($sourcePath, $params), $target);
    }
}
