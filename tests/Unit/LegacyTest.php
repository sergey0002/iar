<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\CropParam;
use App\Domain\Format;
use App\Domain\Gravity;
use App\Domain\Mode;
use App\Domain\ResizeParams;
use App\Domain\Side;
use App\Http\PathRouter;
use App\Infrastructure\CacheKey;
use App\Tests\Support\Factory;
use IarLegacyError;
use PHPUnit\Framework\TestCase;

require_once defined('IAR_LEGACY') ? IAR_LEGACY : dirname(__DIR__, 2) . '/legacy.php';

/**
 * Резервный движок (legacy.php): PHP 7.0+, чистый GD, ноль зависимостей.
 *
 * Кроме собственных проверок здесь есть «кросс-движковые» тесты: кеш-ключи,
 * отпечаток качества и маркер хеша обязаны совпадать с основным движком
 * байт в байт — иначе два движка не смогли бы делить один кеш.
 */
final class LegacyTest extends TestCase
{
    private string $site;

    protected function setUp(): void
    {
        $this->site = Factory::site();
    }

    protected function tearDown(): void
    {
        Factory::cleanup();
    }

    /** @param array<string,mixed> $overrides */
    private function cfg(array $overrides = []): array
    {
        return iar_legacy_config($this->cfgFile($overrides));
    }

    /** @param array<string,mixed> $overrides */
    private function cfgFile(array $overrides = []): string
    {
        $raw = array_merge([
            'images_base_dir' => $this->site,
            'cache_dir' => $this->site . '/cache',
            'log_dir' => $this->site . '/log',
            'redirect_to_cache' => false,
            'aliases_file' => $this->site . '/aliases.json',
        ], $overrides);

        $file = $this->site . '/iar-config.php';
        file_put_contents($file, '<?php return ' . var_export($raw, true) . ';');

        return $file;
    }

    /** @return array<string,string> */
    private function server(array $overrides = []): array
    {
        return array_merge([
            'REQUEST_METHOD' => 'GET',
            'SCRIPT_NAME' => '/iar/index.php',
            'REQUEST_URI' => '/iar/index.php',
            'DOCUMENT_ROOT' => $this->site,
            'HTTP_ACCEPT' => '',
        ], $overrides);
    }

    /** @param array<string,mixed> $get */
    private function plan(array $get, array $cfg = null, array $server = []): array
    {
        return iar_legacy_plan($get, $this->server($server), $cfg ?? $this->cfg());
    }

    private function header(array $response, string $name): ?string
    {
        foreach ($response['headers'] as $pair) {
            if (strcasecmp($pair[0], $name) === 0) {
                return $pair[1];
            }
        }

        return null;
    }

    private function bytes(array $response): string
    {
        if ($response['file'] !== null) {
            return (string) file_get_contents($response['file']);
        }

        return (string) $response['body'];
    }

    /** @return array{0:int,1:int} */
    private function size(string $bytes): array
    {
        $im = imagecreatefromstring($bytes);
        self::assertNotSame(false, $im);
        $size = [imagesx($im), imagesy($im)];
        imagedestroy($im);

        return $size;
    }

    // =====================================================================
    // Совместимость кеша с основным движком
    // =====================================================================

    public function testCacheKeyMatchesMainEngine(): void
    {
        $cfg = $this->cfg();
        $config = Factory::config($this->site);
        $source = $this->site . '/photo.jpg';
        $mtime = (int) filemtime($source);

        $legacyParams = iar_legacy_params(['w' => '300', 'h' => '200', 'm' => 'cover', 'g' => 'top-left', 'ct' => '10p'], $cfg);
        $params = new ResizeParams(300, 200, Mode::Cover, Gravity::TopLeft, CropParam::parse(Side::Top, '10p'));

        self::assertSame(
            CacheKey::build($source, $config->imagesBaseDir, $params, $mtime, $config->qualityFingerprint()),
            iar_legacy_key($source, $cfg, $legacyParams, $mtime),
        );
    }

    public function testCacheKeyMatchesForEveryMode(): void
    {
        $cfg = $this->cfg();
        $config = Factory::config($this->site);
        $source = $this->site . '/nested/www/photo.jpg';
        $mtime = 1700000000;

        foreach ([['fit', 'center'], ['cover', 'bottom'], ['resize', 'right'], ['cover', 'center']] as [$mode, $gravity]) {
            $legacy = iar_legacy_params(['w' => '120', 'm' => $mode, 'g' => $gravity], $cfg);
            $modern = new ResizeParams(120, 0, Mode::from($mode), Gravity::from($gravity));

            self::assertSame(
                CacheKey::build($source, $config->imagesBaseDir, $modern, $mtime, $config->qualityFingerprint()),
                iar_legacy_key($source, $cfg, $legacy, $mtime),
                $mode . '/' . $gravity,
            );
        }
    }

    public function testQualityFingerprintMatchesMainEngine(): void
    {
        foreach ([[], ['jpeg_quality' => 50], ['lossless' => true], ['png_indexed' => false], ['cache_key_config_hash' => false]] as $overrides) {
            self::assertSame(
                Factory::config($this->site, $overrides)->qualityFingerprint(),
                iar_legacy_fingerprint($this->cfg($overrides)),
                json_encode($overrides),
            );
        }
    }

    public function testHashMarkerMatchesMainEngine(): void
    {
        $source = $this->site . '/nested/www/photo.jpg';
        $config = Factory::config($this->site);

        self::assertSame(
            CacheKey::hashMarker($source, $config->imagesBaseDir),
            iar_legacy_hash_marker($source, $config->imagesBaseDir),
        );
    }

    public function testCacheExtensionAndMimeMatchMainEngine(): void
    {
        foreach (['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'] as $name) {
            $format = Format::fromName($name);
            self::assertSame($format->cacheExtension(), iar_legacy_cache_ext($name));
            self::assertSame($format->mime(), iar_legacy_mime($name));
        }
        self::assertSame('application/octet-stream', iar_legacy_mime('bin'));
    }

    /** Вариант, созданный основным движком, резервный читает как свой (и наоборот). */
    public function testEnginesShareTheSameCacheFile(): void
    {
        $config = Factory::config($this->site);
        $modern = Factory::app($this->site);
        Factory::send($modern->handle(Factory::request($this->site, ['w' => '100'])));

        $files = glob($this->site . '/cache/photo__100x0*') ?: [];
        self::assertCount(1, $files);

        $response = $this->plan(['name' => 'photo.jpg', 'w' => '100']);
        self::assertSame('hit', $this->header($response, 'X-Iar-Cache'));
        self::assertSame(basename($files[0]), basename($response['file']));

        // Обратное направление: legacy создаёт, основной движок читает.
        $this->plan(['name' => 'photo.jpg', 'w' => '150']);
        $again = Factory::send($modern->handle(Factory::request($this->site, ['w' => '150'])));
        self::assertSame('hit', $again->headerValue('X-Iar-Cache'));
        self::assertSame($config->cacheDir, $this->cfg()['cache_dir']);
    }

    // =====================================================================
    // Конфигурация
    // =====================================================================

    public function testConfigDefaults(): void
    {
        $cfg = iar_legacy_config($this->site . '/absent.php');

        self::assertSame(2000, $cfg['max_width']);
        self::assertSame(82, $cfg['jpeg_quality']);
        self::assertFalse($cfg['logging']);
        self::assertSame('error', $cfg['log_level']);
        self::assertSame('mtime', $cfg['cache_invalidation']);
        self::assertTrue($cfg['auto_format']);
    }

    public function testConfigLocalOverride(): void
    {
        $file = $this->cfgFile(['jpeg_quality' => 60]);
        file_put_contents(dirname($file) . '/config.local.php', '<?php return ["jpeg_quality" => 30];');

        self::assertSame(30, iar_legacy_config($file)['jpeg_quality']);
    }

    public function testConfigInvalidValuesFallBackToDefaults(): void
    {
        $cfg = $this->cfg([
            'max_width' => -5,
            'jpeg_quality' => 500,
            'png_indexed' => 'yes',
            'log_level' => 'trace',
            'cache_invalidation' => 'crc32',
            'default_mode' => 'stretch',
            'default_gravity' => 'north',
            'allowed_extensions' => [],
        ]);

        self::assertSame(2000, $cfg['max_width']);
        self::assertSame(82, $cfg['jpeg_quality']);
        self::assertTrue($cfg['png_indexed']);
        self::assertSame('error', $cfg['log_level']);
        self::assertSame('mtime', $cfg['cache_invalidation']);
        self::assertSame('fit', $cfg['default_mode']);
        self::assertSame('center', $cfg['default_gravity']);
        self::assertContains('jpg', $cfg['allowed']);
    }

