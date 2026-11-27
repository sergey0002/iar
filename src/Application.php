<?php

declare(strict_types=1);

namespace App;

use App\Domain\Format;
use App\Domain\FormatNegotiator;
use App\Domain\ResizeParams;
use App\Domain\ResizerInterface;
use App\Exception\ForbiddenException;
use App\Exception\NotFoundException;
use App\Exception\ProcessingException;
use App\Exception\UnsupportedFormatException;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\CacheKey;
use App\Infrastructure\CacheStoreInterface;
use App\Infrastructure\LoggerInterface;

/**
 * Оркестрация запроса. Не выполняет побочных эффектов ввода-вывода HTTP:
 * возвращает объект Response, который отправляется через SapiInterface.
 *
 * Исправления аудита: B-01 (fallback с причиной), B-02/H-01 (политика
 * кеш-заголовков), B-08 (URL кеша из конфига), B-14 (no-op без перекодирования),
 * S-01 (CSP для SVG), S-03 (строгая проверка пути), H-05 (HEAD).
 */
final class Application
{
    private const IMMUTABLE_MAX_AGE = 31536000;

    private readonly FormatNegotiator $negotiator;

    public function __construct(
        private readonly Config $config,
        private readonly ResizerInterface $resizer,
        private readonly CacheStoreInterface $cache,
        private readonly LoggerInterface $logger,
        ?FormatNegotiator $negotiator = null,
        private readonly ?bool $outputCompression = null,
    ) {
        $this->negotiator = $negotiator ?? new FormatNegotiator($config);
    }

    public function handle(Request $request): Response
    {
        $started = microtime(true);

        try {
            $source = $this->resolveSource($request->name);
            $sourceFormat = Format::fromName((string) pathinfo($source, PATHINFO_EXTENSION));
            if ($sourceFormat === null) {
                throw new UnsupportedFormatException('Unknown source format.');
            }

            $params = $this->buildParams($request);
            $target = $this->negotiator->negotiate($sourceFormat, $request->format, $request->accept);
            $mtime = $this->resolveMtime($source);

            $key = CacheKey::build(
                $source,
                $this->config->imagesBaseDir,
                $params,
                $mtime,
                $this->config->qualityFingerprint(),
            );
            $cachePath = $this->cache->pathFor($key, $target->cacheExtension());

            $hit = !$this->config->disableCache && $this->cache->has($cachePath);
            if (!$hit) {
                $hit = !$this->generate($source, $cachePath, $key, $params, $target, $sourceFormat, $mtime);
            }

            $response = $this->buildResponse($request, $cachePath, $target, $sourceFormat);
            $this->logRequest($request, $hit ? 'hit' : 'miss', $response->status, $source, $cachePath, $started);

            return $response;
        } catch (ProcessingException $e) {
            return $this->fallbackToOriginal($request, $e, $started);
        }
    }

    /** Строгая проверка пути (аудит S-01/S-03, path traversal). */
    private function resolveSource(string $name): string
    {
        $baseDir = $this->config->imagesBaseDir;
        $candidate = $baseDir . ltrim($name, '/\\');
        $resolved = realpath($candidate);

        if ($resolved === false) {
            throw new NotFoundException('Source file not found.');
        }
        if (!str_starts_with($resolved, $baseDir)) {
            throw new ForbiddenException('Path is outside the images base directory.');
        }
        if (!is_file($resolved)) {
            throw new NotFoundException('Source is not a file.');
        }

        $extension = strtolower((string) pathinfo($resolved, PATHINFO_EXTENSION));
        if (!$this->config->allowsExtension($extension)) {
            throw new ForbiddenException('Extension is not allowed.');
        }

        return $resolved;
    }

    private function buildParams(Request $request): ResizeParams
    {
        return new ResizeParams(
            width: min($request->width, $this->config->maxWidth),
            height: min($request->height, $this->config->maxHeight),
            mode: $request->mode ?? $this->config->defaultMode,
            gravity: $request->gravity ?? $this->config->defaultGravity,
            crop: $request->crop,
        );
    }

