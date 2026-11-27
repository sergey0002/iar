<?php

declare(strict_types=1);

namespace App\Infrastructure;

/**
 * Адаптер растеризации SVG. Выделен в интерфейс, чтобы логика выбора
 * плотности и обработки ошибок тестировалась без расширения Imagick.
 */
interface RasterizerInterface
{
    public function isAvailable(): bool;

    /**
     * Растеризует SVG в PNG-байты.
     *
     * @param int $density DPI чтения вектора
     * @param int $width   целевая ширина (0 — не задана)
     * @param int $height  целевая высота (0 — не задана)
     */
    public function toPng(string $svgPath, int $density, int $width, int $height): string;
}