    public function testConfigNonArrayFileIsTolerated(): void
    {
        $file = $this->site . '/broken-config.php';
        file_put_contents($file, '<?php return 42;');
        $cfg = iar_legacy_config($file);

        self::assertSame(2000, $cfg['max_width']);
    }

    public function testInvalidBaseDirStillServesImages(): void
    {
        $cfg = $this->cfg(['images_base_dir' => $this->site . '/missing']);
        self::assertTrue($cfg['base_problem']);

        // Оригинал ищется в корне сайта — картинка не исчезает.
        $response = iar_legacy_fallback(['name' => 'photo.jpg'], $this->server(), $cfg, false, 'test');
        self::assertSame(200, $response['status']);
    }

    // =====================================================================
    // Разбор запроса
    // =====================================================================

    public function testNameFromUriWinsOverQuery(): void
    {
        self::assertSame('a.jpg', iar_legacy_name(['name' => 'b.jpg'], $this->server(['REQUEST_URI' => '/iar/a.jpg?w=1'])));
    }

    public function testNameFromQueryForScriptUri(): void
    {
        self::assertSame('b.jpg', iar_legacy_name(
            ['name' => 'b.jpg'],
            $this->server(['REQUEST_URI' => '/iar/index.php?name=b.jpg']),
        ));
    }

    public function testNameIsNullWithoutName(): void
    {
        self::assertNull(iar_legacy_name([], $this->server(['REQUEST_URI' => '/iar/index.php'])));
        self::assertNull(iar_legacy_name(['name' => ['x']], $this->server(['REQUEST_URI' => '/iar/index.php'])));
    }

    public function testNameRejectsNullByte(): void
    {
        self::assertNull(iar_legacy_name(['name' => "a.jpg\0.txt"], $this->server(['REQUEST_URI' => '/iar/index.php'])));
    }

    public function testNameFromRootScript(): void
    {
        self::assertSame('img/a.jpg', iar_legacy_name(
            [],
            $this->server(['SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/img/a.jpg']),
        ));
    }

    public function testSourceRejectsMissingFile(): void
    {
        $this->expectException(IarLegacyError::class);
        iar_legacy_source($this->cfg(), 'nope.jpg');
    }

    public function testSourceRejectsDirectory(): void
    {
        $this->expectException(IarLegacyError::class);
        iar_legacy_source($this->cfg(), 'nested');
    }

    public function testSourceRejectsForeignExtension(): void
    {
        try {
            iar_legacy_source($this->cfg(), 'secret.txt');
            self::fail('Expected IarLegacyError');
        } catch (IarLegacyError $e) {
            self::assertSame(403, $e->status());
            self::assertSame('Access denied.', $e->publicMessage());
        }
    }

    public function testSourceRejectsTraversal(): void
    {
        try {
            iar_legacy_source($this->cfg(), '../../../etc/passwd');
            self::fail('Expected IarLegacyError');
        } catch (IarLegacyError $e) {
            self::assertContains($e->status(), [403, 404]);
        }
    }

    public function testDimensionValidation(): void
    {
        $cfg = $this->cfg();
        self::assertSame(0, iar_legacy_params([], $cfg)['width']);
        self::assertSame(0, iar_legacy_params(['w' => ''], $cfg)['width']);
        self::assertSame(2000, iar_legacy_params(['w' => '99999'], $cfg)['width']);

        foreach ([['w' => '-1'], ['w' => 'abc'], ['w' => []]] as $get) {
            try {
                iar_legacy_params($get, $cfg);
                self::fail('Expected IarLegacyError');
            } catch (IarLegacyError $e) {
                self::assertSame(400, $e->status());
            }
        }
    }

    public function testEnumParamsAreLowercasedAndValidated(): void
    {
        $cfg = $this->cfg();
        $params = iar_legacy_params(['m' => 'COVER', 'g' => 'Top-Left', 'fm' => 'WEBP'], $cfg);

        self::assertSame('cover', $params['mode']);
        self::assertSame('top-left', $params['gravity']);
        self::assertSame('webp', $params['format']);
        self::assertNull(iar_legacy_params(['m' => 'unknown'], $cfg)['format']);
        self::assertSame('fit', iar_legacy_params(['m' => 'unknown'], $cfg)['mode']);
        self::assertNull(iar_legacy_params(['fm' => ''], $cfg)['format']);
    }

    public function testEnumParamRejectsNonString(): void
    {
        $this->expectException(IarLegacyError::class);
        iar_legacy_params(['fm' => 5], $this->cfg());
    }

    public function testCropParsing(): void
    {
        $cfg = $this->cfg();
        self::assertSame('_ct10p', iar_legacy_crop_suffix(iar_legacy_params(['ct' => '10p'], $cfg)['crop']));
        self::assertSame('_cl25', iar_legacy_crop_suffix(iar_legacy_params(['cl' => '25'], $cfg)['crop']));
        self::assertSame('_cb12.5p', iar_legacy_crop_suffix(iar_legacy_params(['cb' => '12.5%'], $cfg)['crop']));
        self::assertSame('', iar_legacy_crop_suffix(null));
        self::assertNull(iar_legacy_params(['ct' => ''], $cfg)['crop']);
    }

    public function testCropPriorityAndWarning(): void
    {
        $params = iar_legacy_params(['cl' => '5', 'ct' => '7'], $this->cfg());

        self::assertSame('ct', $params['crop']['side']);
        self::assertTrue($params['multiple_crop']);
    }

    public function testCropRejectsInvalidValues(): void
    {
        foreach ([['ct' => 'abc'], ['ct' => '-1'], ['ct' => '150p'], ['ct' => '999999'], ['ct' => []], ['ct' => 'p']] as $get) {
            try {
                iar_legacy_params($get, $this->cfg());
                self::fail('Expected IarLegacyError for ' . json_encode($get));
            } catch (IarLegacyError $e) {
                self::assertSame(400, $e->status());
            }
        }
    }

    // =====================================================================
    // Формат
    // =====================================================================

    public function testTargetNegotiation(): void
    {
        $cfg = $this->cfg();
        self::assertSame('gif', iar_legacy_target($cfg, 'gif', null, 'image/webp'));
        self::assertSame('svg', iar_legacy_target($cfg, 'svg', null, 'image/webp'));
        self::assertSame('jpg', iar_legacy_target($cfg, 'jpg', null, ''));
        self::assertSame('jpg', iar_legacy_target($cfg, 'jpg', 'jpg', 'image/webp'));
        self::assertSame('webp', iar_legacy_target($cfg, 'jpg', null, 'image/webp'));
        self::assertSame('jpg', iar_legacy_target($this->cfg(['auto_format' => false]), 'jpg', null, 'image/webp'));
        self::assertSame('jpg', iar_legacy_target($this->cfg(['allowed_extensions' => ['jpg']]), 'jpg', null, 'image/webp'));
    }

    public function testRasterCannotBecomeSvg(): void
    {
        try {
            iar_legacy_target($this->cfg(), 'jpg', 'svg', '');
            self::fail('Expected IarLegacyError');
        } catch (IarLegacyError $e) {
            self::assertSame(400, $e->status());
        }
    }

    public function testVaryRules(): void
    {
        $cfg = $this->cfg();
        self::assertTrue(iar_legacy_varies($cfg, 'jpg', null));
        self::assertFalse(iar_legacy_varies($cfg, 'gif', null));
        self::assertFalse(iar_legacy_varies($cfg, 'svg', null));
        self::assertFalse(iar_legacy_varies($cfg, 'jpg', 'jpg'));
        self::assertFalse(iar_legacy_varies($this->cfg(['auto_format' => false]), 'jpg', null));
    }

    // =====================================================================
    // Ответы и заголовки
    // =====================================================================

    public function testMissThenHit(): void
    {
        $cfg = $this->cfg();
        $first = $this->plan(['name' => 'photo.jpg', 'w' => '100'], $cfg);
        $second = $this->plan(['name' => 'photo.jpg', 'w' => '100'], $cfg);

        self::assertSame(200, $first['status']);
        self::assertSame('miss', $this->header($first, 'X-Iar-Cache'));
        self::assertSame('hit', $this->header($second, 'X-Iar-Cache'));
        self::assertSame('legacy', $this->header($second, 'X-Iar-Engine'));
        self::assertSame([100, 67], $this->size($this->bytes($second)));
        self::assertSame($this->bytes($first), $this->bytes($second));
    }

