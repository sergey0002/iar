<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Format;

/**
 * Ответ с оригиналом — общий для аварийного пути Bootstrap и для fallback
 * Application. Отдаётся без обработки, без кеширования, с теми же заголовками
 * безопасности, что и обычный ответ.
 *
 * Используется, когда вариант получить не удалось, а картинка исчезнуть
 * не должна (политика надёжности), в том числе когда реальный путь скрыт
 * псевдонимом и редиректить браузер некуда.
 */
final class OriginalResponse
{
    public static function make(string $path, bool $headOnly = false): Response
    {
        $format = Format::fromName((string) pathinfo($path, PATHINFO_EXTENSION));
        $headers = [
            ['Content-Type', $format === null ? 'application/octet-stream' : $format->mime()],
            ['Cache-Control', 'no-store'],
            ['X-Content-Type-Options', 'nosniff'],
            ['X-Iar-Fallback', 'original'],
        ];

        if ($format === Format::Svg) {
            $headers[] = ['Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox"];
        }

        $size = @filesize($path);
        if ($size !== false) {
            $headers[] = ['Content-Length', (string) $size];
        }

        return Response::file($path, $headers, $headOnly);
    }
}
