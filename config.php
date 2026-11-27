<?php

declare(strict_types=1);

/**
 * Конфигурация IAR. Единственная точка настройки.
 *
 * Концепция продукта:
 *   - портабельность: папка кладётся в корень любого сайта;
 *   - кеш полностью удаляемый, пересоздаётся при первом обращении по URL;
 *   - лог только для отладки, по умолчанию ВЫКЛЮЧЕН, ротация не нужна.
 *
 * Значения валидируются в App\Config::fromArray() — некорректный конфиг
 * приводит к явной ошибке, а не к тихой деградации защиты.
 */

return [
    // === Качество и размеры ===
    'max_width' => 2000,            // px, предел размера результата
    'max_height' => 2000,           // px
    'jpeg_quality' => 82,           // 1–100
    'webp_quality' => 85,           // 1–100
    'avif_quality' => 75,           // 1–100

    // === Защита от DoS: лимиты ВХОДНОГО изображения ===
    // GD декодирует оригинал целиком (~4 байта/пиксель), поэтому лимит
    // проверяется до декодирования.
    'max_input_megapixels' => 40,   // Мп
    'max_input_bytes' => 20000000,  // байт (~20 МБ)

    // === Оптимизация PNG ===
    'png_indexed' => true,          // палитра для маленьких изображений
    'png_indexed_threshold' => 200, // px; 0 = всегда
    // Примечание: опция png_compression удалена — GD-энкодер Intervention
    // не принимает уровень сжатия, настройка не работала (аудит B-06).

    // === Оптимизация JPEG ===
    'jpeg_progressive' => true,

    // === Оптимизация WebP ===
    'webp_strip_metadata' => true,

    // === Режимы по умолчанию ===
    'default_mode' => 'fit',        // fit | cover | resize
    'default_gravity' => 'center',  // center | top | bottom | left | right | top-left | ...

    // === Форматы и кеширование ===
    'lossless' => false,
    'auto_format' => true,          // авто-WebP/AVIF по Accept (GIF не конвертируется — анимация)
    'cache_invalidation' => 'mtime', // 'mtime' | 'off'
    'cache_auto_clean' => true,     // удалять устаревшие варианты оригинала (все размеры)
    'redirect_to_cache' => true,    // редирект на статичный файл кеша (быстрее, воркер свободен)
    'static_mode' => false,         // true = без проверки mtime, 301, immutable
    'disable_cache' => false,       // true = всегда перегенерировать (разработка)
    'cache_key_config_hash' => true, // смена качества инвалидирует кеш

    // === Безопасность ===
    // Корень оригиналов. Путь проверяется при загрузке конфига.
    'images_base_dir' => __DIR__ . '/../',
    'allowed_extensions' => ['jpg', 'jpeg', 'gif', 'png', 'webp', 'avif', 'svg'],

    // === Пути ===
    'cache_dir' => __DIR__ . '/cache',

    // === Логирование (только отладка) ===
    'logging' => false,             // по умолчанию ВЫКЛЮЧЕНО
    'log_dir' => __DIR__ . '/log',
];