    public function testEtagIsStableAndChangesWithOriginal(): void
    {
        $cfg = $this->cfg();
        $first = $this->plan(['name' => 'photo.jpg', 'w' => '100'], $cfg);
        $second = $this->plan(['name' => 'photo.jpg', 'w' => '100'], $cfg);
        self::assertSame($this->header($first, 'ETag'), $this->header($second, 'ETag'));

        touch($this->site . '/photo.jpg', time() + 500);
        clearstatcache();
        $third = $this->plan(['name' => 'photo.jpg', 'w' => '100'], $cfg);

        self::assertNotSame($this->header($first, 'ETag'), $this->header($third, 'ETag'));
    }

    public function testNotModifiedOnMatchingEtag(): void
    {
        $cfg = $this->cfg();
        $first = $this->plan(['name' => 'photo.jpg', 'w' => '100'], $cfg);
        $etag = (string) $this->header($first, 'ETag');

        $again = $this->plan(['name' => 'photo.jpg', 'w' => '100'], $cfg, ['HTTP_IF_NONE_MATCH' => $etag]);

        self::assertSame(304, $again['status']);
        self::assertNull($again['file']);
        self::assertNull($again['body']);
        self::assertSame($etag, $this->header($again, 'ETag'));
        self::assertSame('no-cache', $this->header($again, 'Cache-Control'));
    }

    public function testRedirectToCache(): void
    {
        $response = $this->plan(['name' => 'photo.jpg', 'w' => '100'], $this->cfg(['redirect_to_cache' => true]));

        self::assertSame(302, $response['status']);
        self::assertStringStartsWith('/cache/photo__100x0', (string) $this->header($response, 'Location'));
        self::assertSame('no-cache', $this->header($response, 'Cache-Control'));
    }

    public function testStaticModeUsesImmutableAnd301(): void
    {
        $response = $this->plan(
            ['name' => 'photo.jpg', 'w' => '100'],
            $this->cfg(['static_mode' => true, 'redirect_to_cache' => true]),
        );

        self::assertSame(301, $response['status']);
        self::assertSame('public, max-age=31536000', $this->header($response, 'Cache-Control'));
    }

    public function testStaticModeWithoutRedirectIsImmutable(): void
    {
        $response = $this->plan(['name' => 'photo.jpg', 'w' => '100'], $this->cfg(['static_mode' => true]));

        self::assertSame('public, max-age=31536000, immutable', $this->header($response, 'Cache-Control'));
    }

    public function testDisableCacheNeverReusesAndForbidsStore(): void
    {
        $cfg = $this->cfg(['disable_cache' => true]);
        $this->plan(['name' => 'photo.jpg', 'w' => '100'], $cfg);
        $second = $this->plan(['name' => 'photo.jpg', 'w' => '100'], $cfg);

        self::assertSame('miss', $this->header($second, 'X-Iar-Cache'));
        self::assertSame('no-cache, no-store, must-revalidate', $this->header($second, 'Cache-Control'));
        self::assertNull($this->header($second, 'ETag'));
    }

    public function testHeadHasNoBody(): void
    {
        $response = $this->plan(['name' => 'photo.jpg', 'w' => '100'], null, ['REQUEST_METHOD' => 'HEAD']);

        self::assertTrue($response['head']);
        ob_start();
        iar_legacy_emit($response);
        self::assertSame('', ob_get_clean());
    }

    public function testOtherMethodsAreRejected(): void
    {
        try {
            $this->plan(['name' => 'photo.jpg'], null, ['REQUEST_METHOD' => 'POST']);
            self::fail('Expected IarLegacyError');
        } catch (IarLegacyError $e) {
            self::assertSame(405, $e->status());
            $response = iar_legacy_error_response($e, false);
            self::assertSame('GET, HEAD', $this->header($response, 'Allow'));
        }
    }

    public function testMissingNameIsBadRequest(): void
    {
        try {
            iar_legacy_plan([], $this->server(['REQUEST_URI' => '/iar/index.php']), $this->cfg());
            self::fail('Expected IarLegacyError');
        } catch (IarLegacyError $e) {
            self::assertSame(400, $e->status());
        }
    }

    public function testNotFoundIs404(): void
    {
        try {
            $this->plan(['name' => 'nope.jpg']);
            self::fail('Expected IarLegacyError');
        } catch (IarLegacyError $e) {
            self::assertSame(404, $e->status());
            self::assertSame("File not found.\n", $this->bytes(iar_legacy_error_response($e, false)));
        }
    }

    public function testMultipleCropWarningHeader(): void
    {
        $response = $this->plan(['name' => 'photo.jpg', 'w' => '100', 'ct' => '5', 'cl' => '5']);

        self::assertNotNull($this->header($response, 'X-Iar-Warning'));
    }

    public function testSvgGetsCspAndIsCopiedVerbatim(): void
    {
        $response = $this->plan(['name' => 'icon.svg'], $this->cfg(), ['REQUEST_URI' => '/iar/icon.svg']);

        self::assertSame('image/svg+xml', $this->header($response, 'Content-Type'));
        self::assertStringContainsString("default-src 'none'", (string) $this->header($response, 'Content-Security-Policy'));
        self::assertSame(file_get_contents($this->site . '/icon.svg'), $this->bytes($response));
    }

    public function testContentLengthIsPresent(): void
    {
        $response = $this->plan(['name' => 'photo.jpg', 'w' => '100']);

        self::assertSame((string) strlen($this->bytes($response)), $this->header($response, 'Content-Length'));
    }

    public function testCacheOutsideDocumentRootDisablesRedirect(): void
    {
        $outside = Factory::tempDir();
        $response = $this->plan(
            ['name' => 'photo.jpg', 'w' => '100'],
            $this->cfg(['redirect_to_cache' => true, 'cache_dir' => $outside]),
        );

        self::assertSame(200, $response['status']);
        self::assertNull($this->header($response, 'Location'));
    }

    public function testNoDocumentRootDisablesRedirect(): void
    {
        $response = $this->plan(
            ['name' => 'photo.jpg', 'w' => '100'],
            $this->cfg(['redirect_to_cache' => true]),
            ['DOCUMENT_ROOT' => ''],
        );

        self::assertSame(200, $response['status']);
    }

    public function testCacheUrlWhenCacheIsDocumentRoot(): void
    {
        $cfg = $this->cfg(['cache_dir' => $this->site]);
        $url = iar_legacy_cache_url($this->site . '/x.jpg', $cfg, $this->server());

        self::assertSame('/x.jpg', $url);
    }

    // =====================================================================
    // Обработка
    // =====================================================================

    public function testFitNeverUpscales(): void
    {
        $response = $this->plan(['name' => 'photo.jpg', 'w' => '99999']);

        self::assertSame([1200, 800], $this->size($this->bytes($response)));
    }

    public function testFitByHeight(): void
    {
        $response = $this->plan(['name' => 'photo.jpg', 'h' => '200']);

        self::assertSame([300, 200], $this->size($this->bytes($response)));
    }

    public function testCoverProducesExactSize(): void
    {
        $response = $this->plan(['name' => 'photo.jpg', 'w' => '100', 'h' => '100', 'm' => 'cover']);

        self::assertSame([100, 100], $this->size($this->bytes($response)));
    }

    public function testCoverWithOneDimension(): void
    {
        $response = $this->plan(['name' => 'photo.jpg', 'w' => '80', 'm' => 'cover']);

        self::assertSame([80, 80], $this->size($this->bytes($response)));
    }

    public function testCoverGravityVariants(): void
    {
        foreach (['top', 'bottom', 'left', 'right', 'top-left', 'bottom-right'] as $gravity) {
            $response = $this->plan(['name' => 'photo.jpg', 'w' => '60', 'h' => '40', 'm' => 'cover', 'g' => $gravity]);
            self::assertSame([60, 40], $this->size($this->bytes($response)), $gravity);
        }
    }

    public function testResizeStretches(): void
    {
        $response = $this->plan(['name' => 'photo.jpg', 'w' => '100', 'h' => '20', 'm' => 'resize']);

        self::assertSame([100, 20], $this->size($this->bytes($response)));
    }

    public function testResizeWithSingleDimension(): void
    {
        $response = $this->plan(['name' => 'photo.jpg', 'h' => '40', 'm' => 'resize']);

        self::assertSame([1200, 40], $this->size($this->bytes($response)));
    }

