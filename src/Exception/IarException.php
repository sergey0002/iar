<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Базовое исключение IAR. Все доменные ошибки наследуются от него,
 * что позволяет централизованно маппить их в HTTP-ответы.
 */
class IarException extends \RuntimeException
{
    public function status(): int
    {
        return 500;
    }

    /** Публичное (нейтральное) сообщение для клиента. */
    public function publicMessage(): string
    {
        return 'Internal error.';
    }
}
