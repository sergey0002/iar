<?php

return [
    // === Качество и размеры ===
    'max_width' => 2000,    // px, защита от DoS
    'max_height' => 2000,    // px
    'jpeg_quality' => 82,      // 1–100; 82 — баланс качества и размера
    'webp_quality' => 85,      // 1–100
    'avif_quality' => 75,      // 1–100
    'png_compression' => 9,       // 0–9 (9 = максимум для минимального веса)

    // === Режимы по умолчанию ===
    'default_mode' => 'fit',    // fit, cover, resize
    'default_gravity' => 'center', // top, bottom, left, right, center...

    // === Оптимизация ===
    'lossless' => false,    // true = Max Quality Mode (quality 100)
    'auto_format' => true,     // Автовыбор WebP/AVIF на основе Accept браузера
    'cache_invalidation' => 'mtime',  // 'mtime' (по дате изменения) или 'off'
    'cache_auto_clean' => true,     // Удалять старые версии кеша при обновлении оригинала

    // === Безопасность ===
    // Директория с оригиналами (относительно корня проекта или абсолютный путь)
    'images_base_dir' => realpath(__DIR__ . '/../'),
    'allowed_extensions' => ['jpg', 'jpeg', 'gif', 'png', 'webp', 'avif', 'svg'],

    // === Пути ===
    'cache_dir' => __DIR__ . '/cache',
];
