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

    /** Удаляет устаревшие варианты оригинала (все размеры). */
    public function cleanOldVersions(string $filenamePrefix, string $hashMarker, string $keepBasename): int;

    public function ensureDirectory(): void;

    public function directory(): string;
}
