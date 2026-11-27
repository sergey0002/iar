<?php

declare(strict_types=1);

namespace App\Exception;

final class NotFoundException extends IarException
{
    public function status(): int
    {
        return 404;
    }

    public function publicMessage(): string
    {
        return 'File not found.';
    }
}
