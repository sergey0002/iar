<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Infrastructure\CacheStore;

/**
 * Инъекция отказов файловой системы. Позволяет проверить ветки
 * «нет места», «нет прав», «блокировка не получена» без реального сбоя.
 */
final class FaultyCacheStore extends CacheStore
{
    public bool $failTemp = false;

    public bool $failWrite = false;

    public bool $shortWrite = false;

    public bool $failPublish = false;

    public bool $failOpenLock = false;

    public bool $failLock = false;

    public bool $failScan = false;

    /** Имитация гонки: первый has() — false, следующий — true. */
    public bool $raceHit = false;

    /** Файл, который «исчезает» перед чтением оригинала. */
    public ?string $vanishingSource = null;

    private int $hasCalls = 0;

    public function has(string $path): bool
    {
        $this->hasCalls++;

        if ($this->raceHit && $this->hasCalls > 1) {
            return true;
        }

        return parent::has($path);
    }

    public function acquireLock(string $key)
    {
        if ($this->vanishingSource !== null && is_file($this->vanishingSource)) {
            @unlink($this->vanishingSource);
            clearstatcache();
        }

        return parent::acquireLock($key);
    }

    protected function createTemp(): string|false
    {
        return $this->failTemp ? false : parent::createTemp();
    }

    protected function writeBytes(string $path, string $data): int|false
    {
        if ($this->failWrite) {
            return false;
        }
        if ($this->shortWrite) {
            return max(0, strlen($data) - 1);
        }

        return parent::writeBytes($path, $data);
    }

    protected function publish(string $from, string $to): bool
    {
        return $this->failPublish ? false : parent::publish($from, $to);
    }

    protected function openLock(string $path)
    {
        return $this->failOpenLock ? false : parent::openLock($path);
    }

    protected function lockExclusively($handle): bool
    {
        return $this->failLock ? false : parent::lockExclusively($handle);
    }

    protected function scan(): array|false
    {
        return $this->failScan ? false : parent::scan();
    }
}
