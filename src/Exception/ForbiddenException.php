<?php

declare(strict_types=1);

namespace App\Exception;

final class ForbiddenException extends IarException
{
    public function status(): int
    {
        return 403;
    }

    public function publicMessage(): string
    {
        return 'Access denied.';
    }
}
