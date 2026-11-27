<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Exception\ProcessingException;

/**
 * Файловый кеш.
 *
 * Исправления аудита:
 *   B-10/P-02 — атомарная запись (temp + rename) и flock от stampede;
 *   B-09/P-04 — очистка через scandir + строковые операции (glob ломался
 *               на именах с [ ]), удаляются все размеры оригинала;
 *   E-04      — отказ записи не подавляется, а приводит к понятной ошибке.
 *
 * Кеш полностью удаляемый: после удаления любого файла вариант
 * пересоздаётся при первом обращении по URL (концепция продукта).
 *
 * Класс не final: элементарные файловые операции вынесены в protected
 * методы, чтобы тесты могли подменить их и проверить ветки отказов
 * (нет места, нет прав, flock не получен) без реального сбоя диска.
 */
class CacheStore implements CacheStoreInterface
{
    public function __construct(private readonly string $dir)
    {
    }

    public function directory(): string
    {
        return $this->dir;
    }

    public function pathFor(string $key, string $extension): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . $key . '.' . $extension;
    }

    public function has(string $path): bool
    {
        return is_file($path);
    }

    public function ensureDirectory(): void
    {
        if (is_dir($this->dir)) {
            return;
        }

        // Гонка двух воркеров: подавляем warning и перепроверяем факт.
        @mkdir($this->dir, 0755, true);

        if (!is_dir($this->dir)) {
            throw new ProcessingException('Cache directory could not be created: ' . $this->dir);
        }
    }

    public function put(string $path, string $data): void
    {
        $this->ensureDirectory();

        $temp = $this->createTemp();
        if ($temp === false) {
            throw new ProcessingException('Could not create temporary cache file.');
        }

        $written = $this->writeBytes($temp, $data);
        if ($written === false || $written !== strlen($data)) {
            @unlink($temp);

            throw new ProcessingException('Could not write cache file.');
        }

        // Атомарная подмена: читатели никогда не видят недописанный файл.
        if (!$this->publish($temp, $path)) {
            @unlink($temp);

            throw new ProcessingException('Could not publish cache file.');
        }
    }

    /** @return resource|null */
    public function acquireLock(string $key)
    {
        $this->ensureDirectory();

        $lockPath = $this->dir . DIRECTORY_SEPARATOR . '.' . md5($key) . '.lock';
        $handle = $this->openLock($lockPath);
        if ($handle === false) {
            return null;
        }

        if (!$this->lockExclusively($handle)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /** @param resource|null $lock */
    public function releaseLock($lock): void
    {
        if (!is_resource($lock)) {
            return;
        }

        @flock($lock, LOCK_UN);
        @fclose($lock);
    }

    public function cleanOldVersions(string $filenamePrefix, string $hashMarker, string $keepBasename): int
    {
        if (!is_dir($this->dir)) {
            return 0;
        }

        $entries = $this->scan();
        if ($entries === false) {
            return 0;
        }

        $removed = 0;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === $keepBasename) {
                continue;
            }
            if (str_ends_with($entry, '.lock')) {
                continue;
            }
            if (!str_starts_with($entry, $filenamePrefix) || !str_contains($entry, $hashMarker)) {
                continue;
            }

            if (@unlink($this->dir . DIRECTORY_SEPARATOR . $entry)) {
                $removed++;
            }
        }

        return $removed;
    }

    /** @return string|false */
    protected function createTemp(): string|false
    {
        return @tempnam($this->dir, 'iar_');
    }

    /** @return int|false */
    protected function writeBytes(string $path, string $data): int|false
    {
        return @file_put_contents($path, $data);
    }

    protected function publish(string $from, string $to): bool
    {
        return @rename($from, $to);
    }

    /** @return resource|false */
    protected function openLock(string $path)
    {
        return @fopen($path, 'c');
    }

    /** @param resource $handle */
    protected function lockExclusively($handle): bool
    {
        return @flock($handle, LOCK_EX);
    }

    /** @return list<string>|false */
    protected function scan(): array|false
    {
        return @scandir($this->dir);
    }
}
