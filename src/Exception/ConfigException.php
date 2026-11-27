<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Ошибка конфигурации. Всегда fail-fast: некорректный конфиг не должен
 * приводить к «тихой» деградации защиты (см. аудит S-03).
 */
final class ConfigException extends IarException
{
    public function status(): int
    {
        return 500;
    }

    public function publicMessage(): string
    {
        return 'Configuration error.';
    }
}
