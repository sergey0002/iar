<?php

declare(strict_types=1);

/**
 * Сборщик покрытия для портативного режима (без Xdebug/PCOV).
 *
 * Инструментированный код вызывает IarCoverage::hit() в начале каждого
 * оператора; при завершении процесса накопленные строки сбрасываются
 * в каталог.hits отдельным JSON-файлом. Отчёт сводит все процессы
 * (юнит-раннер + интеграционные запросы) воедино.
 */
final class IarCoverage
{
    /** @var array<string,array<int,bool>> */
    private static array $hits = [];

    private static string $target = '';

    public static function enable(string $hitsDir): void
    {
        self::$target = $hitsDir;
        register_shutdown_function(static function (): void {
            self::dump();
        });
    }

    public static function hit(string $file, int $line): void
    {
        self::$hits[$file][$line] = true;
    }

    public static function dump(): void
    {
        if (self::$target === '' || self::$hits === []) {
            return;
        }

        if (!is_dir(self::$target)) {
            @mkdir(self::$target, 0777, true);
        }

        $lines = [];
        foreach (self::$hits as $file => $set) {
            $lines[$file] = array_keys($set);
        }

        @file_put_contents(
            rtrim(self::$target, '/') . '/' . bin2hex(random_bytes(8)) . '.json',
            (string) json_encode($lines),
        );
    }
}