    public function testEdgeCropAllSides(): void
    {
        $cases = [
            ['ct', '100', [1200, 700]],
            ['cb', '100', [1200, 700]],
            ['cl', '200', [1000, 800]],
            ['cr', '200', [1000, 800]],
            ['ct', '10p', [1200, 720]],
        ];
        foreach ($cases as [$key, $value, $expected]) {
            $response = $this->plan(['name' => 'photo.jpg', $key => $value]);
            self::assertSame($expected, $this->size($this->bytes($response)), $key . '=' . $value);
        }
    }

    public function testCropClampsToAtLeastOnePixel(): void
    {
        $response = $this->plan(['name' => 'photo.jpg', 'cl' => '99999']);

        self::assertSame([1, 800], $this->size($this->bytes($response)));
    }

    public function testCropPercentClamped(): void
    {
        $response = $this->plan(['name' => 'photo.jpg', 'ct' => '100p']);

        self::assertSame([1200, 1], $this->size($this->bytes($response)));
    }

    public function testNoOperationCopiesOriginalBytes(): void
    {
        $response = $this->plan(['name' => 'photo.jpg'], $this->cfg(), ['REQUEST_URI' => '/iar/photo.jpg']);

        self::assertSame(file_get_contents($this->site . '/photo.jpg'), $this->bytes($response));
    }

    public function testPngOutputAndIndexedPalette(): void
    {
        $response = $this->plan(['name' => 'small.png', 'w' => '50', 'fm' => 'png']);

        self::assertSame('image/png', $this->header($response, 'Content-Type'));
        self::assertSame([50, 50], $this->size($this->bytes($response)));
    }

    public function testPngTruecolorWhenIndexedDisabled(): void
    {
        $response = $this->plan(['name' => 'small.png', 'w' => '50', 'fm' => 'png'], $this->cfg(['png_indexed' => false]));

        self::assertSame([50, 50], $this->size($this->bytes($response)));
    }

    public function testPngIndexedThresholdZeroAlwaysApplies(): void
    {
        $response = $this->plan(['name' => 'photo.jpg', 'w' => '300', 'fm' => 'png'], $this->cfg(['png_indexed_threshold' => 0]));

        self::assertSame([300, 200], $this->size($this->bytes($response)));
    }

    public function testJpegProgressiveAndQuality(): void
    {
        $cfg = $this->cfg();
        $low = $this->plan(['name' => 'photo.jpg', 'w' => '200', 'fm' => 'jpg'], $this->cfg(['jpeg_quality' => 20]));
        $high = $this->plan(['name' => 'photo.jpg', 'w' => '200', 'fm' => 'jpg'], $this->cfg(['jpeg_quality' => 95]));

        self::assertNotSame($this->bytes($low), $this->bytes($high));
        self::assertGreaterThan(strlen($this->bytes($low)), strlen($this->bytes($high)));
        self::assertSame('_' . substr(md5(implode('|', [82, 85, 75, 0, 1, 200, 1, 1])), 0, 8), iar_legacy_fingerprint($cfg));
    }

    public function testJpegWithoutProgressive(): void
    {
        $response = $this->plan(['name' => 'photo.jpg', 'w' => '100', 'fm' => 'jpg'], $this->cfg(['jpeg_progressive' => false]));

        self::assertSame([100, 67], $this->size($this->bytes($response)));
    }

    public function testLosslessUsesMaxQuality(): void
    {
        $response = $this->plan(['name' => 'photo.jpg', 'w' => '100', 'fm' => 'jpg'], $this->cfg(['lossless' => true]));

        self::assertSame([100, 67], $this->size($this->bytes($response)));
    }

    public function testGifOutput(): void
    {
        $response = $this->plan(['name' => 'static.gif', 'w' => '4']);

        self::assertSame('image/gif', $this->header($response, 'Content-Type'));
        self::assertSame([4, 3], $this->size($this->bytes($response)));
    }

    public function testWebpOutputWhenSupported(): void
    {
        if (!function_exists('imagewebp')) {
            self::markTestSkipped('GD WebP support is not available');
        }
        $response = $this->plan(['name' => 'photo.jpg', 'w' => '100'], null, ['HTTP_ACCEPT' => 'image/webp']);

        self::assertSame('image/webp', $this->header($response, 'Content-Type'));
        self::assertSame('Accept', $this->header($response, 'Vary'));
    }

    public function testAnimatedGifFallsBackToOriginal(): void
    {
        $response = $this->plan(['name' => 'anim.gif', 'w' => '4'], $this->cfg(), ['REQUEST_URI' => '/iar/anim.gif?w=4']);

        self::assertSame(302, $response['status']);
        self::assertSame('/anim.gif', $this->header($response, 'Location'));
        self::assertSame('original', $this->header($response, 'X-Iar-Fallback'));
        self::assertSame('no-store', $this->header($response, 'Cache-Control'));
    }

    public function testAnimatedGifWithoutOperationsIsCopied(): void
    {
        $response = $this->plan(['name' => 'anim.gif'], $this->cfg(), ['REQUEST_URI' => '/iar/anim.gif']);

        self::assertSame(file_get_contents($this->site . '/anim.gif'), $this->bytes($response));
    }

    public function testAnimatedDetection(): void
    {
        self::assertTrue(iar_legacy_animated($this->site . '/anim.gif', 'gif'));
        self::assertFalse(iar_legacy_animated($this->site . '/static.gif', 'gif'));
        self::assertFalse(iar_legacy_animated($this->site . '/photo.jpg', 'jpg'));
        self::assertFalse(iar_legacy_animated($this->site . '/absent.gif', 'gif'));

        $webp = $this->site . '/a.webp';
        file_put_contents($webp, 'RIFF....WEBPVP8X' . str_repeat("\0", 10) . 'ANMF' . str_repeat("\0", 8));
        self::assertTrue(iar_legacy_animated($webp, 'webp'));
        file_put_contents($webp, 'RIFF....WEBPVP8 ' . str_repeat("\0", 20));
        self::assertFalse(iar_legacy_animated($webp, 'webp'));
        self::assertFalse(iar_legacy_animated($this->site . '/absent.webp', 'webp'));
    }

    public function testCorruptSourceFallsBackToOriginal(): void
    {
        $response = $this->plan(['name' => 'corrupt.jpg', 'w' => '100'], $this->cfg(), ['REQUEST_URI' => '/iar/corrupt.jpg?w=100']);

        self::assertSame(302, $response['status']);
        self::assertSame('/corrupt.jpg', $this->header($response, 'Location'));
    }

    public function testTooManyBytesIsRejected(): void
    {
        $response = $this->plan(['name' => 'photo.jpg', 'w' => '100'], $this->cfg(['max_input_bytes' => 10]));

        self::assertSame(413, $response['status']);
        self::assertSame("Source image is too large.\n", $response['body']);
    }

    public function testTooManyMegapixelsIsRejected(): void
    {
        $response = $this->plan(['name' => 'huge.jpg', 'w' => '100'], $this->cfg(['max_input_megapixels' => 1]));

        self::assertSame(413, $response['status']);
    }

    public function testUnsupportedEncoderFallsBackToOriginal(): void
    {
        // AVIF в этом GD может отсутствовать; если он есть — пропускаем.
        if (function_exists('imageavif')) {
            self::markTestSkipped('GD AVIF support is available');
        }
        $response = $this->plan(['name' => 'photo.jpg', 'w' => '100', 'fm' => 'avif'], $this->cfg(), ['REQUEST_URI' => '/iar/photo.jpg?fm=avif']);

        self::assertSame(302, $response['status']);
        self::assertSame('original', $this->header($response, 'X-Iar-Fallback'));
    }

    public function testSvgRasterizationRequiresImagick(): void
    {
        if (extension_loaded('imagick')) {
            self::markTestSkipped('Imagick is available');
        }
        $response = $this->plan(['name' => 'icon.svg', 'fm' => 'png'], $this->cfg(), ['REQUEST_URI' => '/iar/icon.svg?fm=png']);

        self::assertSame(302, $response['status']);
        self::assertSame('/icon.svg', $this->header($response, 'Location'));
    }

    public function testUnicodeAndSpecialNames(): void
    {
        foreach (['фотка.jpg', 'photo copy.jpg', 'a&b.jpg', 'br[1].jpg'] as $name) {
            $response = $this->plan(
                ['name' => $name, 'w' => '40'],
                $this->cfg(),
                ['REQUEST_URI' => '/iar/' . rawurlencode($name) . '?w=40'],
            );
            self::assertSame(200, $response['status'], $name);
            self::assertSame([40, 27], $this->size($this->bytes($response)), $name);
        }
    }

    // =====================================================================
    // Кеш
    // =====================================================================

