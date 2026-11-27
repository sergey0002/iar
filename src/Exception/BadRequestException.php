<?php

declare(strict_types=1);

namespace App\Exception;

final class BadRequestException extends IarException
{
    public function status(): int
    {
        return 400;
    }

    public function publicMessage(): string
    {
        return 'Bad request.';
    }
}
