<?php

declare(strict_types=1);

namespace App;

use App\Domain\Format;
use App\Domain\Gravity;
use App\Domain\Mode;
use App\Exception\ConfigException;

/**
 * Типизированный конфиг с валидацией (аудит A-04, C-01, O-07).
 *
 * Дефолты соответствуют концепции продукта:
 *   - logging = false (лог только для отладки);
 *   - кеш полностью удаляемый, пересоздаётся по первому URL.
 *
 * Некорректный конфиг приводит к ConfigException (fail-fast), а не к
 * тихой деградации защиты (аудит S-03).
 */
final class Config
{
    /** @param list<string> $allowedExtensions */
    private function __construct(
        public readonly int $maxWidth,
        public readonly int $maxHeight,
        public readonly int $jpegQuality,
        public readonly int $webpQuality,
        public readonly int $avifQuality,
        public readonly bool $pngIndexed,
        public readonly int $pngIndexedThreshold,
        public readonly bool $jpegProgressive,
        public readonly bool $webpStripMetadata,
        public readonly Mode $defaultMode,
        public readonly Gravity $defaultGravity,
        public readonly bool $lossless,
        public readonly bool $autoFormat,
        public readonly string $cacheInvalidation,
        public readonly bool $cacheAutoClean,
        public readonly bool $redirectToCache,
        public readonly bool $staticMode,
        public readonly bool $disableCache,
        public readonly string $imagesBaseDir,
        public readonly array $allowedExtensions,
        public readonly string $cacheDir,
        public readonly string $logDir,
        public readonly bool $logging,
        public readonly string $logLevel,
        public readonly int $maxInputMegapixels,
        public readonly int $maxInputBytes,
        public readonly bool $cacheKeyConfigHash,
        public readonly string $aliasesFile,
    ) {
    }

    /**
     * Загрузка конфига с необязательным локальным переопределением
     * (аудит C-05: профили окружений без фреймворков).
     *
     * config.local.php кладётся рядом и не коммитится — так одна и та же
     * папка переносится между боевым и отладочным окружением.
     */
    public static function load(string $file): self
    {
        $raw = require $file;
        if (!is_array($raw)) {
            throw new ConfigException('Config file must return an array: ' . $file);
        }

        $local = dirname($file) . '/config.local.php';
        if (is_file($local)) {
            $override = require $local;
            if (!is_array($override)) {
                throw new ConfigException('Local config must return an array: ' . $local);
            }
            $raw = array_merge($raw, $override);
        }

        return self::fromArray($raw);
    }

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $baseDirRaw = self::string($raw, 'images_base_dir', '');
        $resolved = $baseDirRaw === '' ? false : realpath($baseDirRaw);
        if ($resolved === false || !is_dir($resolved)) {
            // Fail-fast вместо fail-open: иначе baseDir выродился бы в '/'
            throw new ConfigException('images_base_dir does not exist or is not a directory.');
        }
        $baseDir = rtrim($resolved, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        $extensions = $raw['allowed_extensions'] ?? ['jpg', 'jpeg', 'gif', 'png', 'webp', 'avif', 'svg'];
        if (!is_array($extensions) || $extensions === []) {
            throw new ConfigException('allowed_extensions must be a non-empty array.');
        }
        $normalized = [];
        foreach ($extensions as $ext) {
            if (!is_string($ext) || $ext === '') {
                throw new ConfigException('allowed_extensions must contain non-empty strings.');
            }
            $normalized[] = strtolower($ext);
        }

        $mode = Mode::fromName(self::string($raw, 'default_mode', 'fit'));
        if ($mode === null) {
            throw new ConfigException('default_mode is invalid.');
        }
        $gravity = Gravity::fromName(self::string($raw, 'default_gravity', 'center'));
        if ($gravity === null) {
            throw new ConfigException('default_gravity is invalid.');
        }

        $logLevel = self::string($raw, 'log_level', 'error');
        if (!in_array($logLevel, ['error', 'info', 'debug'], true)) {
            throw new ConfigException('log_level must be "error", "info" or "debug".');
        }

        $invalidation = self::string($raw, 'cache_invalidation', 'mtime');
        if (!in_array($invalidation, ['mtime', 'off'], true)) {
            throw new ConfigException('cache_invalidation must be "mtime" or "off".');
        }

        return new self(
            maxWidth: self::positiveInt($raw, 'max_width', 2000),
            maxHeight: self::positiveInt($raw, 'max_height', 2000),
            jpegQuality: self::quality($raw, 'jpeg_quality', 82),
            webpQuality: self::quality($raw, 'webp_quality', 85),
            avifQuality: self::quality($raw, 'avif_quality', 75),
            pngIndexed: self::bool($raw, 'png_indexed', true),
            pngIndexedThreshold: self::nonNegativeInt($raw, 'png_indexed_threshold', 200),
            jpegProgressive: self::bool($raw, 'jpeg_progressive', true),
            webpStripMetadata: self::bool($raw, 'webp_strip_metadata', true),
            defaultMode: $mode,
            defaultGravity: $gravity,
            lossless: self::bool($raw, 'lossless', false),
            autoFormat: self::bool($raw, 'auto_format', true),
            cacheInvalidation: $invalidation,
            cacheAutoClean: self::bool($raw, 'cache_auto_clean', true),
            redirectToCache: self::bool($raw, 'redirect_to_cache', true),
            staticMode: self::bool($raw, 'static_mode', false),
            disableCache: self::bool($raw, 'disable_cache', false),
            imagesBaseDir: $baseDir,
            allowedExtensions: array_values(array_unique($normalized)),
            cacheDir: self::path($raw, 'cache_dir', __DIR__ . '/../cache'),
            logDir: self::path($raw, 'log_dir', __DIR__ . '/../log'),
            logging: self::bool($raw, 'logging', false),
            logLevel: $logLevel,
            maxInputMegapixels: self::positiveInt($raw, 'max_input_megapixels', 40),
            maxInputBytes: self::positiveInt($raw, 'max_input_bytes', 20000000),
            cacheKeyConfigHash: self::bool($raw, 'cache_key_config_hash', true),
            aliasesFile: self::string($raw, 'aliases_file', __DIR__ . '/../aliases.json'),
        );
    }

