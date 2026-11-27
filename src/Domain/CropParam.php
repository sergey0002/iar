<?php

declare(strict_types=1);

namespace App\Domain;

use App\Exception\BadRequestException;

/**
 * Параметр обрезки края. Валидируется при создании: отрицательные
 * значения и проценты > 100 отклоняются (аудит B-05).
 */
final class CropParam
{
    private function __construct(
        public readonly Side $side,
        public readonly float $value,
        public readonly Unit $unit,
    ) {
    }

    /**
     * @param string $raw сырое значение параметра (например "50", "15p", "20%")
     */
    public static function parse(Side $side, string $raw): ?self
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $isPercent = str_ends_with($raw, 'p') || str_ends_with($raw, '%');
        $number = rtrim($raw, 'p%');

        if ($number === '' || !is_numeric($number)) {
            throw new BadRequestException('Crop value must be numeric.');
        }

        $value = (float) $number;

        if ($value < 0) {
            throw new BadRequestException('Crop value must not be negative.');
        }

        if ($isPercent && $value > 100) {
            throw new BadRequestException('Crop percent must not exceed 100.');
        }

        // Верхняя граница для пикселей — защита от абсурдных значений;
        // фактическое ограничение по размеру изображения применяется в ResizeService.
        if (!$isPercent && $value > 100000) {
            throw new BadRequestException('Crop value is too large.');
        }

        return new self($side, $value, $isPercent ? Unit::Percent : Unit::Pixels);
    }

    /** Суффикс для кеш-ключа. */
    public function suffix(): string
    {
        $num = rtrim(rtrim(number_format($this->value, 4, '.', ''), '0'), '.');

        return '_' . $this->side->value . $num . ($this->unit === Unit::Percent ? 'p' : '');
    }
}
