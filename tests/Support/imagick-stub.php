<?php

declare(strict_types=1);

/**
 * Заглушка ext-imagick для юнит-тестов.
 *
 * Подключается ТОЛЬКО если расширение отсутствует, поэтому в CI с
 * реальным Imagick те же тесты проверяют настоящий адаптер.
 * Заглука повторяет используемый контракт и рисует PNG средствами GD,
 * что позволяет проверить последовательность вызовов и обработку ошибок.
 */

if (class_exists(Imagick::class, false) || class_exists(ImagickPixel::class, false)) {
    return;
}

final class ImagickPixel
{
    public function __construct(public readonly string $color = 'transparent')
    {
    }
}

final class Imagick
{
    public static ?string $lastFile = null;

    public static ?int $lastDensity = null;

    public static ?array $lastThumbnail = null;

    public static bool $cleared = false;

    public static bool $failRead = false;

    public static bool $emptyBlob = false;

    private ?string $file = null;

    private int $density = 72;

    private int $width = 100;

    private int $height = 100;

    public static function reset(): void
    {
        self::$lastFile = null;
        self::$lastDensity = null;
        self::$lastThumbnail = null;
        self::$cleared = false;
        self::$failRead = false;
        self::$emptyBlob = false;
    }

    public function setBackgroundColor(ImagickPixel $pixel): bool
    {
        return true;
    }

    public function setResolution(int $x, int $y): bool
    {
        $this->density = $x;
        self::$lastDensity = $x;

        return true;
    }

    public function readImage(string $path): bool
    {
        if (self::$failRead || !is_file($path)) {
            throw new Exception('unable to open image ' . $path);
        }

        $this->file = $path;
        self::$lastFile = $path;
        // Плотность влияет на размер «вектора» — как у настоящего Imagick.
        $this->width = (int) max(1, round($this->density * 1.4));
        $this->height = (int) max(1, round($this->density * 1.4));

        return true;
    }

    public function setImageFormat(string $format): bool
    {
        return true;
    }

    public function thumbnailImage(int $width, int $height, bool $bestfit = false, bool $fill = false): bool
    {
        self::$lastThumbnail = [$width, $height, $bestfit, $fill];

        if ($bestfit) {
            $scale = min(
                $width > 0 ? $width / $this->width : PHP_FLOAT_MAX,
                $height > 0 ? $height / $this->height : PHP_FLOAT_MAX,
            );
            $this->width = (int) max(1, round($this->width * $scale));
            $this->height = (int) max(1, round($this->height * $scale));
        } else {
            $this->width = max(1, $width);
            $this->height = max(1, $height);
        }

        return true;
    }

    public function getImageBlob(): string
    {
        if (self::$emptyBlob) {
            return '';
        }

        $im = imagecreatetruecolor($this->width, $this->height);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 200, 30, 30, 0));
        ob_start();
        imagepng($im);
        $data = (string) ob_get_clean();
        imagedestroy($im);

        return $data;
    }

    public function clear(): bool
    {
        self::$cleared = true;
        $this->file = null;

        return true;
    }
}
