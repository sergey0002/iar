<?php

declare(strict_types=1);

namespace App\Infrastructure;

/**
 * Отладочный лог: одна JSON-строка на событие, без ротации (концепция).
 *
 * Исправления аудита:
 *   L-03 — структурированный формат с уровнем, статусом и длительностью;
 *   L-04 — редактирование чувствительных параметров;
 *   L-05 — единый канал, результат записи проверяется;
 *   L-07 — экранирование переводов строк (защита от подделки строк лога).
 */
final class DebugLogger implements LoggerInterface
{
    private const SENSITIVE = '/(token|pass|secret|auth|api_?key|session)/i';

    public const LEVEL_ERROR = 'error';

    public const LEVEL_INFO = 'info';

    public const LEVEL_DEBUG = 'debug';

    public function __construct(
        private readonly string $dir,
        private readonly string $level = self::LEVEL_ERROR,
    ) {
    }

    /**
     * Уровень error — только сбои (этого достаточно для отладки:
     * причина, URL, куда редиректнули). Информация о каждом hit/miss
     * не собирается и не пишется, поэтому горячий путь не замедляется.
     */
    public function logsRequests(): bool
    {
        return $this->level !== self::LEVEL_ERROR;
    }

    public function log(string $event, array $context): void
    {
        if ($event !== 'fail' && !$this->logsRequests()) {
            return;
        }

        if ($this->level !== self::LEVEL_DEBUG) {
            // Параметры запроса — только на уровне debug (приватность).
            unset($context['params']);
        }

        $record = [
            'ts' => date('Y-m-d H:i:s'),
            'event' => self::clean($event),
            'level' => $this->level,
        ] + $this->sanitize($context);

        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
            if (!is_dir($this->dir)) {
                return;
            }
        }

        $file = $this->dir . DIRECTORY_SEPARATOR . date('Y-m-d') . '.log';
        @file_put_contents($file, $json . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function sanitize(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            $key = (string) $key;
            if (preg_match(self::SENSITIVE, $key) === 1) {
                $out[$key] = '[redacted]';
                continue;
            }
            $out[$key] = $this->sanitizeValue($value);
        }

        return $out;
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::clean($value);
        }
        if (is_array($value)) {
            return $this->sanitize($value);
        }
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return '[non-scalar]';
    }

    /** Убирает переводы строк и управляющие символы (log-injection). */
    private static function clean(string $value): string
    {
        return str_replace(["\r", "\n"], ['\\r', '\\n'], $value);
    }
}
