<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Ответ как объект (без побочных эффектов). Отправка выполняется через
 * SapiInterface, что делает все ветки заголовков юнит-тестируемыми.
 */
final class Response
{
    /** @param list<array{0:string,1:string}> $headers */
    private function __construct(
        public readonly int $status,
        public readonly array $headers = [],
        public readonly ?string $filePath = null,
        public readonly ?string $body = null,
        public readonly bool $headOnly = false,
    ) {
    }

    /** @param list<array{0:string,1:string}> $headers */
    public static function file(string $path, array $headers, bool $headOnly = false): self
    {
        return new self(200, $headers, filePath: $path, headOnly: $headOnly);
    }

    /** @param list<array{0:string,1:string}> $headers */
    public static function redirect(string $url, int $status, array $headers = [], bool $headOnly = false): self
    {
        return new self($status, array_merge($headers, [['Location', $url]]), headOnly: $headOnly);
    }

    /** @param list<array{0:string,1:string}> $headers */
    public static function notModified(array $headers): self
    {
        return new self(304, $headers);
    }

    /** @param list<array{0:string,1:string}> $headers */
    public static function text(int $status, string $body, array $headers = [], bool $headOnly = false): self
    {
        return new self($status, $headers, body: $body, headOnly: $headOnly);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self(
            $this->status,
            array_merge($this->headers, [[$name, $value]]),
            $this->filePath,
            $this->body,
            $this->headOnly,
        );
    }

    public function headerValue(string $name): ?string
    {
        foreach ($this->headers as [$key, $value]) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function headerValues(string $name): array
    {
        $values = [];
        foreach ($this->headers as [$key, $value]) {
            if (strcasecmp($key, $name) === 0) {
                $values[] = $value;
            }
        }

        return $values;
    }

    public function send(SapiInterface $sapi): void
    {
        $sapi->responseCode($this->status);

        foreach ($this->headers as [$name, $value]) {
            $sapi->header($name . ': ' . $value);
        }

        if (!$this->headOnly && $this->status !== 304) {
            if ($this->filePath !== null) {
                $sapi->readFile($this->filePath);
            } elseif ($this->body !== null) {
                $sapi->output($this->body);
            }
        }

        $sapi->terminate();
    }
}