    /** Разрешён ли формат оригинала конфигурацией. */
    public function allowsExtension(string $ext): bool
    {
        return in_array(strtolower($ext), $this->allowedExtensions, true);
    }

    /** Разрешён ли формат как цель авто-конвертации. */
    public function allowsFormat(Format $format): bool
    {
        return $this->allowsExtension($format->value);
    }

    /**
     * Отпечаток качество-влияющих настроек: смена качества инвалидирует кеш
     * (аудит: ранее смена качества не влияла на кеш-ключ).
     */
    public function qualityFingerprint(): string
    {
        if (!$this->cacheKeyConfigHash) {
            return '';
        }

        return '_' . substr(md5(implode('|', [
            $this->jpegQuality, $this->webpQuality, $this->avifQuality,
            (int) $this->lossless, (int) $this->pngIndexed, $this->pngIndexedThreshold,
            (int) $this->jpegProgressive, (int) $this->webpStripMetadata,
        ])), 0, 8);
    }

    /** @param array<string,mixed> $raw */
    private static function string(array $raw, string $key, string $default): string
    {
        $value = $raw[$key] ?? $default;
        if (!is_string($value)) {
            throw new ConfigException(sprintf('Config key "%s" must be a string.', $key));
        }

        return $value;
    }

    /** @param array<string,mixed> $raw */
    private static function bool(array $raw, string $key, bool $default): bool
    {
        $value = $raw[$key] ?? $default;
        if (!is_bool($value)) {
            throw new ConfigException(sprintf('Config key "%s" must be a boolean.', $key));
        }

        return $value;
    }

    /** @param array<string,mixed> $raw */
    private static function positiveInt(array $raw, string $key, int $default): int
    {
        $value = $raw[$key] ?? $default;
        if (!is_int($value) || $value <= 0) {
            throw new ConfigException(sprintf('Config key "%s" must be a positive integer.', $key));
        }

        return $value;
    }

    /** @param array<string,mixed> $raw */
    private static function nonNegativeInt(array $raw, string $key, int $default): int
    {
        $value = $raw[$key] ?? $default;
        if (!is_int($value) || $value < 0) {
            throw new ConfigException(sprintf('Config key "%s" must be a non-negative integer.', $key));
        }

        return $value;
    }

    /** @param array<string,mixed> $raw */
    private static function quality(array $raw, string $key, int $default): int
    {
        $value = $raw[$key] ?? $default;
        if (!is_int($value) || $value < 1 || $value > 100) {
            throw new ConfigException(sprintf('Config key "%s" must be an integer in 1..100.', $key));
        }

        return $value;
    }

    /** @param array<string,mixed> $raw */
    private static function path(array $raw, string $key, string $default): string
    {
        $value = self::string($raw, $key, $default);
        if (trim($value) === '') {
            throw new ConfigException(sprintf('Config key "%s" must not be empty.', $key));
        }

        return rtrim($value, '/\\');
    }
}
