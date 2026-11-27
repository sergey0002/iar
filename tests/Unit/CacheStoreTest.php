<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Exception\ProcessingException;
use App\Infrastructure\CacheStore;
use App\Tests\Support\Factory;
use App\Tests\Support\FaultyCacheStore;
use PHPUnit\Framework\TestCase;

final class CacheStoreTest extends TestCase
{
    private string $dir;

    private CacheStore $store;

    protected function setUp(): void
    {
        $this->dir = Factory::tempDir() . '/cache';
        $this->store = new CacheStore($this->dir);
    }

    protected function tearDown(): void
    {
        Factory::cleanup();
    }

    public function testDirectoryAndPathFor(): void
    {
        self::assertSame($this->dir, $this->store->directory());
        self::assertSame($this->dir . DIRECTORY_SEPARATOR . 'key.jpg', $this->store->pathFor('key', 'jpg'));
    }

    public function testEnsureDirectoryCreatesOnceAndIsIdempotent(): void
    {
        self::assertFalse(is_dir($this->dir));
        $this->store->ensureDirectory();
        self::assertDirectoryExists($this->dir);
        $this->store->ensureDirectory();
        self::assertDirectoryExists($this->dir);
    }

    /** Аудит B-10/E-04: отказ создания каталога не подавляется молча. */
    public function testEnsureDirectoryFailureThrows(): void
    {
        $blocker = Factory::tempDir() . '/not-a-dir';
        file_put_contents($blocker, 'x');
        $store = new CacheStore($blocker . '/cache');

        $this->expectException(ProcessingException::class);
        $this->expectExceptionMessage('Cache directory');
        $store->ensureDirectory();
    }

    public function testPutCreatesFileAtomically(): void
    {
        $path = $this->store->pathFor('k', 'jpg');
        $this->store->put($path, 'binary-data');

        self::assertFileExists($path);
        self::assertSame('binary-data', file_get_contents($path));
        // Временный файл не должен остаться в каталоге.
        self::assertCount(1, array_diff(scandir($this->dir), ['.', '..']));
    }

    public function testPutOverwritesExisting(): void
    {
        $path = $this->store->pathFor('k', 'jpg');
        $this->store->put($path, 'first');
        $this->store->put($path, 'second');

        self::assertSame('second', file_get_contents($path));
    }

    public function testHasReflectsExistence(): void
    {
        $path = $this->store->pathFor('k', 'jpg');
        self::assertFalse($this->store->has($path));
        $this->store->put($path, 'x');
        self::assertTrue($this->store->has($path));
    }

    public function testPutFailureThrowsAndLeavesNoTemp(): void
    {
        $this->store->ensureDirectory();
        // Каталог без права записи → запись временного файла невозможна.
        chmod($this->dir, 0500);

        try {
            $this->expectException(ProcessingException::class);
            $this->store->put($this->store->pathFor('k', 'jpg'), 'data');
        } finally {
            chmod($this->dir, 0777);
        }
    }

    public function testLockIsAcquiredAndReleased(): void
    {
        $lock = $this->store->acquireLock('key');
        self::assertNotNull($lock);
        $this->store->releaseLock($lock);
        self::assertTrue(true);
    }

    public function testReleaseLockAcceptsNull(): void
    {
        $this->store->releaseLock(null);
        self::assertTrue(true);
    }

    public function testLockFailureReturnsNull(): void
    {
        $blocker = Factory::tempDir() . '/file';
        file_put_contents($blocker, 'x');
        $store = new CacheStore($blocker . '/cache');

        // ensureDirectory бросит исключение раньше — проверяем именно это.
        $this->expectException(ProcessingException::class);
        $store->acquireLock('key');
    }

    /** Аудит B-09: очистка работает для имён со спецсимволами glob. */
    public function testCleanOldVersionsHandlesGlobSpecialChars(): void
    {
        $prefix = 'br[1]__';
        $marker = '__abc123_';
        $this->store->put($this->store->pathFor('br[1]__100x0' . $marker . '1', 'jpg'), 'old');
        $this->store->put($this->store->pathFor('br[1]__100x0' . $marker . '2', 'jpg'), 'new');

        $removed = $this->store->cleanOldVersions($prefix, $marker, 'br[1]__100x0' . $marker . '2.jpg');

        self::assertSame(1, $removed);
        self::assertFileDoesNotExist($this->store->pathFor('br[1]__100x0' . $marker . '1', 'jpg'));
        self::assertFileExists($this->store->pathFor('br[1]__100x0' . $marker . '2', 'jpg'));
    }

