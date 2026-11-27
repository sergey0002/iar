<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Абстракция серверного API. Позволяет тестировать весь HTTP-код
 * (заголовки, статусы, редиректы, отдачу файла) без реального SAPI —
 * ключ к 100% покрытию (аудит A-01, O-01).
 */
interface SapiInterface
{
    public function header(string $header, bool $replace = true): void;

    public function responseCode(int $code): void;

    public function readFile(string $path): void;

    public function output(string $data): void;

    /** Завершает запрос. В тестах бросает ExitCalled вместо exit. */
    public function terminate(): void;

    public function headersSent(): bool;
}
