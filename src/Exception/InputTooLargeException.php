<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Входное изображение превышает лимиты (мегапиксели/байты).
 * Проверка выполняется ДО декодирования, чтобы не допустить OOM
 * (аудит S-02, P-07, E-05).
 */
final class InputTooLargeException extends IarException
{
    public function status(): int
    {
        return 413;
    }

    public function publicMessage(): string
    {
        return 'Source image is too large.';
    }
}