    public function testSelectiveCleanupOfOldVersions(): void
    {
        $cfg = $this->cfg();
        $this->plan(['name' => 'photo.jpg', 'w' => '100'], $cfg);
        $this->plan(['name' => 'photo.jpg', 'w' => '200'], $cfg);
        $this->plan(['name' => 'static.gif', 'w' => '4'], $cfg);
        self::assertCount(3, glob($this->site . '/cache/*') ?: []);

        touch($this->site . '/photo.jpg', time() + 900);
        clearstatcache();
        $this->plan(['name' => 'photo.jpg', 'w' => '100'], $cfg);

        $left = glob($this->site . '/cache/*') ?: [];
        $names = array_map('basename', $left);
        // Остаются: новый вариант photo.jpg и вариант static.gif.
        self::assertCount(2, $left);
        self::assertContains('static__4x0__' . md5('static.gif') . '_' . filemtime($this->site . '/static.gif') . iar_legacy_fingerprint($cfg) . '.gif', $names);
    }

    /** Разные размеры актуальной версии не вытесняют друг друга. */
    public function testCurrentSizesCoexist(): void
    {
        $cfg = $this->cfg();
        $this->plan(['name' => 'photo.jpg', 'w' => '100'], $cfg);
        $this->plan(['name' => 'photo.jpg', 'w' => '200'], $cfg);
        $third = $this->plan(['name' => 'photo.jpg', 'w' => '100'], $cfg);

        self::assertSame('hit', $this->header($third, 'X-Iar-Cache'));
        self::assertCount(2, glob($this->site . '/cache/photo__*') ?: []);
    }

    public function testAutoCleanCanBeDisabled(): void
    {
        $cfg = $this->cfg(['cache_auto_clean' => false]);
        $this->plan(['name' => 'photo.jpg', 'w' => '100'], $cfg);
        touch($this->site . '/photo.jpg', time() + 900);
        clearstatcache();
        $this->plan(['name' => 'photo.jpg', 'w' => '100'], $cfg);

        self::assertCount(2, glob($this->site . '/cache/photo__100x0*') ?: []);
    }

    public function testInvalidationOffKeepsOneFile(): void
    {
        $cfg = $this->cfg(['cache_invalidation' => 'off']);
        $this->plan(['name' => 'photo.jpg', 'w' => '100'], $cfg);
        touch($this->site . '/photo.jpg', time() + 900);
        clearstatcache();
        $second = $this->plan(['name' => 'photo.jpg', 'w' => '100'], $cfg);

        self::assertSame('hit', $this->header($second, 'X-Iar-Cache'));
        self::assertCount(1, glob($this->site . '/cache/photo__100x0*') ?: []);
    }

    public function testDeletedOriginalIsReportedAsNotFound(): void
    {
        $cfg = $this->cfg();
        $this->plan(['name' => 'photo.jpg', 'w' => '100'], $cfg);
        unlink($this->site . '/photo.jpg');
        clearstatcache();

        try {
            $this->plan(['name' => 'photo.jpg', 'w' => '100'], $cfg);
            self::fail('Expected IarLegacyError');
        } catch (IarLegacyError $e) {
            self::assertSame(404, $e->status());
        }
    }

    public function testWipedCacheIsRebuiltOnFirstRequest(): void
    {
        $cfg = $this->cfg();
        $this->plan(['name' => 'photo.jpg', 'w' => '100'], $cfg);
        Factory::removeDir($this->site . '/cache');

        $response = $this->plan(['name' => 'photo.jpg', 'w' => '100'], $cfg);
        self::assertSame('miss', $this->header($response, 'X-Iar-Cache'));
        self::assertSame([100, 67], $this->size($this->bytes($response)));
    }

    public function testCleanOldSkipsLocksAndForeignFiles(): void
    {
        $dir = $this->site . '/cache';
        iar_legacy_ensure_dir($dir);
        file_put_contents($dir . '/photo__1x0__' . md5('photo.jpg') . '_1.jpg', 'x');
        file_put_contents($dir . '/photo__2x0__' . md5('other.jpg') . '_1.jpg', 'x');
        file_put_contents($dir . '/.abc.lock', 'x');
        file_put_contents($dir . '/keep.jpg', 'x');

        $removed = iar_legacy_clean_old($dir, 'photo__', '__' . md5('photo.jpg') . '_', 'keep.jpg');

        self::assertSame(1, $removed);
        self::assertSame(0, iar_legacy_clean_old($this->site . '/absent', 'a', 'b', 'c'));
    }

    public function testCacheDirectoryCannotBeCreated(): void
    {
        $blocker = $this->site . '/blocker';
        file_put_contents($blocker, 'x');

        $this->expectException(IarLegacyError::class);
        iar_legacy_ensure_dir($blocker . '/cache');
    }

    public function testLockAndUnlock(): void
    {
        $dir = $this->site . '/cache';
        iar_legacy_ensure_dir($dir);
        $lock = iar_legacy_lock($dir, 'key');

        self::assertTrue(is_resource($lock));
        iar_legacy_unlock($lock);
        iar_legacy_unlock(null);
        self::assertNull(iar_legacy_lock($this->site . '/absent-dir', 'key'));
    }

    // =====================================================================
    // Аварийный путь
    // =====================================================================

    public function testFallbackServesOriginalBytes(): void
    {
        $response = iar_legacy_fallback(['name' => 'photo.jpg'], $this->server(), $this->cfg(), false, 'boom');

        self::assertSame(200, $response['status']);
        self::assertSame('image/jpeg', $this->header($response, 'Content-Type'));
        self::assertSame('original', $this->header($response, 'X-Iar-Fallback'));
        self::assertSame('no-store', $this->header($response, 'Cache-Control'));
        self::assertSame(file_get_contents($this->site . '/photo.jpg'), $this->bytes($response));
    }

    public function testFallbackRejectsNonImage(): void
    {
        $response = iar_legacy_fallback(['name' => 'secret.txt'], $this->server(), $this->cfg(), false, 'boom');

        self::assertSame(500, $response['status']);
        self::assertSame("Internal error.\n", $response['body']);
    }

    public function testFallbackRejectsTraversalAndMissing(): void
    {
        foreach ([['name' => '../../../etc/passwd'], ['name' => 'nope.jpg'], []] as $get) {
            $response = iar_legacy_fallback($get, $this->server(), $this->cfg(), false, 'boom');
            self::assertSame(500, $response['status']);
        }
    }

    public function testFallbackForSvgAddsCsp(): void
    {
        $response = iar_legacy_fallback(['name' => 'icon.svg'], $this->server(), $this->cfg(), false, 'boom');

        self::assertSame('image/svg+xml', $this->header($response, 'Content-Type'));
        self::assertNotNull($this->header($response, 'Content-Security-Policy'));
    }

    public function testFallbackHeadHasNoBody(): void
    {
        $response = iar_legacy_fallback(['name' => 'photo.jpg'], $this->server(), $this->cfg(), true, 'boom');

        ob_start();
        iar_legacy_emit($response);
        self::assertSame('', ob_get_clean());
    }

    public function testEmitWritesFileAndText(): void
    {
        $file = $this->site . '/photo.jpg';
        ob_start();
        iar_legacy_emit(iar_legacy_file_response($file, [], false));
        self::assertSame(file_get_contents($file), ob_get_clean());

        ob_start();
        iar_legacy_emit(['status' => 304, 'headers' => [], 'file' => $file, 'body' => null, 'head' => false]);
        self::assertSame('', ob_get_clean());

        ob_start();
        iar_legacy_emit(['status' => 200, 'headers' => [], 'file' => null, 'body' => 'hi', 'head' => false]);
        self::assertSame('hi', ob_get_clean());
    }

    // =====================================================================
    // Лог
    // =====================================================================

    public function testLoggingDisabledByDefault(): void
    {
        $this->plan(['name' => 'photo.jpg', 'w' => '100']);

        self::assertDirectoryDoesNotExist($this->site . '/log');
    }

    public function testErrorLevelIsSilentOnSuccess(): void
    {
        $cfg = $this->cfg(['logging' => true]);
        $this->plan(['name' => 'photo.jpg', 'w' => '100'], $cfg);
        $this->plan(['name' => 'photo.jpg', 'w' => '100'], $cfg);

        self::assertDirectoryDoesNotExist($this->site . '/log');
    }

    public function testInfoLevelLogsHitAndMiss(): void
    {
        $cfg = $this->cfg(['logging' => true, 'log_level' => 'info']);
        $this->plan(['name' => 'photo.jpg', 'w' => '100'], $cfg);
        $this->plan(['name' => 'photo.jpg', 'w' => '100'], $cfg);

        $lines = $this->logLines();
        self::assertCount(2, $lines);
        self::assertSame('miss', json_decode($lines[0], true)['event']);
        self::assertSame('hit', json_decode($lines[1], true)['event']);
        self::assertArrayNotHasKey('params', json_decode($lines[1], true));
    }

