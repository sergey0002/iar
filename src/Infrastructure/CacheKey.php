<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Domain\ResizeParams;

/**
 * Построение кеш-ключа.
 *
 * Исправления аудита:
 *   B-03 — относительный путь вычисляется срезом префикса (substr),
 *          а не str_replace, который вырезал все вхождения и давал коллизии;
 *   B-19 — используется полный md5 (32 символа) вместо 10.
 */
final class CacheKey
{
    public static function build(
        string $filePath,
        string $baseDir,
        ResizeParams $params,
        int $mtime,
        string $qualityFingerprint = '',
    ): string {
        $relative = self::relativePath($filePath, $baseDir);
        $hash = md5($relative);
        $filename = pathinfo($filePath, PATHINFO_FILENAME);

        return $filename
            . '__' . $params->width . 'x' . $params->height
            . ($params->crop !== null ? $params->crop->suffix() : '')
            . $params->modeSuffix()
            . '__' . $hash
            . '_' . $mtime
            . $qualityFingerprint;
    }

    /** Префикс имени файла для поиска вариантов кеша. */
    public static function filenamePrefix(string $filePath): string
    {
        return pathinfo($filePath, PATHINFO_FILENAME) . '__';
    }

    /** Маркер хеша исходного пути внутри имени кеш-файла. */
    public static function hashMarker(string $filePath, string $baseDir): string
    {
        return '__' . md5(self::relativePath($filePath, $baseDir)) . '_';
    }

    /** Нормализованный относительный путь (для стабильности хеша). */
    public static function relativePath(string $filePath, string $baseDir): string
    {
        $relative = str_starts_with($filePath, $baseDir)
            ? substr($filePath, strlen($baseDir))
            : $filePath;

        return str_replace('\\', '/', $relative);
    }
}
