<?php

declare(strict_types=1);

namespace App\Domain;

use App\Config;
use App\Exception\UnsupportedFormatException;

/**
 * Выбор целевого формата (аудит B-04, B-07, B-13).
 *
 * Правила:
 *   - явный fm имеет приоритет; fm=svg для растра запрещён;
 *   - SVG-оригинал не конвертируется автоматически;
 *   - GIF-оригинал не конвертируется автоматически, чтобы не потерять
 *     анимацию (GD энкодер WebP/AVIF отдаёт только первый кадр);
 *   - авто-выбор AVIF/WebP только при поддержке энкодера и Accept.
 */
final class FormatNegotiator
{
    /** @param (callable(Format):bool)|null $encoderProbe */
    public function __construct(
        private readonly Config $config,
        private readonly mixed $encoderProbe = null,
    ) {
    }

    public function negotiate(Format $source, ?Format $explicit, string $accept): Format
    {
        if ($explicit !== null) {
            if (!$source->isRaster() && $explicit !== Format::Svg) {
                // Растеризация SVG требует Imagick; допустима, проверяется в сервисе.
                return $explicit;
            }
            if ($source->isRaster() && !$explicit->isAllowedRasterOutput()) {
                throw new UnsupportedFormatException('SVG is not a valid output format for raster sources.');
            }

            return $explicit;
        }

        if (!$source->isRaster()) {
            return Format::Svg;
        }

        // Анимация: GIF оставляем как есть (аудит B-07).
        if ($source === Format::Gif) {
            return Format::Gif;
        }

        if (!$this->config->autoFormat) {
            return $source;
        }

        if ($this->canUse(Format::Avif, $accept, 'image/avif')) {
            return Format::Avif;
        }

        if ($this->canUse(Format::Webp, $accept, 'image/webp')) {
            return Format::Webp;
        }

        return $source;
    }

    /**
     * Нужен ли Vary: Accept (аудит H-03).
     *
     * Для GIF ответ не зависит от Accept (формат сохраняется всегда),
     * поэтому Vary не отправляется — иначе CDN плодил бы лишние варианты.
     */
    public function variesOnAccept(Format $source, ?Format $explicit): bool
    {
        return $this->config->autoFormat
            && $explicit === null
            && $source->isRaster()
            && $source !== Format::Gif;
    }

    private function canUse(Format $format, string $accept, string $mime): bool
    {
        if (!str_contains($accept, $mime) || !$this->config->allowsFormat($format)) {
            return false;
        }

        // Пробник подменяется в тестах: ветки AVIF проверяются и там,
        // где расширение не собрано.
        return $this->encoderProbe === null
            ? $format->encoderAvailable()
            : (bool) ($this->encoderProbe)($format);
    }
}
