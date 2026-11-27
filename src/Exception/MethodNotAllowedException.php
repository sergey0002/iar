<?php

declare(strict_types=1);

namespace App\Exception;

final class MethodNotAllowedException extends IarException
{
    public function status(): int
    {
        return 405;
    }

    public function publicMessage(): string
    {
        return 'Method not allowed.';
    }
}
