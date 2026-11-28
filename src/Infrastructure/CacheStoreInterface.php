<?php

declare(strict_types=1);

namespace App\Infrastructure;

interface CacheStoreInterface
{
    public function pathFor(string $key, string $extension): string;

    public function has(string $path): bool;

    /** Атомарная запись: временный файл + rename. */
    public function put(string $path, string $data): void;

    /** @return resource|null */
    public function acquireLock(string $key);

    /** @param resource|null $lock */
    public function releaseLock($lock): void;

    /**
     * Удаляет устаревшие варианты оригинала — всех размеров, но только
     * прошлых версий. Актуальные варианты разных размеров сохраняются:
     * страница с srcset не должна перегенерировать кеш на каждый запрос.
     *
     * @param string $currentVersion суффикс актуальной версии ("{mtime}{fingerprint}.")
     */
    public function cleanOldVersions(
        string $filenamePrefix,
        string $hashMarker,
        string $keepBasename,
        string $currentVersion = '',
    ): int;

    public function ensureDirectory(): void;

    public function directory(): string;
}
