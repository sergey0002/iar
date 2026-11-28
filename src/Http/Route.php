<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Результат разбора пути: реальный файл + параметры, вытащенные из пути.
 *
 * `$name` — путь относительно корня оригиналов после применения псевдонима
 * и вырезания виртуальной папки (пресета или макроса). `$original` — путь
 * до маршрутизации: он нужен, чтобы реальный каталог всегда побеждал
 * виртуальный маршрут (обратная совместимость ссылок).
 */
final class Route
{
    /** @param array<string,string> $params */
    private function __construct(
        public readonly string $name,
        public readonly string $original,
        public readonly array $params = [],
        public readonly ?string $alias = null,
        public readonly ?string $preset = null,
    ) {
    }

    /** Путь не переписывался. */
    public static function plain(string $name): self
    {
        return new self($name, $name);
    }

    /**
     * @param array<string,string> $params
     */
    public static function rewritten(
        string $name,
        string $original,
        array $params,
        ?string $alias,
        ?string $preset,
    ): self {
        return new self($name, $original, $params, $alias, $preset);
    }

    /** Был ли путь переписан (псевдоним, пресет или макрос). */
    public function isRewritten(): bool
    {
        return $this->alias !== null || $this->preset !== null;
    }

    /** Применён ли псевдоним каталога: реальный путь может быть непубличным. */
    public function hasAlias(): bool
    {
        return $this->alias !== null;
    }
}
