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

    /**
     * Нужно ли вообще собирать события запроса (hit/miss).
     *
     * Горячий путь обязан не делать лишней работы: при выключенном логе
     * Application не вызывает filesize() ради метрик, которые никто
     * не запишет. Ошибки (fail) логируются независимо от этого флага.
     */
    public function logsRequests(): bool;
}
