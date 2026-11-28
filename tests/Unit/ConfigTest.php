<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config;
use App\Domain\Format;
use App\Domain\Gravity;
use App\Domain\Mode;
use App\Exception\ConfigException;
use App\Tests\Support\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
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

    public function testDefaultsMatchProductConcept(): void
    {
        $config = Config::fromArray(['images_base_dir' => $this->site]);

        // Концепция: лог только для отладки, по умолчанию выключен.
        self::assertFalse($config->logging);
        self::assertTrue($config->redirectToCache);
        self::assertSame(40, $config->maxInputMegapixels);
        self::assertSame(20000000, $config->maxInputBytes);
        self::assertSame(Mode::Fit, $config->defaultMode);
        self::assertSame(Gravity::Center, $config->defaultGravity);
        self::assertSame('mtime', $config->cacheInvalidation);
        self::assertTrue($config->cacheKeyConfigHash);
    }

    public function testBaseDirIsNormalizedWithTrailingSeparator(): void
    {
        $config = Factory::config($this->site);

        self::assertTrue(str_ends_with($config->imagesBaseDir, DIRECTORY_SEPARATOR));
        self::assertSame(realpath($this->site), rtrim($config->imagesBaseDir, DIRECTORY_SEPARATOR));
    }

    /** Аудит S-03: несуществующий base_dir не должен вырождаться в '/'. */
    public function testMissingBaseDirFailsFast(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('images_base_dir');

        Config::fromArray(['images_base_dir' => $this->site . '/does-not-exist']);
    }

    public function testEmptyBaseDirFailsFast(): void
    {
        $this->expectException(ConfigException::class);
        Config::fromArray([]);
    }

    public function testBaseDirPointingToFileFailsFast(): void
    {
        $this->expectException(ConfigException::class);
        Config::fromArray(['images_base_dir' => $this->site . '/photo.jpg']);
    }

    public static function invalidValues(): array
    {
        return [
            'max_width zero' => [['max_width' => 0]],
            'max_width negative' => [['max_width' => -1]],
            'max_width string' => [['max_width' => '2000']],
            'max_height bool' => [['max_height' => true]],
            'jpeg_quality low' => [['jpeg_quality' => 0]],
            'jpeg_quality high' => [['jpeg_quality' => 101]],
            'webp_quality string' => [['webp_quality' => '85']],
            'avif_quality float' => [['avif_quality' => 75.5]],
            'png_indexed string' => [['png_indexed' => 'yes']],
            'png_indexed_threshold negative' => [['png_indexed_threshold' => -1]],
            'jpeg_progressive int' => [['jpeg_progressive' => 1]],
            'webp_strip_metadata int' => [['webp_strip_metadata' => 0]],
            'default_mode unknown' => [['default_mode' => 'stretch']],
            'default_gravity unknown' => [['default_gravity' => 'middle']],
            'lossless string' => [['lossless' => 'true']],
            'auto_format string' => [['auto_format' => 'true']],
            'cache_invalidation unknown' => [['cache_invalidation' => 'daily']],
            'cache_auto_clean int' => [['cache_auto_clean' => 1]],
            'redirect_to_cache int' => [['redirect_to_cache' => 1]],
            'static_mode int' => [['static_mode' => 1]],
            'disable_cache int' => [['disable_cache' => 1]],
            'allowed_extensions string' => [['allowed_extensions' => 'jpg']],
            'allowed_extensions empty' => [['allowed_extensions' => []]],
            'allowed_extensions non-string' => [['allowed_extensions' => [123]]],
            'allowed_extensions empty-string' => [['allowed_extensions' => ['']]],
            'cache_dir empty' => [['cache_dir' => '   ']],
            'log_dir int' => [['log_dir' => 5]],
            'logging int' => [['logging' => 1]],
            'max_input_megapixels zero' => [['max_input_megapixels' => 0]],
            'max_input_bytes negative' => [['max_input_bytes' => -5]],
            'cache_key_config_hash int' => [['cache_key_config_hash' => 1]],
        ];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidConfigThrows(array $override): void
    {
        $this->expectException(ConfigException::class);
        Config::fromArray(array_merge(['images_base_dir' => $this->site], $override));
    }

    public function testExtensionsAreNormalizedAndDeduplicated(): void
    {
        $config = Factory::config($this->site, ['allowed_extensions' => ['JPG', 'jpg', 'PNG']]);

        self::assertSame(['jpg', 'png'], $config->allowedExtensions);
        self::assertTrue($config->allowsExtension('JPG'));
        self::assertFalse($config->allowsExtension('gif'));
        self::assertTrue($config->allowsFormat(Format::Png));
        self::assertFalse($config->allowsFormat(Format::Gif));
    }

    public function testPathsAreTrimmedOfTrailingSeparators(): void
    {
        $config = Factory::config($this->site, [
            'cache_dir' => $this->site . '/cache/',
            'log_dir' => $this->site . '/log/',
        ]);

        self::assertSame($this->site . '/cache', $config->cacheDir);
        self::assertSame($this->site . '/log', $config->logDir);
    }

    public function testQualityFingerprintChangesWithQuality(): void
    {
        $a = Factory::config($this->site, ['jpeg_quality' => 82])->qualityFingerprint();
        $b = Factory::config($this->site, ['jpeg_quality' => 60])->qualityFingerprint();

        self::assertNotSame($a, $b);
        self::assertStringContainsString('_', $a);
    }

    public function testQualityFingerprintDisabled(): void
    {
        $config = Factory::config($this->site, ['cache_key_config_hash' => false]);

        self::assertSame('', $config->qualityFingerprint());
    }

    public function testQualityFingerprintStableForSameSettings(): void
    {
        self::assertSame(
            Factory::config($this->site)->qualityFingerprint(),
            Factory::config($this->site)->qualityFingerprint(),
        );
    }

    public function testDefaultModeAndGravityAreParsedCaseInsensitively(): void
    {
        $config = Factory::config($this->site, ['default_mode' => 'COVER', 'default_gravity' => 'Top-Left']);

        self::assertSame(Mode::Cover, $config->defaultMode);
        self::assertSame(Gravity::TopLeft, $config->defaultGravity);
    }

    public function testCacheInvalidationOffIsAccepted(): void
    {
        self::assertSame('off', Factory::config($this->site, ['cache_invalidation' => 'off'])->cacheInvalidation);
    }

    /** Профили окружений: config.local.php переопределяет базовый конфиг. */
    public function testLoadReadsConfigFile(): void
    {
        $dir = Factory::tempDir();
        $site = Factory::tempDir();
        file_put_contents($dir . '/config.php', '<?php return ' . var_export(['images_base_dir' => $site], true) . ';');

        self::assertSame(2000, Config::load($dir . '/config.php')->maxWidth);
    }

    public function testLoadAppliesLocalOverride(): void
    {
        $dir = Factory::tempDir();
        $site = Factory::tempDir();
        file_put_contents($dir . '/config.php', '<?php return ' . var_export(['images_base_dir' => $site], true) . ';');
        file_put_contents($dir . '/config.local.php', '<?php return ["max_width" => 640, "logging" => true];');

        $config = Config::load($dir . '/config.php');
        self::assertSame(640, $config->maxWidth);
        self::assertTrue($config->logging);
    }

    public function testLoadRejectsNonArrayConfig(): void
    {
        $dir = Factory::tempDir();
        file_put_contents($dir . '/config.php', '<?php return "oops";');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('must return an array');
        Config::load($dir . '/config.php');
    }

    public function testLoadRejectsNonArrayLocalOverride(): void
    {
        $dir = Factory::tempDir();
        $site = Factory::tempDir();
        file_put_contents($dir . '/config.php', '<?php return ' . var_export(['images_base_dir' => $site], true) . ';');
        file_put_contents($dir . '/config.local.php', '<?php return 42;');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Local config must return an array');
        Config::load($dir . '/config.php');
    }

    public function testLogLevelDefaultsToError(): void
    {
        self::assertSame('error', Factory::config($this->site)->logLevel);
    }

    public function testLogLevelAcceptsInfoAndDebug(): void
    {
        self::assertSame('info', Factory::config($this->site, ['log_level' => 'info'])->logLevel);
        self::assertSame('debug', Factory::config($this->site, ['log_level' => 'debug'])->logLevel);
    }

    public function testInvalidLogLevelIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('log_level');
        Factory::config($this->site, ['log_level' => 'trace']);
    }

    public function testNonStringLogLevelIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        Factory::config($this->site, ['log_level' => 5]);
    }
}
