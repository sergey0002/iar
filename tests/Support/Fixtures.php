<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Генератор тестовых изображений. Идемпотентен, работает на чистом GD,
 * поэтому набор воспроизводится и локально, и в CI без внешних файлов.
 */
final class Fixtures
{
    private const ANIM_GIF = 'R0lGODlhCAAGAIEAAP8AAAAAAAAAAAAAACH/C05FVFNDQVBFMi4wAwEAAAAh+QQACgAAACwAAAAACAAGAAAIDgABCBxIsKDBgwgTDgwIACH5BAEKAAEALAAAAAAIAAYAgQD/AAAAAAAAAAAAAAgOAAEIHEiwoMGDCBMODAgAOw==';

    private const EXIF_JPEG = '/9j/4AAQSkZJRgABAQAAAQABAAD/4QAiRXhpZgAATU0AKgAAAAgAAQESAAMAAAABAAYAAAAAAAD/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoHBwYIDAoMDAsKCwsNDhIQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/2wBDAQMEBAUEBQkFBQkUDQsNFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBT/wAARCAAKABQDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD7a+LX/JKvGf8A2Bb3/wBEPX5oV+l/xa/5JV4z/wCwLe/+iHr80K+C+i5/yJsy/wCvsf8A0g/O/Ej/AHvD/wCF/mFFFFf20fjx/9k=';

    /** Создаёт «корень сайта» с набором тестовых изображений. */
    public static function ensure(string $dir): void
    {
        self::mkdir($dir);

        self::gradient($dir . '/photo.jpg', 1200, 800);
        self::alphaPng($dir . '/small.png');
        self::truecolorPng($dir . '/wide.png');

        file_put_contents($dir . '/anim.gif', base64_decode(self::ANIM_GIF, true));
        // Статический GIF: один кадр, палитра создана сразу (без конвертации).
        $static = imagecreate(8, 6);
        imagecolorallocate($static, 10, 120, 200);
        imagegif($static, $dir . '/static.gif');
        imagedestroy($static);
        file_put_contents($dir . '/exif.jpg', base64_decode(self::EXIF_JPEG, true));
        file_put_contents($dir . '/corrupt.jpg', '<?php echo "not an image"; ?>');
        file_put_contents($dir . '/secret.txt', 'do not serve me');
        file_put_contents(
            $dir . '/icon.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100">'
            . '<script>alert(document.domain)</script>'
            . '<circle cx="50" cy="50" r="40" fill="red"/></svg>',
        );

        foreach (['photo copy.jpg', 'фотка.jpg', 'a&b.jpg', 'br[1].jpg'] as $name) {
            copy($dir . '/photo.jpg', $dir . '/' . $name);
        }

        copy($dir . '/small.png', $dir . '/private.png');

        // Другое изображение с тем же mtime — воспроизводит коллизию кеш-ключа (B-03).
        self::mkdir($dir . '/nested/www');
        self::solid($dir . '/nested/www/photo.jpg', 1200, 800, 255, 0, 0);
        touch($dir . '/nested/www/photo.jpg', (int) filemtime($dir . '/photo.jpg'));

        // Синтетический JPEG: заголовок объявляет 14000x10000 (140 Мп),
        // но файл крошечный — тест лимита входа не требует памяти.
        file_put_contents($dir . '/huge.jpg', self::jpegHeader(14000, 10000));

        clearstatcache();
    }

    private static function mkdir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    private static function gradient(string $path, int $w, int $h): void
    {
        $im = imagecreatetruecolor($w, $h);
        for ($x = 0; $x < $w; $x++) {
            $color = imagecolorallocate($im, (int) (255 * $x / $w), 100, 180);
            imageline($im, $x, 0, $x, $h - 1, $color);
        }
        imagejpeg($im, $path, 90);
        imagedestroy($im);
    }

    private static function solid(string $path, int $w, int $h, int $r, int $g, int $b): void
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
        imagejpeg($im, $path, 90);
        imagedestroy($im);
    }

    private static function alphaPng(string $path): void
    {
        $im = imagecreatetruecolor(100, 100);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledrectangle($im, 10, 10, 60, 60, imagecolorallocate($im, 255, 0, 0));
        imagepng($im, $path);
        imagedestroy($im);
    }

    private static function truecolorPng(string $path): void
    {
        $im = imagecreatetruecolor(400, 300);
        for ($x = 0; $x < 400; $x++) {
            for ($y = 0; $y < 300; $y++) {
                imagesetpixel($im, $x, $y, imagecolorallocate($im, $x % 256, $y % 256, ($x * $y) % 256));
            }
        }
        imagepng($im, $path);
        imagedestroy($im);
    }

    /** Минимальный JPEG-заголовок с заявленными размерами (для теста лимита). */
    private static function jpegHeader(int $width, int $height): string
    {
        $sof = "\xFF\xC0" . pack('n', 17) . "\x08" . pack('n', $height) . pack('n', $width) . "\x03";
        $sof .= "\x01\x11\x00\x02\x11\x01\x03\x11\x01";

        return "\xFF\xD8" . $sof . "\xFF\xD9";
    }
}
