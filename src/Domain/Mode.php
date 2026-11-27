<?php

declare(strict_types=1);

namespace App\Domain;

enum Mode: string
{
    case Fit = 'fit';
    case Cover = 'cover';
    case Resize = 'resize';

    public static function fromName(string $name): ?self
    {
        return self::tryFrom(strtolower(trim($name)));
    }
}
