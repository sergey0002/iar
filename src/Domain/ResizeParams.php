<?php

declare(strict_types=1);

namespace App\Domain;

/** Параметры обработки изображения (DTO вместо неструктурированного массива). */
final class ResizeParams
{
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly Mode $mode,
        public readonly Gravity $gravity,
        public readonly ?CropParam $crop = null,
    ) {
    }

    /** Есть ли операции, изменяющие изображение. */
    public function hasOperations(): bool
    {
        return $this->width > 0 || $this->height > 0 || $this->crop !== null
            || $this->mode !== Mode::Fit;
    }

    public function modeSuffix(): string
    {
        if ($this->mode === Mode::Fit) {
            return '';
        }

        $suffix = '_m-' . $this->mode->value;
        if ($this->mode === Mode::Cover && $this->gravity !== Gravity::Center) {
            $suffix .= '-g-' . $this->gravity->value;
        }

        return $suffix;
    }
}