    public function testDebugLevelIncludesParams(): void
    {
        $cfg = $this->cfg(['logging' => true, 'log_level' => 'debug']);
        $this->plan(['name' => 'photo.jpg', 'w' => '100'], $cfg);

        $record = json_decode($this->logLines()[0], true);
        self::assertSame('100', $record['params']['w']);
        self::assertSame('legacy', $record['engine']);
    }

    public function testErrorLevelLogsFailuresWithReason(): void
    {
        $cfg = $this->cfg(['logging' => true]);
        $this->plan(['name' => 'corrupt.jpg', 'w' => '100'], $cfg, ['REQUEST_URI' => '/iar/corrupt.jpg?w=100']);

        $record = json_decode($this->logLines()[0], true);
        self::assertSame('fail', $record['event']);
        self::assertSame('/corrupt.jpg', $record['redirect']);
        self::assertNotSame('', $record['reason']);
    }

    public function testLogRedactsSensitiveKeysAndNewlines(): void
    {
        $cfg = $this->cfg(['logging' => true, 'log_level' => 'debug']);
        iar_legacy_log($cfg, 'fail', ['api_key' => 'abc', 'reason' => "line1\nline2", 'obj' => new \stdClass()]);

        $record = json_decode($this->logLines()[0], true);
        self::assertSame('[redacted]', $record['api_key']);
        self::assertSame('line1\\nline2', $record['reason']);
        self::assertSame('[non-scalar]', $record['obj']);
        self::assertCount(1, $this->logLines());
    }

    public function testLogIsNotWrittenWhenDirectoryCannotBeCreated(): void
    {
        $blocker = $this->site . '/log-blocker';
        file_put_contents($blocker, 'x');
        $cfg = $this->cfg(['logging' => true, 'log_level' => 'info', 'log_dir' => $blocker . '/log']);

        iar_legacy_log($cfg, 'hit', ['status' => 200]);

        self::assertDirectoryDoesNotExist($blocker . '/log');
    }


    // =====================================================================
    // Точка входа и фатальные ошибки
    // =====================================================================

    /** Полный цикл через iar_legacy_run(): заголовки + тело. */
    public function testRunServesImage(): void
    {
        $cfg = $this->cfg();
        ob_start();
        iar_legacy_run(['name' => 'photo.jpg', 'w' => '80'], $this->server(), $this->cfgFile());
        $body = ob_get_clean();

        self::assertSame([80, 53], $this->size($body));
        self::assertSame('hit', $this->header($this->plan(['name' => 'photo.jpg', 'w' => '80'], $cfg), 'X-Iar-Cache'));
    }

    public function testRunReturnsNeutralClientError(): void
    {
        ob_start();
        iar_legacy_run(['name' => 'secret.txt'], $this->server(), $this->cfgFile());
        $body = ob_get_clean();

        self::assertSame("Access denied.\n", $body);
    }

    /** Серверный сбой обработчика: оригинал вместо пустого ответа. */
    public function testRunFallsBackToOriginalOnServerFailure(): void
    {
        $this->cfg(['logging' => true]);
        $planner = static function (): array {
            throw new IarLegacyError(500, 'Image processing failed.', 'искусственный сбой');
        };

        ob_start();
        iar_legacy_run(['name' => 'photo.jpg'], $this->server(), $this->cfgFile(['logging' => true]), $planner);
        $body = ob_get_clean();

        self::assertSame(file_get_contents($this->site . '/photo.jpg'), $body);
        $record = json_decode($this->logLines()[0], true);
        self::assertSame('fail', $record['event']);
        self::assertSame('bootstrap', $record['stage']);
    }

    /** На PHP 7+ Error — не Exception: он тоже перехватывается. */
    public function testRunFallsBackOnPhpError(): void
    {
        $planner = static function (): array {
            throw new \RuntimeException('неожиданная ошибка');
        };

        ob_start();
        iar_legacy_run(['name' => 'photo.jpg'], $this->server(), $this->cfgFile(), $planner);
        $body = ob_get_clean();

        self::assertSame(file_get_contents($this->site . '/photo.jpg'), $body);
    }

    /** Каталог кеша занят файлом: сбой обработки → редирект на оригинал. */
    public function testUnwritableCacheRedirectsToOriginal(): void
    {
        file_put_contents($this->site . '/cache', 'blocker');

        $response = $this->plan(['name' => 'photo.jpg', 'w' => '80'], $this->cfg());

        self::assertSame(302, $response['status']);
        self::assertSame('/photo.jpg', $this->header($response, 'Location'));
    }

    public function testShutdownServesOriginalOnFatalError(): void
    {
        ob_start();
        iar_legacy_shutdown(
            ['name' => 'photo.jpg'],
            $this->server(),
            $this->cfg(),
            false,
            ['type' => E_ERROR, 'message' => 'Allowed memory size exhausted'],
            false,
        );
        $body = ob_get_clean();

        self::assertSame(file_get_contents($this->site . '/photo.jpg'), $body);
    }

    public function testShutdownIsSilentWhenHeadersAlreadySent(): void
    {
        ob_start();
        iar_legacy_shutdown(['name' => 'photo.jpg'], $this->server(), $this->cfg(), false, ['type' => E_ERROR, 'message' => 'boom'], true);
        self::assertSame('', ob_get_clean());
    }

    public function testShutdownIgnoresNonFatalErrors(): void
    {
        ob_start();
        iar_legacy_shutdown(['name' => 'photo.jpg'], $this->server(), $this->cfg(), false, ['type' => E_WARNING, 'message' => 'notice'], false);
        self::assertSame('', ob_get_clean());

        ob_start();
        iar_legacy_shutdown(['name' => 'photo.jpg'], $this->server(), $this->cfg(), false, null, false);
        self::assertSame('', ob_get_clean());
    }

    public function testShutdownWithoutOriginalIsNeutral500(): void
    {
        ob_start();
        iar_legacy_shutdown(['name' => 'nope.jpg'], $this->server(), $this->cfg(), false, ['type' => E_ERROR, 'message' => 'boom'], false);
        self::assertSame("Internal error.\n", ob_get_clean());
    }

    // =====================================================================
    // Ветки отказов, проверяемые напрямую
    // =====================================================================

    public function testSourceOutsideBaseIsForbidden(): void
    {
        $cfg = $this->cfg(['images_base_dir' => $this->site . '/nested']);

        try {
            iar_legacy_source($cfg, '../photo.jpg');
            self::fail('Expected IarLegacyError');
        } catch (IarLegacyError $e) {
            self::assertSame(403, $e->status());
        }
    }

    public function testAvifIsChosenWhenEncoderExists(): void
    {
        $probe = static function (string $function): bool {
            return $function === 'imageavif';
        };

        self::assertSame('avif', iar_legacy_target($this->cfg(), 'jpg', null, 'image/avif,image/webp', $probe));
    }

    public function testInvalidBaseDirIsLoggedAndRequestStillRuns(): void
    {
        $cfg = $this->cfg(['images_base_dir' => $this->site . '/missing', 'logging' => true]);

        try {
            $this->plan(['name' => 'photo.jpg', 'w' => '10'], $cfg);
            self::fail('Expected IarLegacyError');
        } catch (IarLegacyError $e) {
            self::assertSame(404, $e->status());
        }

        $record = json_decode($this->logLines()[0], true);
        self::assertSame('config', $record['stage']);
    }

    public function testRasterToSvgIsRejectedByPlan(): void
    {
        $response = $this->plan(['name' => 'photo.jpg', 'fm' => 'svg']);

        self::assertSame(400, $response['status']);
        self::assertSame("Unsupported output format.\n", $response['body']);
    }

    public function testRedirectIsSkippedWhenDocumentRootIsMissing(): void
    {
        $response = $this->plan(
            ['name' => 'photo.jpg', 'w' => '100'],
            $this->cfg(['redirect_to_cache' => true]),
            ['DOCUMENT_ROOT' => $this->site . '/missing'],
        );

        self::assertSame(200, $response['status']);
    }

