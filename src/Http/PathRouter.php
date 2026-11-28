<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Маршрутизация путей по JSON-конфигу (aliases.json).
 *
 * Три механизма, все настраиваются одним файлом:
 *
 *   1. Псевдонимы каталогов:  "z": "/z/s/d/f/"
 *      /iar/z/file.png  →  /z/s/d/f/file.png
 *
 *   2. Пресеты — имя ПОСЛЕДНЕЙ папки в пути как набор GET-параметров:
 *      "thumb": {"w":300,"h":300,"m":"cover"}
 *      /iar/thumb/file.png  →  file.png?w=300&h=300&m=cover
 *
 *   3. Макросы — маска последней папки, ровно один параметр:
 *      /iar/x-2000/file.png  →  file.png?w=2000
 *      /iar/y-1000/file.png  →  file.png?h=1000
 *
 * Правила, важные для политик продукта:
 *   - реальный файл важнее виртуального маршрута: если по буквальному пути
 *     файл есть, маршрут не применяется (старые ссылки не ломаются);
 *   - параметры из URL важнее параметров из пути;
 *   - значения пресетов проходят ту же валидацию, что и обычные параметры
 *     запроса, а имена параметров ограничены белым списком URL API;
 *   - цель псевдонима не может выходить за корень оригиналов;
 *   - сломанный JSON не роняет картинки: маршрутизация просто выключается,
 *     причина доступна через error() и попадает в отладочный лог.
 *
 * Класс не final и конструктор protected: файловую проверку можно
 * подменить в тестах, не трогая диск.
 */
class PathRouter
{
    /** Параметры URL API, которые разрешено задавать в пресете. */
    private const ALLOWED_PARAMS = ['w', 'h', 'm', 'g', 'fm', 'ct', 'cr', 'cb', 'cl'];

    /**
     * @param array<string,string> $aliases
     * @param array<string,array<string,string>> $presets
     */
    protected function __construct(
        private readonly array $aliases,
        private readonly array $presets,
        private readonly bool $macrosEnabled,
        private readonly string $widthPrefix,
        private readonly string $heightPrefix,
        private readonly ?string $baseDir,
        private readonly ?string $error,
    ) {
    }

    /** Маршрутизация выключена: путь используется как есть. */
    public static function disabled(): self
    {
        return new self([], [], false, 'x-', 'y-', null, null);
    }

    /**
     * Загрузка JSON. Отсутствие файла — не ошибка (функция просто выключена),
     * невалидный JSON — ошибка, но без исключения: картинки должны отдаваться.
     */
    public static function fromFile(string $file, ?string $baseDir = null): self
    {
        if (!is_file($file)) {
            return self::disabled();
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return new self([], [], false, 'x-', 'y-', $baseDir, 'Aliases file is not readable.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return new self([], [], false, 'x-', 'y-', $baseDir, 'Aliases file must contain a JSON object.');
        }

        return self::fromArray($decoded, $baseDir);
    }

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw, ?string $baseDir = null): self
    {
        if (($raw['enabled'] ?? true) !== true) {
            return self::disabled();
        }

        $aliases = [];
        foreach (self::section($raw, 'aliases') as $key => $value) {
            $name = self::normalizeAlias((string) $key);
            $target = self::normalizeTarget($value);
            if ($name !== null && $target !== null) {
                $aliases[$name] = $target;
            }
        }

        // Длинные префиксы важнее коротких: "a/b" побеждает "a".
        uksort($aliases, static fn (string $a, string $b): int => substr_count($b, '/') <=> substr_count($a, '/') ?: strcmp($a, $b));

        $presets = [];
        foreach (self::section($raw, 'presets') as $key => $value) {
            $name = (string) $key;
            if ($name === '' || str_starts_with($name, '_') || str_contains($name, '/') || !is_array($value)) {
                continue;
            }
            $params = self::normalizeParams($value);
            if ($params !== []) {
                $presets[$name] = $params;
            }
        }

        $macros = self::section($raw, 'macros');
        $macrosEnabled = ($macros['enabled'] ?? true) === true;
        $width = self::normalizePrefix($macros['width'] ?? 'x-', 'x-');
        $height = self::normalizePrefix($macros['height'] ?? 'y-', 'y-');

        return new self($aliases, $presets, $macrosEnabled, $width, $height, $baseDir, null);
    }

    /** Причина, по которой конфигурация не загружена (для отладочного лога). */
    public function error(): ?string
    {
        return $this->error;
    }

    /** Есть ли что применять вообще (чтобы не платить за разбор пути). */
    public function isEmpty(): bool
    {
        return $this->aliases === [] && $this->presets === [] && !$this->macrosEnabled;
    }

