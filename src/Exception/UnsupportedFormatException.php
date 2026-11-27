<?php

declare(strict_types=1);

namespace App\Exception;

final class UnsupportedFormatException extends IarException
{
    public function status(): int
    {
        return 400;
    }

    public function publicMessage(): string
    {
        return 'Unsupported output format.';
    }
}
