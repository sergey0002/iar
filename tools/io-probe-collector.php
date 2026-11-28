<?php

declare(strict_types=1);

/**
 * Сборщик счётчиков ввода-вывода для tools/io-probe.php.
 * Каждый вызов файловой функции в инструментированном коде проходит
 * через __callStatic(), который увеличивает счётчик и вызывает оригинал.
 */
final class IarProbe
{
    /** @var array<string,int> */
    private static array $counts = [];

    private static string $target = '';

    /** @var list<string> */
    private static array $trace = [];

    private static bool $tracing = false;

    public static function enable(string $hitsDir, bool $trace = false): void
    {
        self::$target = $hitsDir;
        self::$tracing = $trace;
        register_shutdown_function(static function (): void {
            self::dump();
        });
    }

    /** @param list<mixed> $args */
    public static function __callStatic(string $name, array $args): mixed
    {
        self::$counts[$name] = (self::$counts[$name] ?? 0) + 1;

        if (self::$tracing) {
            $first = $args[0] ?? '';
            self::$trace[] = $name . '(' . (is_string($first) ? $first : get_debug_type($first)) . ')';
        }

        return $name(...$args);
    }

    public static function dump(): void
    {
        if (self::$target === '') {
            return;
        }
        if (!is_dir(self::$target)) {
            @mkdir(self::$target, 0777, true);
        }

        @file_put_contents(
            rtrim(self::$target, '/') . '/' . bin2hex(random_bytes(8)) . '.json',
            (string) json_encode(self::$tracing ? ['counts' => self::$counts, 'trace' => self::$trace] : self::$counts),
        );
    }
}
