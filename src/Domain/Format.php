<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Форматы изображений. Единый источник правды для MIME, допустимости
 * в качестве выходного формата и поддержки анимации (аудит A-02, B-04).
 */
enum Format: string
{
    case Jpg = 'jpg';
    case Jpeg = 'jpeg';
    case Png = 'png';
    case Gif = 'gif';
    case Webp = 'webp';
    case Avif = 'avif';
    case Svg = 'svg';

    public static function fromName(string $name): ?self
    {
        return self::tryFrom(strtolower(trim($name)));
    }

    public function mime(): string
    {
        return match ($this) {
            self::Jpg, self::Jpeg => 'image/jpeg',
            self::Png => 'image/png',
            self::Gif => 'image/gif',
            self::Webp => 'image/webp',
            self::Avif => 'image/avif',
            self::Svg => 'image/svg+xml',
        };
    }

    public function isRaster(): bool
    {
        return $this !== self::Svg;
    }

    /** Может ли формат содержать анимацию. */
    public function supportsAnimation(): bool
    {
        return $this === self::Gif || $this === self::Webp;
    }

    /** Допустим ли формат как явный выход (fm) для растрового оригинала. */
    public function isAllowedRasterOutput(): bool
    {
        return $this !== self::Svg;
    }

    /** Нормализованное расширение для кеш-файла (jpeg → jpg). */
    public function cacheExtension(): string
    {
        return $this === self::Jpeg ? self::Jpg->value : $this->value;
    }

    /** Доступен ли энкодер в текущем окружении. */
    public function encoderAvailable(): bool
    {
        return match ($this) {
            self::Webp => function_exists('imagewebp'),
            self::Avif => function_exists('imageavif'),
            default => true,
        };
    }
}
