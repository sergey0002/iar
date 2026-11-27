<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Ошибка обработки изображения. Несёт причину (reason), которая
 * логируется и позволяет корректно выполнить fallback на оригинал
 * (см. аудит B-01: неопределённая $reason ломала редирект).
 */
final class ProcessingException extends IarException
{
    public function __construct(private readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function status(): int
    {
        return 500;
    }

    public function publicMessage(): string
    {
        return 'Image processing failed.';
    }
}