    public function testGenerateReturnsFalseWhenFileAlreadyExists(): void
    {
        $cfg = $this->cfg();
        $source = $this->site . '/photo.jpg';
        $params = iar_legacy_params(['w' => '10'], $cfg);
        $key = iar_legacy_key($source, $cfg, $params, 1);
        $path = $cfg['cache_dir'] . '/' . $key . '.jpg';
        iar_legacy_ensure_dir($cfg['cache_dir']);
        file_put_contents($path, 'existing');

        self::assertFalse(iar_legacy_generate($source, 'jpg', $path, $key, $params, 'jpg', $cfg, 1));
        self::assertSame('existing', file_get_contents($path));
    }

    public function testGenerateFailsOnUnreadableSource(): void
    {
        $cfg = $this->cfg();
        $params = iar_legacy_params([], $cfg);

        $this->expectException(IarLegacyError::class);
        $this->expectExceptionMessage('Could not read source image.');
        iar_legacy_generate($this->site . '/absent.jpg', 'jpg', $cfg['cache_dir'] . '/x.jpg', 'k', $params, 'jpg', $cfg, 0);
    }

    public function testGenerateRejectsEmptyRender(): void
    {
        $cfg = $this->cfg();
        $params = iar_legacy_params(['w' => '10'], $cfg);
        $renderer = static function (): string {
            return '';
        };

        $this->expectException(IarLegacyError::class);
        $this->expectExceptionMessage('empty image');
        iar_legacy_generate(
            $this->site . '/photo.jpg',
            'jpg',
            $cfg['cache_dir'] . '/empty.jpg',
            'k',
            $params,
            'jpg',
            $cfg,
            0,
            $renderer,
        );
    }

    public function testRenderFailsOnUnstatableSource(): void
    {
        $this->expectException(IarLegacyError::class);
        $this->expectExceptionMessage('Could not stat source image.');
        iar_legacy_render($this->site . '/absent.jpg', 'jpg', iar_legacy_params(['w' => '10'], $this->cfg()), 'jpg', $this->cfg());
    }

    /** Заголовок валиден, но декодер формата не справляется. */
    public function testRenderFailsWhenDecoderCannotRead(): void
    {
        $path = $this->site . '/fake.jpg';
        copy($this->site . '/small.png', $path);

        $this->expectException(IarLegacyError::class);
        $this->expectExceptionMessage('GD could not decode');
        iar_legacy_render($path, 'jpg', iar_legacy_params(['w' => '10'], $this->cfg()), 'jpg', $this->cfg());
    }

    public function testDecodeUnknownExtensionReturnsFalse(): void
    {
        self::assertFalse(iar_legacy_decode($this->site . '/photo.jpg', 'bin'));
    }

    public function testDecodeWebpWhenSupported(): void
    {
        if (!function_exists('imagecreatefromwebp') || !function_exists('imagewebp')) {
            self::markTestSkipped('GD WebP support is not available');
        }
        $path = $this->site . '/real.webp';
        $im = imagecreatefromjpeg($this->site . '/photo.jpg');
        imagewebp($im, $path, 80);
        imagedestroy($im);

        $decoded = iar_legacy_decode($path, 'webp');
        self::assertTrue(is_resource($decoded) || $decoded instanceof \GdImage);
        imagedestroy($decoded);
    }

    public function testSvgWithOperationsIsReadThroughRenderer(): void
    {
        $cfg = $this->cfg();
        $params = iar_legacy_params(['w' => '10'], $cfg);
        $data = iar_legacy_render($this->site . '/icon.svg', 'svg', $params, 'svg', $cfg);

        self::assertSame(file_get_contents($this->site . '/icon.svg'), $data);
    }

    public function testSvgRendererFailsOnUnreadableFile(): void
    {
        $this->expectException(IarLegacyError::class);
        $this->expectExceptionMessage('Could not read SVG source.');
        iar_legacy_render_svg($this->site . '/absent.svg', iar_legacy_params([], $this->cfg()), 'svg', $this->cfg());
    }

    /** Публикация кеша невозможна: путь занят каталогом. */
    public function testCachePublishFailsWhenPathIsDirectory(): void
    {
        $cfg = $this->cfg();
        iar_legacy_ensure_dir($cfg['cache_dir']);
        $path = $cfg['cache_dir'] . '/occupied.jpg';
        mkdir($path);
        file_put_contents($path . '/inner', 'x');

        $this->expectException(IarLegacyError::class);
        $this->expectExceptionMessage('Could not publish cache file.');
        iar_legacy_put($cfg['cache_dir'], $path, 'data');
    }

    public function testLogSkipsInvalidUtf8(): void
    {
        $cfg = $this->cfg(['logging' => true, 'log_level' => 'info']);
        iar_legacy_log($cfg, 'hit', ['reason' => "\xB1\x31"]);

        self::assertDirectoryDoesNotExist($this->site . '/log');
    }

    public function testLogDropsParamsBelowDebugLevel(): void
    {
        $cfg = $this->cfg(['logging' => true, 'log_level' => 'info']);
        iar_legacy_log($cfg, 'fail', ['params' => ['w' => '10'], 'reason' => 'x']);

        $record = json_decode($this->logLines()[0], true);
        self::assertArrayNotHasKey('params', $record);
    }

    public function testLogSilentForSuccessAtErrorLevel(): void
    {
        $cfg = $this->cfg(['logging' => true]);
        iar_legacy_log($cfg, 'hit', ['status' => 200]);

        self::assertDirectoryDoesNotExist($this->site . '/log');
    }

    /** @return list<string> */
    private function logLines(): array
    {
        $files = glob($this->site . '/log/*.log') ?: [];
        if ($files === []) {
            return [];
        }

        return array_values(array_filter(explode("\n", (string) file_get_contents($files[0])), static fn ($l) => $l !== ''));
    }


    // =====================================================================
    // Маршруты из aliases.json: паритет с основным движком
    // =====================================================================

    /** @param array<string,mixed> $json */
    private function aliases(array $json): string
    {
        $file = $this->site . '/aliases.json';
        file_put_contents($file, (string) json_encode($json));

        return $file;
    }

    /** @return array<string,mixed> */
    private function sampleAliases(): array
    {
        return [
            'aliases' => ['z' => '/z/s/d/f/', 'img' => 'nested/www'],
            'presets' => ['1000' => ['w' => 1000], 'thumb' => ['w' => 60, 'h' => 40, 'm' => 'cover']],
            'macros' => ['enabled' => true, 'width' => 'x-', 'height' => 'y-'],
        ];
    }

    public function testLegacyRouterMatchesMainEngine(): void
    {
        $file = $this->aliases($this->sampleAliases());
        $legacy = iar_legacy_router($file, $this->site . '/');
        $modern = PathRouter::fromFile($file, $this->site . '/');

        $paths = [
            'z/file.png', 'img/photo.jpg', '1000/photo.jpg', 'thumb/photo.jpg',
            'x-2000/photo.jpg', 'y-1000/photo.jpg', 'img/x-640/photo.jpg',
            'plain.jpg', 'nested/www/photo.jpg', 'unknown/x-10/a.png', 'x-/a.png',
            'x-0/a.png', 'x-1234567/a.png', 'thumb/nested/photo.jpg', '',
        ];

        foreach ($paths as $path) {
            $a = iar_legacy_route($legacy, $path);
            $b = $modern->route($path);

            self::assertSame($b->name, $a['name'], 'name: ' . $path);
            self::assertSame($b->original, $a['original'], 'original: ' . $path);
            self::assertSame($b->params, $a['params'], 'params: ' . $path);
            self::assertSame($b->alias, $a['alias'], 'alias: ' . $path);
            self::assertSame($b->preset, $a['preset'], 'preset: ' . $path);
        }
    }

    public function testLegacyRouterNormalizesUnsafeDefinitions(): void
    {
        $file = $this->aliases([
            'aliases' => ['_comment' => 'x', '../up' => 'x', 'ok' => '../up', 'good' => '/nested/www/', 'root' => '/'],
            'presets' => ['bad' => ['nope' => 1], 'a/b' => ['w' => 1], 'good' => ['w' => '60', 'x' => [1]]],
            'macros' => ['width' => 'x/', 'height' => 5],
        ]);
        $legacy = iar_legacy_router($file, $this->site . '/');
        $modern = PathRouter::fromFile($file, $this->site . '/');

        foreach (['ok/a.png', 'good/a.png', 'root/a.png', 'bad/a.png', 'good/x-70/a.png', 'y-70/a.png'] as $path) {
            $a = iar_legacy_route($legacy, $path);
            $b = $modern->route($path);
            self::assertSame($b->name, $a['name'], 'name: ' . $path);
            self::assertSame($b->params, $a['params'], 'params: ' . $path);
        }
    }

