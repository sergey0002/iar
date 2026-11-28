<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\CropParam;
use App\Domain\Format;
use App\Domain\Gravity;
use App\Domain\Mode;
use App\Domain\Side;
use App\Exception\BadRequestException;
use App\Exception\MethodNotAllowedException;

/**
 * Разбор и валидация входных параметров (аудит B-11, B-13, S-06).
 *
 * Путь к изображению берётся из URI, а не из $_GET['name'] — это
 * исключает перезапись пути пользовательским query-параметром (S-06).
 * Прямой вызов index.php?name=... остаётся поддерживаемым.
 */
final class Request
{
    private function __construct(
        public readonly string $name,
        public readonly int $width,
        public readonly int $height,
        public readonly ?Mode $mode,
        public readonly ?Gravity $gravity,
        public readonly ?Format $format,
        public readonly ?CropParam $crop,
        public readonly bool $multipleCropParams,
        public readonly string $method,
        public readonly string $accept,
        public readonly ?string $ifNoneMatch,
        public readonly string $requestUri,
        public readonly string $scriptName,
        public readonly string $documentRoot,
        /** Сырой query-набор как пришёл от клиента. */
        public readonly array $query,
        /** Итоговые параметры: query поверх параметров из пути. */
        public readonly array $params,
        public readonly Route $route,
    ) {
    }

    /**
     * @param array<string,mixed> $get
     * @param array<string,mixed> $server
     * @param PathRouter|null $router маршруты из aliases.json (псевдонимы,
     *                                пресеты, макросы последней папки)
     */
    public static function fromArray(array $get, array $server, ?PathRouter $router = null): self
    {
        $method = strtoupper(self::scalarString($server, 'REQUEST_METHOD', 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') {
            throw new MethodNotAllowedException('Only GET and HEAD are supported.');
        }

        $scriptName = self::scalarString($server, 'SCRIPT_NAME', '/index.php');
        $requestUri = self::scalarString($server, 'REQUEST_URI', '');
        $documentRoot = self::scalarString($server, 'DOCUMENT_ROOT', '');

        $name = self::resolveName($get, $scriptName, $requestUri);
        $route = ($router ?? PathRouter::disabled())->route($name);

        // Параметры из пути — это значения по умолчанию: явный ?w= всегда важнее.
        $params = self::mergeParams($get, $route->params);

        [$crop, $multiple] = self::resolveCrop($params);

        return new self(
            name: $route->name,
            width: self::dimension($params, 'w'),
            height: self::dimension($params, 'h'),
            mode: self::enumParam($params, 'm', Mode::fromName(...)),
            gravity: self::enumParam($params, 'g', Gravity::fromName(...)),
            format: self::enumParam($params, 'fm', Format::fromName(...)),
            crop: $crop,
            multipleCropParams: $multiple,
            method: $method,
            accept: self::scalarString($server, 'HTTP_ACCEPT', ''),
            ifNoneMatch: isset($server['HTTP_IF_NONE_MATCH']) && is_string($server['HTTP_IF_NONE_MATCH'])
                ? $server['HTTP_IF_NONE_MATCH']
                : null,
            requestUri: $requestUri,
            scriptName: $scriptName,
            documentRoot: $documentRoot,
            query: $get,
            params: $params,
            route: $route,
        );
    }

    /**
     * Параметры пути не перекрывают явные параметры запроса; пустое
     * значение в запросе («?w=») считается отсутствующим.
     *
     * @param array<string,mixed> $get
     * @param array<string,string> $path
     * @return array<string,mixed>
     */
    private static function mergeParams(array $get, array $path): array
    {
        foreach ($path as $key => $value) {
            $current = $get[$key] ?? null;
            if (!array_key_exists($key, $get) || (is_string($current) && trim($current) === '')) {
                $get[$key] = $value;
            }
        }

        return $get;
    }

    public function isHead(): bool
    {
        return $this->method === 'HEAD';
    }

    /** Директория скрипта в URL (например "/iar"). */
    public function scriptDir(): string
    {
        $dir = str_replace('\\', '/', dirname($this->scriptName));

        return rtrim($dir, '/');
    }

    /**
     * Путь из URI имеет приоритет над query-параметром name (аудит S-06).
     *
     * @param array<string,mixed> $get
     */
    private static function resolveName(array $get, string $scriptName, string $requestUri): string
    {
        $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        $path = parse_url($requestUri, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '';

        $relative = '';
        if ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($path, $scriptDir . '/')) {
            $relative = substr($path, strlen($scriptDir) + 1);
        } elseif ($path !== '') {
            $relative = ltrim($path, '/');
        }

        // Если URI указывает на файл изображения — используем его, игнорируя ?name=
        if ($relative !== '' && !str_ends_with(strtolower($relative), '.php') && str_contains($relative, '.')) {
            return $relative;
        }

        $raw = $get['name'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            throw new BadRequestException("Parameter 'name' is required and must be a string.");
        }

        return $raw;
    }

    /**
     * @param array<string,mixed> $get
     * @return array{0:?CropParam,1:bool}
     */
    private static function resolveCrop(array $get): array
    {
        $found = 0;
        $crop = null;

        foreach (Side::priority() as $side) {
            $key = $side->value;
            if (!array_key_exists($key, $get)) {
                continue;
            }
            $raw = $get[$key];
            if (!is_string($raw) && !is_int($raw) && !is_float($raw)) {
                throw new BadRequestException(sprintf('Parameter "%s" must be scalar.', $key));
            }
            $raw = (string) $raw;
            if (trim($raw) === '') {
                continue;
            }
            $found++;
            if ($crop === null) {
                $crop = CropParam::parse($side, $raw);
            }
        }

        return [$crop, $found > 1];
    }

    /** @param array<string,mixed> $get */
    private static function dimension(array $get, string $key): int
    {
        if (!array_key_exists($key, $get)) {
            return 0;
        }

        $raw = $get[$key];
        if (!is_string($raw) && !is_int($raw)) {
            throw new BadRequestException(sprintf('Parameter "%s" must be an integer.', $key));
        }
        if (is_string($raw) && trim($raw) === '') {
            return 0;
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if ($value === false) {
            throw new BadRequestException(sprintf('Parameter "%s" must be an integer.', $key));
        }
        if ($value < 0) {
            throw new BadRequestException(sprintf('Parameter "%s" must not be negative.', $key));
        }

        return $value;
    }

    /**
     * @template T
     * @param array<string,mixed> $get
     * @param callable(string):?T $factory
     * @return ?T
     */
    private static function enumParam(array $get, string $key, callable $factory): mixed
    {
        if (!array_key_exists($key, $get)) {
            return null;
        }
        $raw = $get[$key];
        if (!is_string($raw)) {
            throw new BadRequestException(sprintf('Parameter "%s" must be a string.', $key));
        }
        if (trim($raw) === '') {
            return null;
        }

        return $factory($raw);
    }

    /** @param array<string,mixed> $server */
    private static function scalarString(array $server, string $key, string $default): string
    {
        $value = $server[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }
}