    private function resolveMtime(string $source): int
    {
        if ($this->config->staticMode || $this->config->cacheInvalidation !== 'mtime') {
            return 0;
        }

        $mtime = @filemtime($source);

        return $mtime === false ? 0 : $mtime;
    }

    /**
     * Генерация варианта. Возвращает true, если файл был создан этим
     * запросом, и false — если уже существовал (выиграл другой воркер).
     */
    private function generate(
        string $source,
        string $cachePath,
        string $key,
        ResizeParams $params,
        Format $target,
        Format $sourceFormat,
        int $mtime,
    ): bool {
        $this->cache->ensureDirectory();

        if ($this->config->cacheAutoClean && $mtime > 0) {
            $this->cache->cleanOldVersions(
                CacheKey::filenamePrefix($source),
                CacheKey::hashMarker($source, $this->config->imagesBaseDir),
                basename($cachePath),
            );
        }

        $lock = $this->cache->acquireLock($key);

        try {
            // Двойная проверка: пока ждали блокировку, файл мог появиться.
            if (!$this->config->disableCache && $this->cache->has($cachePath)) {
                return false;
            }

            // No-op: без операций и без смены формата не перекодируем (B-14).
            $data = (!$params->hasOperations() && $target === $sourceFormat)
                ? $this->readSource($source)
                : $this->resizer->render($source, $params, $target);

            $this->cache->put($cachePath, $data);

            return true;
        } finally {
            $this->cache->releaseLock($lock);
        }
    }

    private function readSource(string $source): string
    {
        $data = @file_get_contents($source);
        if ($data === false) {
            throw new ProcessingException('Could not read source image.');
        }

        return $data;
    }

    private function buildResponse(
        Request $request,
        string $cachePath,
        Format $target,
        Format $sourceFormat,
    ): Response {
        $headOnly = $request->isHead();
        $headers = $this->commonHeaders($target, $request);

        if ($this->config->disableCache) {
            $headers[] = ['Cache-Control', 'no-cache, no-store, must-revalidate'];
            $headers[] = ['Pragma', 'no-cache'];
            $headers[] = ['Expires', '0'];
            $headers = array_merge($headers, $this->varyHeaders($request, $sourceFormat));
            $headers = array_merge($headers, $this->contentHeaders($cachePath, $target));

            return Response::file($cachePath, $headers, $headOnly);
        }

        $etag = $this->etag($cachePath);
        $cacheControl = $this->config->staticMode
            ? 'public, max-age=' . self::IMMUTABLE_MAX_AGE . ', immutable'
            : 'no-cache';

        $vary = $this->varyHeaders($request, $sourceFormat);
        $conditional = array_merge($headers, [['ETag', $etag], ['Cache-Control', $cacheControl]], $vary);

        if ($request->ifNoneMatch !== null && trim($request->ifNoneMatch) === $etag) {
            return Response::notModified($conditional);
        }

        $redirectUrl = $this->config->redirectToCache
            ? $this->cacheUrl($cachePath, $request)
            : null;

        if ($redirectUrl !== null) {
            // На редирект не отправляем immutable: URL кеша меняется при
            // обновлении оригинала, а закешированный редирект вёл бы на 404.
            $redirectControl = $this->config->staticMode
                ? 'public, max-age=' . self::IMMUTABLE_MAX_AGE
                : 'no-cache';
            $redirectHeaders = array_merge(
                $headers,
                [['ETag', $etag], ['Cache-Control', $redirectControl]],
                $vary,
            );

            return Response::redirect(
                $redirectUrl,
                $this->config->staticMode ? 301 : 302,
                $redirectHeaders,
                $headOnly,
            );
        }

        $headers = array_merge($conditional, $this->contentHeaders($cachePath, $target));

        return Response::file($cachePath, $headers, $headOnly);
    }

