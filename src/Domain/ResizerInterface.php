<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Обработчик изображений. Возвращает закодированные байты, а не пишет
 * файл сам — персистентность отвечает CacheStore (атомарная запись).
 */
interface ResizerInterface
{
    public function render(string $sourcePath, ResizeParams $params, Format $target): string;
}
