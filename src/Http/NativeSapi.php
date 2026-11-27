<?php

declare(strict_types=1);

namespace App\Http;

final class NativeSapi implements SapiInterface
{
    public function header(string $header, bool $replace = true): void
    {
        if ($this->headersSent()) {
            return;
        }

        header($header, $replace);
    }

    public function responseCode(int $code): void
    {
        http_response_code($code);
    }

    public function readFile(string $path): void
    {
        readfile($path);
    }

    public function output(string $data): void
    {
        echo $data;
    }

    public function terminate(): void
    {
        exit;
    }

    public function headersSent(): bool
    {
        return headers_sent();
    }
}