    /** Аудит B-09: удаляются варианты ВСЕХ размеров, а не только текущего. */
    public function testCleanOldVersionsRemovesAllSizes(): void
    {
        $marker = '__hash_';
        foreach (['photo__100x0', 'photo__200x0', 'photo__0x300'] as $stem) {
            $this->store->put($this->store->pathFor($stem . $marker . '1', 'jpg'), 'old');
        }
        $keep = 'photo__100x0' . $marker . '2.jpg';
        $this->store->put($this->store->pathFor('photo__100x0' . $marker . '2', 'jpg'), 'new');

        $removed = $this->store->cleanOldVersions('photo__', $marker, $keep);

        self::assertSame(3, $removed);
        self::assertFileExists($this->dir . '/' . $keep);
    }

    public function testCleanOldVersionsKeepsUnrelatedFiles(): void
    {
        $this->store->put($this->store->pathFor('photo__100x0__hash_1', 'jpg'), 'a');
        $this->store->put($this->store->pathFor('photo2__100x0__hash_1', 'jpg'), 'b');
        $this->store->put($this->store->pathFor('photo__100x0__other_1', 'jpg'), 'c');

        $removed = $this->store->cleanOldVersions('photo__', '__hash_', 'keep.jpg');

        self::assertSame(1, $removed);
        self::assertFileExists($this->store->pathFor('photo2__100x0__hash_1', 'jpg'));
        self::assertFileExists($this->store->pathFor('photo__100x0__other_1', 'jpg'));
    }

    public function testCleanOldVersionsSkipsLockFiles(): void
    {
        $this->store->acquireLock('photo__100x0__hash_1');
        $this->store->put($this->store->pathFor('photo__100x0__hash_1', 'jpg'), 'a');

        $removed = $this->store->cleanOldVersions('photo__', '__hash_', 'keep.jpg');

        self::assertSame(1, $removed);
    }

    public function testCleanOldVersionsOnMissingDirectory(): void
    {
        self::assertSame(0, $this->store->cleanOldVersions('photo__', '__hash_', 'keep.jpg'));
    }

    /** Концепция: кеш можно грохнуть целиком — он пересоздаётся. */
    public function testCacheCanBeWipedAndRecreated(): void
    {
        $path = $this->store->pathFor('k', 'jpg');
        $this->store->put($path, 'data');
        unlink($path);
        self::assertFalse($this->store->has($path));

        $this->store->put($path, 'data');
        self::assertTrue($this->store->has($path));
    }

    /** Инъекция отказов ФС: все ветки ошибок проверяются без реального сбоя. */
    public function testTempFileFailure(): void
    {
        $store = new FaultyCacheStore($this->dir);
        $store->failTemp = true;

        $this->expectException(ProcessingException::class);
        $this->expectExceptionMessage('temporary cache file');
        $store->put($this->dir . '/x.jpg', 'data');
    }

    public function testWriteFailure(): void
    {
        $store = new FaultyCacheStore($this->dir);
        $store->failWrite = true;

        $this->expectException(ProcessingException::class);
        $this->expectExceptionMessage('Could not write cache file');
        $store->put($this->dir . '/x.jpg', 'data');
    }

    public function testShortWriteIsDetected(): void
    {
        $store = new FaultyCacheStore($this->dir);
        $store->shortWrite = true;

        $this->expectException(ProcessingException::class);
        $store->put($this->dir . '/x.jpg', 'data');
    }

    public function testPublishFailure(): void
    {
        $store = new FaultyCacheStore($this->dir);
        $store->failPublish = true;

        $this->expectException(ProcessingException::class);
        $this->expectExceptionMessage('Could not publish cache file');
        $store->put($this->dir . '/x.jpg', 'data');
    }

    public function testFailedWritesLeaveNoTemporaryFiles(): void
    {
        $store = new FaultyCacheStore($this->dir);
        $store->failPublish = true;

        try {
            $store->put($this->dir . '/x.jpg', 'data');
        } catch (ProcessingException) {
        }

        self::assertSame([], glob($this->dir . '/iar_*') ?: []);
    }

    public function testLockOpenFailureReturnsNull(): void
    {
        $store = new FaultyCacheStore($this->dir);
        $store->failOpenLock = true;

        self::assertNull($store->acquireLock('key'));
    }

    public function testLockAcquireFailureReturnsNull(): void
    {
        $store = new FaultyCacheStore($this->dir);
        $store->failLock = true;

        self::assertNull($store->acquireLock('key'));
    }

    public function testScanFailureReturnsZero(): void
    {
        $store = new FaultyCacheStore($this->dir);
        $store->ensureDirectory();
        $store->failScan = true;

        self::assertSame(0, $store->cleanOldVersions('photo__', '__abc', 'keep.jpg'));
    }

    public function testEnsureDirectoryFailsWhenPathIsAFile(): void
    {
        $blocker = dirname($this->dir) . '/blocker';
        file_put_contents($blocker, 'x');
        $store = new CacheStore($blocker . '/cache');

        $this->expectException(ProcessingException::class);
        $this->expectExceptionMessage('Cache directory could not be created');
        $store->ensureDirectory();
    }
}
