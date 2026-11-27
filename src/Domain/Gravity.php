<?php

declare(strict_types=1);

namespace App\Domain;

enum Gravity: string
{
    case Center = 'center';
    case Top = 'top';
    case Bottom = 'bottom';
    case Left = 'left';
    case Right = 'right';
    case TopLeft = 'top-left';
    case TopRight = 'top-right';
    case BottomLeft = 'bottom-left';
    case BottomRight = 'bottom-right';

    public static function fromName(string $name): ?self
    {
        return self::tryFrom(strtolower(trim($name)));
    }
}
