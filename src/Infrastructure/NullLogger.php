<?php

declare(strict_types=1);

namespace App\Infrastructure;

final class NullLogger implements LoggerInterface
{
    public function log(string $event, array $context): void
    {
        // Логирование выключено — намеренно ничего не делаем.
    }
}
