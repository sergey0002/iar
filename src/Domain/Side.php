<?php

declare(strict_types=1);

namespace App\Domain;

enum Side: string
{
    case Top = 'ct';
    case Right = 'cr';
    case Bottom = 'cb';
    case Left = 'cl';

    /** Приоритет разбора параметров (аудит: ct → cr → cb → cl). */
    public static function priority(): array
    {
        return [self::Top, self::Right, self::Bottom, self::Left];
    }
}