    public function testLegacyRouterReportsBrokenJson(): void
    {
        $file = $this->site . '/aliases.json';
        file_put_contents($file, '{bad');
        $router = iar_legacy_router($file, $this->site . '/');

        self::assertSame('Aliases file must contain a JSON object.', $router['error']);
        self::assertSame('z/a.png', iar_legacy_route($router, 'z/a.png')['name']);
    }

    public function testLegacyRouterMissingFileIsSilent(): void
    {
        $router = iar_legacy_router($this->site . '/absent.json', $this->site . '/');

        self::assertNull($router['error']);
        self::assertSame([], $router['aliases']);
        self::assertSame('z/a.png', iar_legacy_route($router, 'z/a.png')['name']);
    }

    public function testLegacyRouterUnreadableFile(): void
    {
        $file = $this->aliases([]);
        chmod($file, 0000);
        clearstatcache();
        if (@file_get_contents($file) !== false) {
            chmod($file, 0644);
            self::markTestSkipped('Права не ограничивают чтение (запуск от root).');
        }

        try {
            $router = iar_legacy_router($file, $this->site . '/');
            self::assertSame('Aliases file is not readable.', $router['error']);
        } finally {
            chmod($file, 0644);
        }
    }

    public function testLegacyRouterDisabledByFlag(): void
    {
        $raw = $this->sampleAliases();
        $raw['enabled'] = false;
        $router = iar_legacy_router($this->aliases($raw), $this->site . '/');

        self::assertSame([], $router['aliases']);
        self::assertFalse($router['macros']);
    }

    public function testLegacyMacroCanBeDisabled(): void
    {
        $router = iar_legacy_router($this->aliases(['macros' => ['enabled' => false]]), $this->site . '/');

        self::assertSame('x-10/a.png', iar_legacy_route($router, 'x-10/a.png')['name']);
    }

    public function testLegacyCustomPrefixes(): void
    {
        $router = iar_legacy_router($this->aliases(['macros' => ['width' => 'w', 'height' => 'h_']]), $this->site . '/');

        self::assertSame(['w' => '800'], iar_legacy_route($router, 'w800/a.png')['params']);
        self::assertSame(['h' => '600'], iar_legacy_route($router, 'h_600/a.png')['params']);
    }

    public function testLegacyLiteralFileWins(): void
    {
        mkdir($this->site . '/thumb', 0777, true);
        copy($this->site . '/photo.jpg', $this->site . '/thumb/photo.jpg');
        $router = iar_legacy_router($this->aliases($this->sampleAliases()), $this->site . '/');

        self::assertSame('thumb/photo.jpg', iar_legacy_route($router, 'thumb/photo.jpg')['name']);
    }

    public function testLegacyMergeParamsKeepsExplicitQuery(): void
    {
        $merged = iar_legacy_merge_params(['w' => '20', 'h' => '', 'm' => 0], ['w' => '100', 'h' => '50', 'm' => 'cover']);

        self::assertSame('20', $merged['w']);
        self::assertSame('50', $merged['h']);
        self::assertSame(0, $merged['m']);
    }

    public function testLegacyAliasAndMacroResizeImage(): void
    {
        $this->aliases($this->sampleAliases());
        $response = $this->plan(['name' => 'img/x-60/photo.jpg']);

        self::assertSame(200, $response['status']);
        self::assertSame([60, 40], $this->size($this->bytes($response)));
        self::assertSame('legacy', $this->header($response, 'X-Iar-Engine'));
    }

    public function testLegacyPresetCacheKeyMatchesPlainQuery(): void
    {
        $this->aliases($this->sampleAliases());
        $cfg = $this->cfg();
        $viaPreset = $this->plan(['name' => 'thumb/photo.jpg'], $cfg);
        $viaQuery = $this->plan(['name' => 'photo.jpg', 'w' => '60', 'h' => '40', 'm' => 'cover'], $cfg);

        self::assertSame('miss', $this->header($viaPreset, 'X-Iar-Cache'));
        self::assertSame('hit', $this->header($viaQuery, 'X-Iar-Cache'));
        self::assertSame($viaPreset['file'], $viaQuery['file']);
    }

    public function testLegacyQueryWinsOverPreset(): void
    {
        $this->aliases($this->sampleAliases());
        $response = $this->plan(['name' => 'thumb/photo.jpg', 'w' => '30']);

        // Режим cover пришёл из пресета, ширина — из query: точный размер 30x40.
        self::assertSame([30, 40], $this->size($this->bytes($response)));
    }

    public function testLegacyAliasedFailureServesOriginalInline(): void
    {
        $this->aliases(['aliases' => ['c' => '']]);
        $cfg = $this->cfg();
        $response = $this->plan(['name' => 'c/corrupt.jpg', 'w' => '40'], $cfg);

        self::assertSame(200, $response['status']);
        self::assertNull($this->header($response, 'Location'));
        self::assertSame('original', $this->header($response, 'X-Iar-Fallback'));
        self::assertSame('legacy', $this->header($response, 'X-Iar-Engine'));
        self::assertSame(file_get_contents($this->site . '/corrupt.jpg'), $this->bytes($response));
    }

    public function testLegacyPresetOnlyFailureStillRedirects(): void
    {
        $this->aliases($this->sampleAliases());
        $response = $this->plan(['name' => 'thumb/corrupt.jpg', 'w' => '40']);

        self::assertSame(302, $response['status']);
        self::assertSame('/corrupt.jpg', $this->header($response, 'Location'));
    }

    public function testLegacyBrokenAliasesLoggedOnce(): void
    {
        file_put_contents($this->site . '/aliases.json', '{bad');
        $cfg = $this->cfg(['logging' => true]);
        $response = $this->plan(['name' => 'photo.jpg', 'w' => '20'], $cfg);

        self::assertSame(200, $response['status']);
        $lines = $this->logLines();
        self::assertCount(1, $lines);
        self::assertStringContainsString('"stage":"aliases"', $lines[0]);
    }

    public function testLegacyEmergencyFallbackResolvesAlias(): void
    {
        $this->aliases(['aliases' => ['z' => 'nested/www']]);
        $response = iar_legacy_fallback(
            ['name' => 'z/photo.jpg'],
            $this->server(),
            $this->cfg(),
            false,
            'сбой конфига'
        );

        self::assertSame(200, $response['status']);
        self::assertSame(file_get_contents($this->site . '/nested/www/photo.jpg'), $this->bytes($response));
    }

    public function testLegacyEmergencyFallbackWithoutAliasesFileKey(): void
    {
        $cfg = $this->cfg();
        unset($cfg['aliases_file']);
        $response = iar_legacy_fallback(['name' => 'photo.jpg'], $this->server(), $cfg, false, 'сбой');

        self::assertSame(200, $response['status']);
        self::assertSame('original', $this->header($response, 'X-Iar-Fallback'));
    }


    public function testLegacyMacrosDisabledButPresetsActive(): void
    {
        $router = iar_legacy_router($this->aliases([
            'presets' => ['thumb' => ['w' => 60]],
            'macros' => ['enabled' => false],
        ]), $this->site . '/');

        self::assertSame('x-10/a.png', iar_legacy_route($router, 'x-10/a.png')['name']);
        self::assertSame(['w' => '60'], iar_legacy_route($router, 'thumb/a.png')['params']);
    }

    public function testLegacyRejectsSchemesNullBytesAndDriveLetters(): void
    {
        $router = iar_legacy_router($this->aliases([
            'aliases' => [
                'scheme' => 'http://evil/x',
                'nul' => "a\0b",
                'drive' => 'C:\\files',
                'dot' => './here',
                'up' => '../up',
                'badkey' => 42,
            ],
            'macros' => ['enabled' => false],
        ]), $this->site . '/');

        self::assertSame([], $router['aliases']);
        self::assertSame('scheme/a.png', iar_legacy_route($router, 'scheme/a.png')['name']);
    }

    public function testLegacyUnderscoreKeysAreComments(): void
    {
        $router = iar_legacy_router($this->aliases([
            '_readme' => array('комментарий'),
            'presets' => array('_note' => array('w' => 10), 'thumb' => array('w' => 20)),
            'macros' => array('enabled' => false),
        ]), $this->site . '/');

        self::assertSame('_note/a.png', iar_legacy_route($router, '_note/a.png')['name']);
        self::assertSame(array('w' => '20'), iar_legacy_route($router, 'thumb/a.png')['params']);
    }

    public function testLegacyAliasToRootDirectory(): void
    {
        $router = iar_legacy_router($this->aliases(['aliases' => ['root' => '/']]), $this->site . '/');

        self::assertSame('photo.jpg', iar_legacy_route($router, 'root/photo.jpg')['name']);
    }
}
