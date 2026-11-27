<?php

declare(strict_types=1);

namespace App\Domain;

enum Unit: string
{
    case Pixels = 'px';
    case Percent = 'p';
}
