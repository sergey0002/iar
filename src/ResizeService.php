<?php

namespace App;

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

/**
 * ResizeService — логика ресайза изображений.
 * Использует Intervention Image v3 с GD-драйвером.
 *
 * Fixes applied (audit 2026-03-05):
 *   IMP-04 Нулевые w/h: если оба 0 — ресайз пропускается
 *   IMP-05 SVG fallback при fm != svg: возвращает false вместо copy()
 */
class ResizeService
{
    private array $config;
    private ImageManager $manager;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->manager = new ImageManager(new Driver());
    }

    /**
     * Основной метод обработки изображения.
     */
    public function process(string $sourcePath, string $cachePath, array $params, string $targetExt): bool
    {
        try {
            // SVG — особый путь
            if (strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION)) === 'svg') {
                return $this->processSvg($sourcePath, $cachePath, $params, $targetExt);
            }

            $image = $this->manager->read($sourcePath);

            // 1. Обрезка краёв (ct/cr/cb/cl)
            $this->applyEdgeCrop($image, $params);

            // 2. Ресайз и кадрирование
            $this->applyResizePolicy($image, $params);

            // 3. Сохранение
            $this->saveEncoded($image, $cachePath, $targetExt);

            return true;

        } catch (\Throwable $e) {
            error_log('[ResizeService] Error: ' . $e->getMessage());
            return false;
        }
    }

    // ============================================================
    // Ручная обрезка краёв (пиксели или проценты)
    // ============================================================
    private function applyEdgeCrop($image, array $params): void
    {
        $crop = $params['crop'] ?? null;
        if (!$crop) {
            return;
        }

        $width = $image->width();
        $height = $image->height();

        $value = (float) $crop['value'];
        $isPercent = $crop['unit'] === 'p';

        $x = 0;
        $y = 0;
        $newW = $width;
        $newH = $height;

        switch ($crop['side']) {
            case 'ct': // top
                $amount = $isPercent ? ($height * $value / 100) : $value;
                $y = (int) $amount;
                $newH = (int) ($height - $amount);
                break;
            case 'cr': // right
                $amount = $isPercent ? ($width * $value / 100) : $value;
                $newW = (int) ($width - $amount);
                break;
            case 'cb': // bottom
                $amount = $isPercent ? ($height * $value / 100) : $value;
                $newH = (int) ($height - $amount);
                break;
            case 'cl': // left
                $amount = $isPercent ? ($width * $value / 100) : $value;
                $x = (int) $amount;
                $newW = (int) ($width - $amount);
                break;
        }

        // Защита: не допускаем обрезки более 90% измерения
        $newW = max($newW, (int) ceil($width * 0.1));
        $newH = max($newH, (int) ceil($height * 0.1));

        if ($newW > 0 && $newH > 0) {
            $image->crop($newW, $newH, $x, $y);
        }
    }

    // ============================================================
    // Политика ресайза (fit, cover, resize) + гравитация
    // ============================================================
    private function applyResizePolicy($image, array $params): void
    {
        $w = (int) ($params['w'] ?? 0);
        $h = (int) ($params['h'] ?? 0);
        $mode = $params['mode'] ?? $this->config['default_mode'];
        $gravity = $params['gravity'] ?? $this->config['default_gravity'];

        // Ограничение максимальных размеров
        if ($w > 0 && $w > $this->config['max_width'])
            $w = $this->config['max_width'];
        if ($h > 0 && $h > $this->config['max_height'])
            $h = $this->config['max_height'];

        // IMP-04: Если оба размера равны нулю — ресайз не нужен
        if ($w === 0 && $h === 0) {
            return;
        }

        // Если только одно измерение — передаём null для другого
        $wArg = $w > 0 ? $w : null;
        $hArg = $h > 0 ? $h : null;

        switch ($mode) {
            case 'cover':
                // cover требует оба размера; если одного нет — используем доступное
                $image->cover($wArg ?? $hArg, $hArg ?? $wArg, $gravity);
                break;
            case 'resize':
                $image->resize($wArg, $hArg);
                break;
            case 'fit':
            default:
                // scaleDown: никогда не увеличивает маленькое изображение
                $image->scaleDown($wArg, $hArg);
                break;
        }
    }

    // ============================================================
    // Сохранение в нужный формат с настройками качества
    // ============================================================
    private function saveEncoded($image, string $destPath, string $ext): void
    {
        $lossless = $this->config['lossless'] ?? false;

        switch (strtolower($ext)) {
            case 'png':
                // PNG — всегда без потерь; compression влияет только на размер файла
                $compression = $lossless ? 0 : (int) ($this->config['png_compression'] ?? 9);

                // Умный indexed-режим: включается если ширина <= порога (0 = всегда)
                $pngIndexed = $this->config['png_indexed'] ?? false;
                $pngThreshold = (int) ($this->config['png_indexed_threshold'] ?? 200);
                $useIndexed = $pngIndexed && !$lossless
                    && ($pngThreshold === 0 || $image->width() <= $pngThreshold);

                $image->toPng(
                    indexed: $useIndexed
                )->save($destPath);
                break;

            case 'webp':
                $quality = $lossless ? 100 : (int) ($this->config['webp_quality'] ?? 85);
                // webp_strip_metadata: удаляем EXIF/XMP (экономит 1–5 KB)
                $stripMeta = $this->config['webp_strip_metadata'] ?? true;
                $image->toWebp(quality: $quality, strip: $stripMeta)->save($destPath);
                break;

            case 'avif':
                $quality = $lossless ? 100 : (int) ($this->config['avif_quality'] ?? 75);
                $image->toAvif(quality: $quality)->save($destPath);
                break;

            case 'gif':
                $image->toGif()->save($destPath);
                break;

            case 'jpg':
            case 'jpeg':
            default:
                $quality = $lossless ? 100 : (int) ($this->config['jpeg_quality'] ?? 82);
                // jpeg_progressive: плавная загрузка сверху вниз
                $progressive = $this->config['jpeg_progressive'] ?? false;
                // GD автоматически удаляет EXIF при перекодировании
                $image->toJpeg(
                    quality: $quality,
                    progressive: $progressive
                )->save($destPath);
                break;
        }
    }

    // ============================================================
    // Обработка SVG: растеризация через Imagick или прямая отдача
    // ============================================================
    private function processSvg(string $sourcePath, string $cachePath, array $params, string $targetExt): bool
    {
        // IMP-05: Если нужна растеризация (targetExt != svg), но Imagick недоступен — ошибка
        if ($targetExt !== 'svg') {
            if (class_exists('Imagick')) {
                return $this->svgViaImagick($sourcePath, $cachePath, $params, $targetExt);
            }
            // Нет Imagick, растеризовать нечем
            error_log('[ResizeService] SVG rasterization to ' . $targetExt . ' requested but Imagick not available.');
            return false;
        }

        // Целевой формат — SVG: просто кешируем оригинал (браузер рендерит нативно)
        return copy($sourcePath, $cachePath) !== false;
    }

    private function svgViaImagick(string $sourcePath, string $cachePath, array $params, string $targetExt): bool
    {
        $imagick = new \Imagick();
        $imagick->setBackgroundColor(new \ImagickPixel('transparent'));
        $imagick->readImage($sourcePath);
        $imagick->setImageFormat('png32');

        $w = (int) ($params['w'] ?? 0);
        $h = (int) ($params['h'] ?? 0);
        if ($w > 0 || $h > 0) {
            $imagick->thumbnailImage($w ?: 0, $h ?: 0, /* bestfit */ true, /* fill */ false);
        }

        $tmpPng = tempnam(sys_get_temp_dir(), 'svg_') . '.png';
        $imagick->writeImage($tmpPng);
        $imagick->clear();

        if ($targetExt === 'png') {
            rename($tmpPng, $cachePath);
            return true;
        }

        // Конвертируем через Intervention в нужный формат
        try {
            $image = $this->manager->read($tmpPng);
            $this->saveEncoded($image, $cachePath, $targetExt);
        } finally {
            @unlink($tmpPng);
        }

        return true;
    }
}
