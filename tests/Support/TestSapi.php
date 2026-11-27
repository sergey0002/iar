<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Http\SapiInterface;

/**
 * Перехватывает весь HTTP-вывод: статус, заголовки, тело, факт завершения.
 * Позволяет тестировать HTTP-поведение без реального SAPI.
 */
final class TestSapi implements SapiInterface
{
    public int $status = 200;

    /** @var list<string> */
    public array $headers = [];

    public string $body = '';

    public ?string $fileRead = null;

    public bool $terminated = false;

    public bool $forceHeadersSent = false;

    public function header(string $header, bool $replace = true): void
    {
        if ($replace) {
            $name = strtolower(trim(explode(':', $header, 2)[0]));
            $this->headers = array_values(array_filter(
                $this->headers,
                static fn (string $h): bool => strtolower(trim(explode(':', $h, 2)[0])) !== $name,
            ));
        }

        $this->headers[] = $header;
    }

    public function responseCode(int $code): void
    {
        $this->status = $code;
    }

    public function readFile(string $path): void
    {
        $this->fileRead = $path;
        $data = @file_get_contents($path);
        $this->body .= $data === false ? '' : $data;
    }

    public function output(string $data): void
    {
        $this->body .= $data;
    }

    public function terminate(): void
    {
        $this->terminated = true;

        throw new ExitCalled('terminate');
    }

    public function headersSent(): bool
    {
        return $this->forceHeadersSent || $this->headers !== [] || $this->body !== '';
    }

    public function headerValue(string $name): ?string
    {
        foreach ($this->headers as $header) {
            $parts = explode(':', $header, 2);
            if (strcasecmp(trim($parts[0]), $name) === 0) {
                return trim($parts[1] ?? '');
            }
        }

        return null;
    }

    /** @return list<string> */
    public function headerValues(string $name): array
    {
        $values = [];
        foreach ($this->headers as $header) {
            $parts = explode(':', $header, 2);
            if (strcasecmp(trim($parts[0]), $name) === 0) {
                $values[] = trim($parts[1] ?? '');
            }
        }

        return $values;
    }
}
