<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\CropParam;
use App\Domain\Gravity;
use App\Domain\Mode;
use App\Domain\ResizeParams;
use App\Infrastructure\CacheKey;
use App\Tests\Support\Factory;
use PHPUnit\Framework\TestCase;

final class CacheKeyTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = Factory::tempDir() . '/';
    }

    protected function tearDown(): void
    {
        Factory::cleanup();
    }

    private function params(int $w = 100, int $h = 0, Mode $mode = Mode::Fit, ?CropParam $crop = null): ResizeParams
    {
        return new ResizeParams($w, $h, $mode, Gravity::Center, $crop);
    }

    public function testKeyContainsDimensionsHashAndMtime(): void
    {
        $key = CacheKey::build($this->base . 'photo.jpg', $this->base, $this->params(), 12345);

        self::assertStringContainsString('photo__100x0__', $key);
        self::assertStringContainsString('_12345', $key);
        self::assertStringContainsString(md5('photo.jpg'), $key);
    }

    /** Аудит B-19: полный md5 (32 символа), а не 10. */
    public function testHashIsFullLength(): void
    {
        self::assertSame(32, strlen(md5('photo.jpg')));
        self::assertStringContainsString(md5('photo.jpg'), CacheKey::build(
            $this->base . 'photo.jpg',
            $this->base,
            $this->params(),
            1,
        ));
    }

    /**
     * Аудит B-03: вложенный путь, содержащий имя базовой директории,
     * больше не даёт тот же ключ (раньше str_replace вырезал все вхождения).
     */
    public function testNestedPathDoesNotCollideWithBasePath(): void
    {
        $base = '/www/';
        $rootFile = '/www/photo.jpg';
        $nestedFile = '/www/www/photo.jpg';

        $a = CacheKey::build($rootFile, $base, $this->params(), 1000);
        $b = CacheKey::build($nestedFile, $base, $this->params(), 1000);

        self::assertNotSame($a, $b);
        self::assertSame('photo.jpg', CacheKey::relativePath($rootFile, $base));
        self::assertSame('www/photo.jpg', CacheKey::relativePath($nestedFile, $base));
    }

    public function testRelativePathNormalizesSeparators(): void
    {
        self::assertSame('a/b.jpg', CacheKey::relativePath('C:\\site\\a\\b.jpg', 'C:\\site\\'));
    }

    public function testRelativePathOutsideBaseIsKept(): void
    {
        self::assertSame('/other/x.jpg', CacheKey::relativePath('/other/x.jpg', '/www/'));
    }

    public function testCropAndModeSuffixesAreIncluded(): void
    {
        $key = CacheKey::build(
            $this->base . 'photo.jpg',
            $this->base,
            $this->params(200, 100, Mode::Cover, CropParam::parse(\App\Domain\Side::Bottom, '15p')),
            5,
        );

        self::assertStringContainsString('__200x100_cb15p_m-cover__', $key);
    }

    public function testQualityFingerprintChangesKey(): void
    {
        $a = CacheKey::build($this->base . 'photo.jpg', $this->base, $this->params(), 1, '_aaaa1111');
        $b = CacheKey::build($this->base . 'photo.jpg', $this->base, $this->params(), 1, '_bbbb2222');

        self::assertNotSame($a, $b);
    }

    public function testMtimeChangesKey(): void
    {
        self::assertNotSame(
            CacheKey::build($this->base . 'photo.jpg', $this->base, $this->params(), 1),
            CacheKey::build($this->base . 'photo.jpg', $this->base, $this->params(), 2),
        );
    }

    public function testSameInputsProduceSameKey(): void
    {
        self::assertSame(
            CacheKey::build($this->base . 'photo.jpg', $this->base, $this->params(), 7),
            CacheKey::build($this->base . 'photo.jpg', $this->base, $this->params(), 7),
        );
    }

    public function testFilenamePrefixAndHashMarker(): void
    {
        $file = $this->base . 'photo.jpg';

        self::assertSame('photo__', CacheKey::filenamePrefix($file));
        self::assertSame('__' . md5('photo.jpg') . '_', CacheKey::hashMarker($file, $this->base));
    }

    public function testDifferentDirectoriesProduceDifferentKeys(): void
    {
        self::assertNotSame(
            CacheKey::build($this->base . 'a/photo.jpg', $this->base, $this->params(), 1),
            CacheKey::build($this->base . 'b/photo.jpg', $this->base, $this->params(), 1),
        );
    }
}
