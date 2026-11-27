<?php

declare(strict_types=1);

namespace App\Infrastructure;

/**
 * Лог только для отладки (концепция продукта). По умолчанию
 * используется NullLogger — нулевые накладные расходы на горячем пути.
 */
interface LoggerInterface
{
    /** @param array<string,mixed> $context */
    public function log(string $event, array $context): void;
}