    /**
     * Разбор относительного пути. Никаких обращений к диску, если путь
     * не подходит ни под один маршрут.
     */
    public function route(string $relative): Route
    {
        // Сначала нормализуем разделители, только потом срезаем крайние
        // слэши: иначе «\z\file.png» превратится в «/z/file.png».
        $path = ltrim(str_replace('\\', '/', $relative), '/');
        // Файл в корне не может содержать ни папку-пресет, ни префикс
        // псевдонима — не тратим время на разбор (важно для cache hit).
        if ($this->isEmpty() || !str_contains($path, '/') || str_contains($path, "\0")) {
            return Route::plain($relative);
        }

        $segments = explode('/', $path);
        $params = [];
        $preset = null;

        // Виртуальной может быть только ПОСЛЕДНЯЯ папка (перед именем файла).
        if (count($segments) >= 2) {
            $folder = $segments[count($segments) - 2];
            $candidate = $this->folderParams($folder);
            if ($candidate !== null) {
                [$params, $preset] = $candidate;
                array_splice($segments, count($segments) - 2, 1);
            }
        }

        $rest = implode('/', $segments);
        [$name, $alias] = $this->applyAlias($rest);

        if ($params === [] && $alias === null) {
            return Route::plain($relative);
        }

        // Реальный файл важнее виртуального маршрута (обратная совместимость).
        if ($this->literalExists($path)) {
            return Route::plain($relative);
        }

        return Route::rewritten($name, $relative, $params, $alias, $preset);
    }

    /**
     * Параметры из имени папки: сначала пресет (явная настройка важнее
     * маски), затем макрос. Возвращает [параметры, имя пресета/маски].
     *
     * @return array{0:array<string,string>,1:string}|null
     */
    private function folderParams(string $folder): ?array
    {
        if (isset($this->presets[$folder])) {
            return [$this->presets[$folder], $folder];
        }

        if (!$this->macrosEnabled) {
            return null;
        }

        foreach ([[$this->widthPrefix, 'w'], [$this->heightPrefix, 'h']] as [$prefix, $key]) {
            if (!str_starts_with($folder, $prefix)) {
                continue;
            }
            $number = substr($folder, strlen($prefix));
            if ($number === '' || strlen($number) > 6 || !ctype_digit($number) || (int) $number < 1) {
                continue;
            }

            return [[$key => $number], $folder];
        }

        return null;
    }

    /** @return array{0:string,1:?string} */
    private function applyAlias(string $path): array
    {
        foreach ($this->aliases as $alias => $target) {
            if (str_starts_with($path, $alias . '/')) {
                return [$target . substr($path, strlen($alias) + 1), $alias];
            }
        }

        return [$path, null];
    }

    /** Существует ли файл по буквальному пути (только для маршрутов-кандидатов). */
    protected function literalExists(string $relative): bool
    {
        return $this->baseDir !== null && $this->isFile($this->baseDir . $relative);
    }

    protected function isFile(string $path): bool
    {
        return is_file($path);
    }

    /** @param array<string,mixed> $raw @return array<string,mixed> */
    private static function section(array $raw, string $key): array
    {
        $value = $raw[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    /** Имя псевдонима: без крайних слэшей, без выхода наверх, без служебных ключей. */
    private static function normalizeAlias(string $key): ?string
    {
        $key = trim(str_replace('\\', '/', $key), '/');
        if ($key === '' || str_starts_with($key, '_') || !self::isSafePath($key)) {
            return null;
        }

        return $key;
    }

    /** Цель псевдонима: каталог относительно корня оригиналов. */
    private static function normalizeTarget(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $target = trim(str_replace('\\', '/', $value), '/');
        if ($target === '') {
            return '';
        }
        if (!self::isSafePath($target)) {
            return null;
        }

        return $target . '/';
    }

    private static function isSafePath(string $path): bool
    {
        if (str_contains($path, "\0") || str_contains($path, '://') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' || $segment === '.') {
                return false;
            }
        }

        return true;
    }

    /**
     * Параметры пресета: только белый список URL API и только скаляры.
     * Значения остаются строками — их проверит обычный разбор запроса.
     *
     * @param array<string,mixed> $value
     * @return array<string,string>
     */
    private static function normalizeParams(array $value): array
    {
        $params = [];
        foreach ($value as $key => $item) {
            $key = (string) $key;
            if (!in_array($key, self::ALLOWED_PARAMS, true) || !is_scalar($item)) {
                continue;
            }
            $params[$key] = (string) $item;
        }

        return $params;
    }

    private static function normalizePrefix(mixed $value, string $default): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{1,16}$/', $value) !== 1) {
            return $default;
        }

        return $value;
    }
}
