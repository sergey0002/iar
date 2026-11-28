<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application;
use App\Config;
use App\Domain\Format;
use App\Domain\Side;
use App\Exception\ForbiddenException;
use App\Exception\NotFoundException;
use App\Exception\UnsupportedFormatException;
use App\Http\PathRouter;
use App\Http\Request;
use App\Infrastructure\CacheStore;
use App\Tests\Support\Factory;
use App\Tests\Support\FaultyCacheStore;
use App\Tests\Support\FakeResizer;
use App\Tests\Support\SpyLogger;
use App\Tests\Support\TestSapi;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    private string $site;

    private SpyLogger $logger;

    protected function setUp(): void
    {
        $this->site = Factory::site();
        $this->logger = new SpyLogger();
    }

    protected function tearDown(): void
    {
        Factory::cleanup();
    }

    private function app(array $overrides = [], ?FakeResizer $resizer = null): Application
    {
        $config = Factory::config($this->site, $overrides);

        return new Application(
            $config,
            $resizer ?? new FakeResizer(),
            new CacheStore($config->cacheDir),
            $this->logger,
        );
    }

    private function config(array $overrides = []): Config
    {
        return Factory::config($this->site, $overrides);
    }

    private function request(array $get = [], array $server = [], ?PathRouter $router = null): Request
    {
        return Factory::request($this->site, $get, $server, $router);
    }

    private function handle(
        Application $app,
        array $get = [],
        array $server = [],
        ?PathRouter $router = null,
    ): TestSapi {
        return Factory::send($app->handle($this->request($get, $server, $router)));
    }

    /** Маршруты: «z» скрывает реальный каталог, «thumb» — пресет. */
    private function router(): PathRouter
    {
        return Factory::router($this->site, [
            'aliases' => ['z' => 'nested/www'],
            'presets' => ['thumb' => ['w' => 60, 'h' => 40, 'm' => 'cover']],
        ]);
    }

    public function testFirstRequestGeneratesAndServesImage(): void
    {
        $resizer = new FakeResizer();
        $sapi = $this->handle($this->app([], $resizer), ['w' => '300']);

        self::assertSame(200, $sapi->status);
        self::assertSame('FAKE-IMAGE-BYTES', $sapi->body);
        self::assertSame('image/jpeg', $sapi->headerValue('Content-Type'));
        self::assertSame((string) strlen('FAKE-IMAGE-BYTES'), $sapi->headerValue('Content-Length'));
        self::assertSame('nosniff', $sapi->headerValue('X-Content-Type-Options'));
        self::assertSame('no-cache', $sapi->headerValue('Cache-Control'));
        self::assertNotNull($sapi->headerValue('ETag'));
        self::assertSame(1, $resizer->calls);
        self::assertSame('miss', $this->logger->lastEvent());
    }

    public function testSecondRequestIsCacheHit(): void
    {
        $resizer = new FakeResizer();
        $app = $this->app([], $resizer);

        $this->handle($app, ['w' => '300']);
        $sapi = $this->handle($app, ['w' => '300']);

        self::assertSame(200, $sapi->status);
        self::assertSame(1, $resizer->calls);
        self::assertSame('hit', $this->logger->lastEvent());
    }

    /** Аудит B-14: no-op не перекодирует, а копирует байты оригинала. */
    public function testNoOpCopiesOriginalBytes(): void
    {
        $resizer = new FakeResizer();
        $sapi = $this->handle($this->app([], $resizer));

        self::assertSame(0, $resizer->calls);
        self::assertSame(file_get_contents($this->site . '/photo.jpg'), $sapi->body);
    }

    public function testNoOpWithFormatChangeStillRenders(): void
    {
        $resizer = new FakeResizer();
        $this->handle($this->app([], $resizer), ['fm' => 'png']);

        self::assertSame(1, $resizer->calls);
        self::assertSame(Format::Png, $resizer->lastTarget);
    }

    public function testCropOnlyCountsAsOperation(): void
    {
        $resizer = new FakeResizer();
        $this->handle($this->app([], $resizer), ['ct' => '10']);

        self::assertSame(1, $resizer->calls);
        self::assertNotNull($resizer->lastParams?->crop);
    }

    /** Аудит H-02/B-02: 304 повторяет ETag, Cache-Control и Vary. */
    public function testConditionalRequestReturnsFullNotModified(): void
    {
        $app = $this->app();
        $first = $this->handle($app, ['w' => '100']);
        $etag = (string) $first->headerValue('ETag');

        $second = $this->handle($app, ['w' => '100'], ['HTTP_IF_NONE_MATCH' => $etag]);

        self::assertSame(304, $second->status);
        self::assertSame('', $second->body);
        self::assertSame($etag, $second->headerValue('ETag'));
        self::assertSame('no-cache', $second->headerValue('Cache-Control'));
        self::assertSame('Accept', $second->headerValue('Vary'));
        self::assertSame('nosniff', $second->headerValue('X-Content-Type-Options'));
    }

    public function testMismatchedEtagReturnsFullResponse(): void
    {
        $sapi = $this->handle($this->app(), ['w' => '100'], ['HTTP_IF_NONE_MATCH' => '"stale"']);

        self::assertSame(200, $sapi->status);
        self::assertNotSame('', $sapi->body);
    }

    /** Аудит H-05: HEAD отдаёт заголовки без тела. */
    public function testHeadRequestHasNoBody(): void
    {
        $sapi = $this->handle($this->app(), ['w' => '100'], ['REQUEST_METHOD' => 'HEAD']);

        self::assertSame(200, $sapi->status);
        self::assertSame('', $sapi->body);
        self::assertNull($sapi->fileRead);
        self::assertSame((string) strlen('FAKE-IMAGE-BYTES'), $sapi->headerValue('Content-Length'));
    }

    public function testHeadNotModified(): void
    {
        $app = $this->app();
        $etag = (string) $this->handle($app, ['w' => '100'])->headerValue('ETag');
        $sapi = $this->handle($app, ['w' => '100'], [
            'REQUEST_METHOD' => 'HEAD',
            'HTTP_IF_NONE_MATCH' => $etag,
        ]);

        self::assertSame(304, $sapi->status);
        self::assertSame('', $sapi->body);
    }

    /** Аудит B-08: Location строится из реального cache_dir. */
    public function testRedirectToCacheUsesRelativeUrl(): void
    {
        $app = $this->app(['redirect_to_cache' => true]);
        $sapi = $this->handle($app, ['w' => '100']);

        self::assertSame(302, $sapi->status);
        $location = (string) $sapi->headerValue('Location');
        self::assertStringStartsWith('/cache/', $location);
        self::assertStringEndsWith('.jpg', $location);
        self::assertSame('no-cache', $sapi->headerValue('Cache-Control'));
        self::assertStringNotContainsString('immutable', (string) $sapi->headerValue('Cache-Control'));
        self::assertFileExists($this->site . $location);
    }

    public function testRedirectWithCustomCacheDir(): void
    {
        $app = $this->app([
            'redirect_to_cache' => true,
            'cache_dir' => $this->site . '/assets/generated',
        ]);
        $sapi = $this->handle($app, ['w' => '100']);

        self::assertSame(302, $sapi->status);
        self::assertStringStartsWith('/assets/generated/', (string) $sapi->headerValue('Location'));
    }

    public function testRedirectWithNestedDocumentRoot(): void
    {
        mkdir($this->site . '/public', 0777, true);
        $app = $this->app(['redirect_to_cache' => true]);
        $sapi = $this->handle($app, ['w' => '100'], ['DOCUMENT_ROOT' => $this->site . '/public']);

        // Кеш вне DOCUMENT_ROOT — редирект невозможен, отдаём файл сами.
        self::assertSame(200, $sapi->status);
        self::assertNull($sapi->headerValue('Location'));
    }

    public function testNoRedirectWithoutDocumentRoot(): void
    {
        $app = $this->app(['redirect_to_cache' => true]);
        $sapi = $this->handle($app, ['w' => '100'], ['DOCUMENT_ROOT' => '']);

        self::assertSame(200, $sapi->status);
        self::assertNull($sapi->headerValue('Location'));
    }

    public function testNoRedirectWhenDocumentRootDoesNotExist(): void
    {
        $app = $this->app(['redirect_to_cache' => true]);
        $sapi = $this->handle($app, ['w' => '100'], ['DOCUMENT_ROOT' => $this->site . '/nope']);

        self::assertSame(200, $sapi->status);
    }

    public function testRedirectHeadHasNoBody(): void
    {
        $app = $this->app(['redirect_to_cache' => true]);
        $sapi = $this->handle($app, ['w' => '100'], ['REQUEST_METHOD' => 'HEAD']);

        self::assertSame(302, $sapi->status);
        self::assertSame('', $sapi->body);
    }

    /** Аудит B-02: immutable только в static_mode и только на 200. */
    public function testStaticModeAddsImmutableOnDirectResponse(): void
    {
        $sapi = $this->handle($this->app(['static_mode' => true]), ['w' => '100']);

        self::assertSame(200, $sapi->status);
        self::assertSame('public, max-age=31536000, immutable', $sapi->headerValue('Cache-Control'));
    }

    public function testStaticModeRedirectIsPermanentButNotImmutable(): void
    {
        $app = $this->app(['static_mode' => true, 'redirect_to_cache' => true]);
        $sapi = $this->handle($app, ['w' => '100']);

        self::assertSame(301, $sapi->status);
        self::assertSame('public, max-age=31536000', $sapi->headerValue('Cache-Control'));
    }

    public function testStaticModeIgnoresMtime(): void
    {
        $resizer = new FakeResizer();
        $app = $this->app(['static_mode' => true], $resizer);
        $this->handle($app, ['w' => '100']);

        touch($this->site . '/photo.jpg', time() + 500);
        clearstatcache();
        $this->handle($app, ['w' => '100']);

        self::assertSame(1, $resizer->calls);
    }

    public function testDisableCacheRegeneratesEveryTime(): void
    {
        $resizer = new FakeResizer();
        $app = $this->app(['disable_cache' => true], $resizer);

        $sapi = $this->handle($app, ['w' => '100']);
        $this->handle($app, ['w' => '100']);

        self::assertSame(2, $resizer->calls);
        self::assertSame('no-cache, no-store, must-revalidate', $sapi->headerValue('Cache-Control'));
        self::assertSame('no-cache', $sapi->headerValue('Pragma'));
        self::assertSame('0', $sapi->headerValue('Expires'));
        self::assertNull($sapi->headerValue('ETag'));
    }

    /** Аудит S-01: SVG отдаётся с CSP, запрещающей скрипты. */
    public function testSvgGetsContentSecurityPolicy(): void
    {
        $sapi = $this->handle($this->app(), ['name' => 'icon.svg']);

        self::assertSame(200, $sapi->status);
        self::assertSame('image/svg+xml', $sapi->headerValue('Content-Type'));
        $csp = (string) $sapi->headerValue('Content-Security-Policy');
        self::assertStringContainsString("default-src 'none'", $csp);
        self::assertStringContainsString('sandbox', $csp);
    }

    public function testRasterHasNoCspHeader(): void
    {
        $sapi = $this->handle($this->app(), ['w' => '100']);

        self::assertNull($sapi->headerValue('Content-Security-Policy'));
    }

    /** Аудит B-01/L-06: fallback логирует причину и не кешируется. */
    public function testProcessingFailureFallsBackToOriginal(): void
    {
        $resizer = new FakeResizer();
        $resizer->failure = 'decoder exploded';
        $sapi = $this->handle($this->app([], $resizer), ['w' => '100']);

        self::assertSame(302, $sapi->status);
        self::assertSame('/photo.jpg', $sapi->headerValue('Location'));
        self::assertSame('no-store', $sapi->headerValue('Cache-Control'));
        self::assertSame('fail', $this->logger->lastEvent());
        self::assertSame('decoder exploded', $this->logger->lastContext()['reason'] ?? null);
    }

    public function testFallbackUrlKeepsSubdirectory(): void
    {
        $resizer = new FakeResizer();
        $resizer->failure = 'boom';
        $sapi = $this->handle($this->app([], $resizer), ['name' => 'nested/www/photo.jpg', 'w' => '10']);

        self::assertSame('/nested/www/photo.jpg', $sapi->headerValue('Location'));
    }

    public function testFallbackHeadHasNoBody(): void
    {
        $resizer = new FakeResizer();
        $resizer->failure = 'boom';
        $sapi = $this->handle($this->app([], $resizer), ['w' => '10'], ['REQUEST_METHOD' => 'HEAD']);

        self::assertSame(302, $sapi->status);
        self::assertSame('', $sapi->body);
    }

    public function testMissingFileThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->app()->handle($this->request(['name' => 'nope.jpg']));
    }

    public function testTraversalIsRejected(): void
    {
        $this->expectException(NotFoundException::class);
        $this->app()->handle($this->request(['name' => '../../../etc/passwd']));
    }

    public function testDirectoryIsNotAFile(): void
    {
        $this->expectException(NotFoundException::class);
        $this->app()->handle($this->request(['name' => 'nested']));
    }

    public function testDisallowedExtensionIsForbidden(): void
    {
        file_put_contents($this->site . '/secret.txt', 'x');

        $this->expectException(ForbiddenException::class);
        $this->app()->handle($this->request(['name' => 'secret.txt']));
    }

    public function testAllowedButUnknownFormatIsRejected(): void
    {
        file_put_contents($this->site . '/pic.tiff', 'x');

        $this->expectException(UnsupportedFormatException::class);
        $this->app(['allowed_extensions' => ['jpg', 'tiff']])
            ->handle($this->request(['name' => 'pic.tiff']));
    }

    public function testSymlinkOutsideBaseDirIsRejected(): void
    {
        $outside = Factory::tempDir();
        file_put_contents($outside . '/secret.jpg', 'x');
        @symlink($outside . '/secret.jpg', $this->site . '/link.jpg');

        if (!is_link($this->site . '/link.jpg')) {
            self::markTestSkipped('Symlinks are not supported on this platform');
        }

        $this->expectException(ForbiddenException::class);
        $this->app()->handle($this->request(['name' => 'link.jpg']));
    }

    public function testDimensionsAreClampedToConfiguredMaximum(): void
    {
        $resizer = new FakeResizer();
        $this->handle($this->app(['max_width' => 500, 'max_height' => 400], $resizer), [
            'w' => '99999',
            'h' => '99999',
        ]);

        self::assertSame(500, $resizer->lastParams?->width);
        self::assertSame(400, $resizer->lastParams?->height);
    }

    public function testDefaultModeAndGravityComeFromConfig(): void
    {
        $resizer = new FakeResizer();
        $this->handle($this->app(['default_mode' => 'cover', 'default_gravity' => 'top'], $resizer), [
            'w' => '100',
        ]);

        self::assertSame('cover', $resizer->lastParams?->mode->value);
        self::assertSame('top', $resizer->lastParams?->gravity->value);
    }

    /** Аудит B-03: одинаковые имена в разных каталогах не должны сталкиваться. */
    public function testSameBasenameInDifferentDirsGetsDifferentCacheFiles(): void
    {
        $app = $this->app();
        $a = $this->handle($app, ['name' => 'photo.jpg', 'w' => '100']);
        $b = $this->handle($app, ['name' => 'nested/www/photo.jpg', 'w' => '100']);

        $files = glob($this->site . '/cache/photo__100x0*') ?: [];
        self::assertCount(2, $files);
        self::assertNotSame(basename($files[0]), basename($files[1]));
        self::assertSame($a->body, $b->body);
    }

    public function testMtimeChangeInvalidatesCache(): void
    {
        $resizer = new FakeResizer();
        $app = $this->app([], $resizer);
        $this->handle($app, ['w' => '100']);

        touch($this->site . '/photo.jpg', time() + 1000);
        clearstatcache();
        $this->handle($app, ['w' => '100']);

        self::assertSame(2, $resizer->calls);
    }

    public function testInvalidationOffKeepsCacheAcrossMtimeChange(): void
    {
        $resizer = new FakeResizer();
        $app = $this->app(['cache_invalidation' => 'off'], $resizer);
        $this->handle($app, ['w' => '100']);

        touch($this->site . '/photo.jpg', time() + 1000);
        clearstatcache();
        $this->handle($app, ['w' => '100']);

        self::assertSame(1, $resizer->calls);
    }

    /** Аудит B-17: смена качества меняет кеш-ключ. */
    public function testQualityChangeInvalidatesCache(): void
    {
        $this->handle($this->app(['jpeg_quality' => 82]), ['w' => '100']);
        $resizer = new FakeResizer();
        $this->handle($this->app(['jpeg_quality' => 60], $resizer), ['w' => '100']);

        self::assertSame(1, $resizer->calls);
    }

    public function testConfigHashCanBeDisabled(): void
    {
        $this->handle($this->app(['cache_key_config_hash' => false]), ['w' => '100']);
        $resizer = new FakeResizer();
        $this->handle($this->app(['cache_key_config_hash' => false, 'jpeg_quality' => 60], $resizer), [
            'w' => '100',
        ]);

        self::assertSame(0, $resizer->calls);
    }

    /** Аудит B-15: автоочистка удаляет старые версии того же изображения. */
    public function testAutoCleanRemovesOldVersions(): void
    {
        $app = $this->app();
        $this->handle($app, ['w' => '100']);
        $this->handle($app, ['w' => '200']);

        touch($this->site . '/photo.jpg', time() + 1000);
        clearstatcache();
        $this->handle($app, ['w' => '100']);

        $files = glob($this->site . '/cache/photo__*') ?: [];
        self::assertCount(1, $files);
        self::assertStringContainsString('__100x0', $files[0]);
    }

    public function testAutoCleanCanBeDisabled(): void
    {
        $app = $this->app(['cache_auto_clean' => false]);
        $this->handle($app, ['w' => '100']);

        touch($this->site . '/photo.jpg', time() + 1000);
        clearstatcache();
        $this->handle($app, ['w' => '100']);

        self::assertCount(2, glob($this->site . '/cache/photo__*') ?: []);
    }

    /** Концепция продукта: кеш можно удалить целиком — он пересоздаётся. */
    public function testWipedCacheIsRegenerated(): void
    {
        $resizer = new FakeResizer();
        $app = $this->app([], $resizer);
        $first = $this->handle($app, ['w' => '100']);

        Factory::removeDir($this->site . '/cache');
        self::assertDirectoryDoesNotExist($this->site . '/cache');

        $second = $this->handle($app, ['w' => '100']);

        self::assertSame(200, $second->status);
        self::assertSame($first->body, $second->body);
        self::assertSame(2, $resizer->calls);
    }

    public function testVaryAcceptOnAutoFormat(): void
    {
        $sapi = $this->handle($this->app(), ['w' => '100']);

        self::assertSame('Accept', $sapi->headerValue('Vary'));
    }

    public function testNoVaryWithExplicitFormat(): void
    {
        $sapi = $this->handle($this->app(), ['w' => '100', 'fm' => 'png']);

        self::assertNull($sapi->headerValue('Vary'));
    }

    public function testMultipleCropParamsProduceWarningHeader(): void
    {
        $resizer = new FakeResizer();
        $sapi = $this->handle($this->app([], $resizer), ['ct' => '10', 'cb' => '20']);

        self::assertNotNull($sapi->headerValue('X-Iar-Warning'));
        self::assertSame(Side::Top, $resizer->lastParams?->crop?->side);
    }

    public function testLogContextContainsMetrics(): void
    {
        $this->handle($this->app(), ['w' => '100']);
        $context = (array) $this->logger->lastContext();

        self::assertSame(200, $context['status']);
        self::assertArrayHasKey('orig_bytes', $context);
        self::assertArrayHasKey('new_bytes', $context);
        self::assertArrayHasKey('saved_pct', $context);
        self::assertArrayHasKey('duration_ms', $context);
        self::assertSame(['name' => 'photo.jpg', 'w' => '100'], $context['params']);
    }

    /** Пустой оригинал: метрики не делят на ноль. */
    public function testSavedPercentIsZeroForEmptyOriginal(): void
    {
        file_put_contents($this->site . '/empty.jpg', '');
        $sapi = $this->handle($this->app(), ['name' => 'empty.jpg']);

        self::assertSame(200, $sapi->status);
        self::assertSame(0.0, $this->logger->lastContext()['saved_pct'] ?? null);
        self::assertSame(0, $this->logger->lastContext()['orig_bytes'] ?? null);
    }

    public function testUnicodeAndSpecialCharacterNames(): void
    {
        foreach (['фотка.jpg', 'photo copy.jpg', 'a&b.jpg', 'br[1].jpg'] as $name) {
            $sapi = $this->handle($this->app(), ['name' => $name, 'w' => '100']);
            self::assertSame(200, $sapi->status, $name);
        }
    }

    public function testPathFromUriBeatsQueryName(): void
    {
        $request = Factory::request($this->site, ['name' => 'private.png'], [
            'REQUEST_URI' => '/iar/photo.jpg?name=private.png',
        ]);
        $sapi = Factory::send($this->app()->handle($request));

        self::assertSame(200, $sapi->status);
        self::assertSame('image/jpeg', $sapi->headerValue('Content-Type'));
    }

    /** Аудит H-04: при сжатии вывода Content-Length не отправляется. */
    public function testContentLengthOmittedWhenOutputCompressionActive(): void
    {
        $config = $this->config();
        $app = new Application(
            $config,
            new FakeResizer(),
            new CacheStore($config->cacheDir),
            $this->logger,
            null,
            true,
        );

        $sapi = Factory::send($app->handle($this->request(['w' => '100'])));

        self::assertNull($sapi->headerValue('Content-Length'));
        self::assertSame('FAKE-IMAGE-BYTES', $sapi->body);
    }

    public function testContentLengthPresentWhenCompressionDisabled(): void
    {
        $config = $this->config();
        $app = new Application(
            $config,
            new FakeResizer(),
            new CacheStore($config->cacheDir),
            $this->logger,
            null,
            false,
        );

        $sapi = Factory::send($app->handle($this->request(['w' => '100'])));

        self::assertSame('16', $sapi->headerValue('Content-Length'));
    }

    public function testCacheStoreDirectoryIsExposedForUrlBuilding(): void
    {
        $store = new CacheStore($this->config()->cacheDir);
        $store->ensureDirectory();

        self::assertSame($this->site . '/cache', $store->directory());
    }

    /** Гонка воркеров: пока ждали блокировку, вариант уже создан. */
    public function testRaceWinnerIsReportedAsHit(): void
    {
        $config = $this->config();
        $cache = new FaultyCacheStore($config->cacheDir);
        $cache->raceHit = true;
        $resizer = new FakeResizer();
        $app = new Application($config, $resizer, $cache, $this->logger);

        $sapi = Factory::send($app->handle($this->request(['w' => '100'])));

        self::assertSame(200, $sapi->status);
        self::assertSame(0, $resizer->calls);
        self::assertSame('hit', $this->logger->lastEvent());
    }

    /** Оригинал исчез между проверкой пути и чтением — понятный fallback. */
    public function testVanishedSourceFallsBackToOriginal(): void
    {
        $config = $this->config();
        $cache = new FaultyCacheStore($config->cacheDir);
        $cache->vanishingSource = $this->site . '/photo.jpg';
        $app = new Application($config, new FakeResizer(), $cache, $this->logger);

        $sapi = Factory::send($app->handle($this->request()));

        self::assertSame(302, $sapi->status);
        self::assertSame('fail', $this->logger->lastEvent());
        self::assertSame('Could not read source image.', $this->logger->lastContext()['reason'] ?? null);
    }

    /** Отказ записи в кеш приводит к fallback, а не к битому ответу. */
    public function testCacheWriteFailureFallsBackToOriginal(): void
    {
        $config = $this->config();
        $cache = new FaultyCacheStore($config->cacheDir);
        $cache->failWrite = true;
        $app = new Application($config, new FakeResizer(), $cache, $this->logger);

        $sapi = Factory::send($app->handle($this->request(['w' => '100'])));

        self::assertSame(302, $sapi->status);
        self::assertSame('no-store', $sapi->headerValue('Cache-Control'));
    }

    /**
     * Политика производительности: размеры актуальной версии оригинала
     * не вытесняют друг друга. Иначе страница с srcset перегенерировала бы
     * кеш на каждый запрос.
     */
    public function testDifferentSizesOfCurrentVersionCoexist(): void
    {
        $app = $this->app();
        $this->handle($app, ['w' => '100']);
        $this->handle($app, ['w' => '200']);
        $again = $this->handle($app, ['w' => '100']);

        self::assertSame('hit', $again->headerValue('X-Iar-Cache'));
        self::assertCount(2, glob($this->site . '/cache/photo__*') ?: []);
    }

    /** Смена оригинала удаляет устаревшие варианты всех размеров. */
    public function testOriginalChangeRemovesStaleSizes(): void
    {
        $app = $this->app();
        $this->handle($app, ['w' => '100']);
        $this->handle($app, ['w' => '200']);

        touch($this->site . '/photo.jpg', time() + 900);
        clearstatcache();
        $this->handle($app, ['w' => '100']);

        $files = glob($this->site . '/cache/photo__*') ?: [];
        self::assertCount(1, $files);
        self::assertStringContainsString('100x0', basename($files[0]));
    }

    /** Горячий путь: заголовок показывает, что вариант взят из кеша. */
    public function testCacheStatusHeader(): void
    {
        $app = $this->app();
        self::assertSame('miss', $this->handle($app, ['w' => '100'])->headerValue('X-Iar-Cache'));
        self::assertSame('hit', $this->handle($app, ['w' => '100'])->headerValue('X-Iar-Cache'));
    }

    /**
     * Политика производительности: при наличии варианта оригинал не читается
     * и не декодируется. Оригинал на 140 Мп (файл-заголовок) не должен
     * ни замедлить, ни уронить запрос из кеша.
     */
    public function testCacheHitDoesNotTouchHugeOriginal(): void
    {
        $config = $this->config();
        $cache = new CacheStore($config->cacheDir);
        $resizer = new FakeResizer();
        $app = new Application($config, $resizer, $cache, $this->logger);

        // Прогреваем кеш для «огромного» оригинала обычным рендером.
        $this->handle($app, ['name' => 'huge.jpg', 'w' => '100']);
        self::assertSame(1, $resizer->calls);

        $sapi = $this->handle($app, ['name' => 'huge.jpg', 'w' => '100']);

        self::assertSame(200, $sapi->status);
        self::assertSame('hit', $sapi->headerValue('X-Iar-Cache'));
        self::assertSame(1, $resizer->calls);
    }

    /** Оригинал побитово испорчен, но вариант в кеше есть — отдаём вариант. */
    public function testCacheHitSurvivesCorruptedOriginal(): void
    {
        $app = $this->app();
        $this->handle($app, ['w' => '100']);

        $mtime = (int) filemtime($this->site . '/photo.jpg');
        file_put_contents($this->site . '/photo.jpg', 'broken bytes');
        touch($this->site . '/photo.jpg', $mtime);
        clearstatcache();

        $sapi = $this->handle($app, ['w' => '100']);
        self::assertSame(200, $sapi->status);
        self::assertSame('hit', $sapi->headerValue('X-Iar-Cache'));
    }

    /** ETag выводится из ключа: он стабилен и меняется вместе с оригиналом. */
    public function testEtagIsDerivedFromCacheKey(): void
    {
        $app = $this->app();
        $first = $this->handle($app, ['w' => '100']);
        $second = $this->handle($app, ['w' => '100']);
        self::assertSame($first->headerValue('ETag'), $second->headerValue('ETag'));

        touch($this->site . '/photo.jpg', time() + 500);
        clearstatcache();
        $third = $this->handle($app, ['w' => '100']);

        self::assertNotSame($first->headerValue('ETag'), $third->headerValue('ETag'));
    }

    public function testDifferentSizesHaveDifferentEtags(): void
    {
        $app = $this->app();
        $a = $this->handle($app, ['w' => '100']);
        $b = $this->handle($app, ['w' => '200']);

        self::assertNotSame($a->headerValue('ETag'), $b->headerValue('ETag'));
    }

    /** Выключенный лог: метрики не собираются вовсе. */
    public function testDisabledLoggerCollectsNothing(): void
    {
        $config = $this->config();
        $logger = new SpyLogger(false);
        $app = new Application($config, new FakeResizer(), new CacheStore($config->cacheDir), $logger);

        Factory::send($app->handle($this->request(['w' => '100'])));
        Factory::send($app->handle($this->request(['w' => '100'])));

        self::assertSame([], $logger->records);
    }

    /** Пустой результат обработки никогда не попадает в кеш: отдаём оригинал. */
    public function testEmptyRenderFallsBackToOriginal(): void
    {
        $sapi = $this->handle($this->app([], new FakeResizer('')), ['w' => '100']);

        self::assertSame(302, $sapi->status);
        self::assertSame('/photo.jpg', $sapi->headerValue('Location'));
        self::assertSame([], glob($this->site . '/cache/photo__100x0*') ?: []);
    }

    /** Политика анимации: неудачная конвертация → оригинал, картинка не пропадает. */
    public function testFailedAnimationConversionFallsBackToOriginal(): void
    {
        $resizer = new FakeResizer();
        $resizer->failure = 'Animated gif cannot be converted to webp without losing frames.';
        $sapi = $this->handle($this->app([], $resizer), ['name' => 'anim.gif', 'fm' => 'webp']);

        self::assertSame(302, $sapi->status);
        self::assertSame('/anim.gif', $sapi->headerValue('Location'));
        self::assertSame('no-store', $sapi->headerValue('Cache-Control'));
    }

    // -----------------------------------------------------------------
    // Маршруты из aliases.json в оркестрации
    // -----------------------------------------------------------------

    public function testAliasAndMacroProduceResizedVariant(): void
    {
        $resizer = new FakeResizer();
        $sapi = $this->handle($this->app([], $resizer), ['name' => 'z/x-60/photo.jpg'], [], $this->router());

        self::assertSame(200, $sapi->status);
        self::assertSame($this->site . '/nested/www/photo.jpg', $resizer->lastSource);
        self::assertSame(60, $resizer->lastParams?->width);
        self::assertSame('miss', $this->logger->lastEvent());
        self::assertSame('z', $this->logger->lastContext()['alias'] ?? null);
        self::assertSame('x-60', $this->logger->lastContext()['preset'] ?? null);
        self::assertSame('60', $this->logger->lastContext()['params']['w'] ?? null);
    }

    public function testPresetParametersReachResizer(): void
    {
        $resizer = new FakeResizer();
        $this->handle($this->app([], $resizer), ['name' => 'thumb/photo.jpg'], [], $this->router());

        self::assertSame(60, $resizer->lastParams?->width);
        self::assertSame(40, $resizer->lastParams?->height);
        self::assertSame('cover', $resizer->lastParams?->mode->value);
    }

    public function testQueryOverridesPresetInApplication(): void
    {
        $resizer = new FakeResizer();
        $this->handle($this->app([], $resizer), ['name' => 'thumb/photo.jpg', 'w' => '20'], [], $this->router());

        self::assertSame(20, $resizer->lastParams?->width);
    }

    /** Псевдоним скрывает реальный путь: редиректить некуда, отдаём тело. */
    public function testAliasedFailureServesOriginalInline(): void
    {
        $resizer = new FakeResizer();
        $resizer->failure = 'decoder exploded';
        $sapi = $this->handle($this->app([], $resizer), ['name' => 'z/photo.jpg', 'w' => '10'], [], $this->router());

        self::assertSame(200, $sapi->status);
        self::assertNull($sapi->headerValue('Location'));
        self::assertSame('original', $sapi->headerValue('X-Iar-Fallback'));
        self::assertSame('intervention', $sapi->headerValue('X-Iar-Engine'));
        self::assertSame('image/jpeg', $sapi->headerValue('Content-Type'));
        self::assertSame('no-store', $sapi->headerValue('Cache-Control'));
        self::assertSame('nosniff', $sapi->headerValue('X-Content-Type-Options'));
        self::assertSame(
            (string) filesize($this->site . '/nested/www/photo.jpg'),
            $sapi->headerValue('Content-Length'),
        );
        self::assertSame(file_get_contents($this->site . '/nested/www/photo.jpg'), $sapi->body);

        $context = $this->logger->lastContext();
        self::assertSame('fail', $this->logger->lastEvent());
        self::assertSame(200, $context['status'] ?? null);
        self::assertArrayHasKey('redirect', $context);
        self::assertNull($context['redirect']);
        self::assertSame('z', $context['alias'] ?? null);
    }

    public function testAliasedFailureHeadHasNoBody(): void
    {
        $resizer = new FakeResizer();
        $resizer->failure = 'boom';
        $sapi = $this->handle(
            $this->app([], $resizer),
            ['name' => 'z/photo.jpg', 'w' => '10'],
            ['REQUEST_METHOD' => 'HEAD'],
            $this->router(),
        );

        self::assertSame(200, $sapi->status);
        self::assertSame('', $sapi->body);
        self::assertSame('original', $sapi->headerValue('X-Iar-Fallback'));
    }

    /** Без псевдонима реальное имя видно в URL — прежний редирект. */
    public function testPresetOnlyFailureStillRedirects(): void
    {
        $resizer = new FakeResizer();
        $resizer->failure = 'boom';
        $sapi = $this->handle($this->app([], $resizer), ['name' => 'thumb/photo.jpg'], [], $this->router());

        self::assertSame(302, $sapi->status);
        self::assertSame('/photo.jpg', $sapi->headerValue('Location'));
        self::assertSame('thumb', $this->logger->lastContext()['preset'] ?? null);
    }

    public function testAliasedSvgFailureKeepsCsp(): void
    {
        $resizer = new FakeResizer();
        $resizer->failure = 'boom';
        $router = Factory::router($this->site, ['aliases' => ['i' => '']]);
        $sapi = $this->handle($this->app([], $resizer), ['name' => 'i/icon.svg', 'w' => '10'], [], $router);

        self::assertSame(200, $sapi->status);
        self::assertSame('image/svg+xml', $sapi->headerValue('Content-Type'));
        self::assertNotNull($sapi->headerValue('Content-Security-Policy'));
    }
}