    /** @return list<array{0:string,1:string}> */
    private function commonHeaders(Format $target, Request $request): array
    {
        $headers = [['X-Content-Type-Options', 'nosniff']];

        if ($request->multipleCropParams) {
            // Несколько кроп-параметров: применяется первый по приоритету ct→cr→cb→cl.
            $headers[] = ['X-Iar-Warning', 'multiple crop parameters; only the highest priority one is applied'];
        }

        if ($target === Format::Svg) {
            // SVG может содержать скрипты: запрещаем их выполнение и в PHP-пути
            // тоже, а не только при отдаче Apache (аудит S-01).
            $headers[] = ['Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox"];
        }

        return $headers;
    }

    /** @return list<array{0:string,1:string}> */
    private function varyHeaders(Request $request, Format $sourceFormat): array
    {
        return $this->negotiator->variesOnAccept($sourceFormat, $request->format)
            ? [['Vary', 'Accept']]
            : [];
    }

    /** @return list<array{0:string,1:string}> */
    private function contentHeaders(string $cachePath, Format $target): array
    {
        $headers = [['Content-Type', $target->mime()]];

        // При активном сжатии вывода длина тела не совпадёт (аудит H-04).
        if (!$this->outputCompressionActive()) {
            $size = @filesize($cachePath);
            if ($size !== false) {
                $headers[] = ['Content-Length', (string) $size];
            }
        }

        return $headers;
    }

    /**
     * Активно ли сжатие вывода. Параметр конструктора — тестовый шов:
     * ini_get('zlib.output_compression') не всегда меняется в runtime.
     */
    private function outputCompressionActive(): bool
    {
        if ($this->outputCompression !== null) {
            return $this->outputCompression;
        }

        $value = ini_get('zlib.output_compression');

        return $value !== false && $value !== '' && $value !== '0';
    }

    private function etag(string $path): string
    {
        $mtime = @filemtime($path);
        $size = @filesize($path);

        // Разделитель исключает коллизию склейки (аудит B-19/H-06).
        return '"' . md5(($mtime === false ? 0 : $mtime) . ':' . ($size === false ? 0 : $size)) . '"';
    }

    /**
     * URL кеш-файла вычисляется из реального cache_dir относительно
     * DOCUMENT_ROOT (аудит B-08). Вне webroot — редирект невозможен.
     */
    private function cacheUrl(string $cachePath, Request $request): ?string
    {
        $documentRoot = $request->documentRoot;
        if ($documentRoot === '') {
            return null;
        }

        $rootReal = realpath($documentRoot);
        $cacheReal = realpath($this->cache->directory());
        if ($rootReal === false || $cacheReal === false) {
            return null;
        }

        $root = rtrim(str_replace('\\', '/', $rootReal), '/') . '/';
        $cacheDir = str_replace('\\', '/', $cacheReal);

        if (!str_starts_with($cacheDir . '/', $root)) {
            return null;
        }

        $relative = substr($cacheDir, strlen($root));

        return '/' . ($relative === '' ? '' : $relative . '/') . basename($cachePath);
    }

    /** Fallback на оригинал с явной причиной (аудит B-01, L-06). */
    private function fallbackToOriginal(Request $request, ProcessingException $e, float $started): Response
    {
        $parentDir = rtrim(dirname($request->scriptDir()), '/');
        $url = $parentDir . '/' . ltrim($request->name, '/\\');

        $this->logger->log('fail', [
            'reason' => $e->reason(),
            'status' => 302,
            'url' => $request->requestUri,
            'redirect' => $url,
            'duration_ms' => $this->elapsedMs($started),
        ]);

        return Response::redirect($url, 302, [
            ['Cache-Control', 'no-store'],
            ['X-Content-Type-Options', 'nosniff'],
        ], $request->isHead());
    }

    private function logRequest(
        Request $request,
        string $event,
        int $status,
        string $source,
        string $cachePath,
        float $started,
    ): void {
        $origSize = @filesize($source);
        $newSize = @filesize($cachePath);
        $orig = $origSize === false ? 0 : $origSize;
        $new = $newSize === false ? 0 : $newSize;

        $this->logger->log($event, [
            'status' => $status,
            'url' => $request->requestUri,
            'params' => $request->query,
            'orig_bytes' => $orig,
            'new_bytes' => $new,
            'saved_pct' => $orig > 0 ? round(($orig - $new) / $orig * 100, 2) : 0.0,
            'duration_ms' => $this->elapsedMs($started),
        ]);
    }

    private function elapsedMs(float $started): float
    {
        return round((microtime(true) - $started) * 1000, 2);
    }
}
