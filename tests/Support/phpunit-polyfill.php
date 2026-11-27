<?php

declare(strict_types=1);

/**
 * Минимальная замена PHPUnit для портабельного запуска тестов.
 *
 * Подключается ТОЛЬКО раннером tests/run.php и только если настоящий
 * PHPUnit не установлен. В CI и у разработчика с composer используется
 * настоящий PHPUnit — тесты написаны под его API и не меняются.
 *
 * Это сохраняет концепцию продукта: набор тестов запускается даже там,
 * где нет composer (`php tests/run.php`).
 */

namespace PHPUnit\Framework {
    class AssertionFailedError extends \RuntimeException
    {
    }

    class SkippedTestError extends \RuntimeException
    {
    }

    abstract class TestCase
    {
        public static int $assertionCount = 0;

        private ?string $expectedException = null;

        private ?string $expectedExceptionMessage = null;

        protected function setUp(): void
        {
        }

        protected function tearDown(): void
        {
        }

        public function runSetUp(): void
        {
            $this->setUp();
        }

        public function runTearDown(): void
        {
            $this->tearDown();
        }

        public function expectedExceptionClass(): ?string
        {
            return $this->expectedException;
        }

        public function expectedExceptionMessageText(): ?string
        {
            return $this->expectedExceptionMessage;
        }

        public function expectException(string $class): void
        {
            $this->expectedException = $class;
        }

        public function expectExceptionMessage(string $message): void
        {
            $this->expectedExceptionMessage = $message;
        }

        public static function assertTrue(mixed $value, string $message = ''): void
        {
            self::$assertionCount++;
            if ($value !== true) {
                throw new AssertionFailedError($message ?: 'Expected true, got ' . self::export($value));
            }
        }

        public static function assertFalse(mixed $value, string $message = ''): void
        {
            self::$assertionCount++;
            if ($value !== false) {
                throw new AssertionFailedError($message ?: 'Expected false, got ' . self::export($value));
            }
        }

        public static function assertSame(mixed $expected, mixed $actual, string $message = ''): void
        {
            self::$assertionCount++;
            if ($expected !== $actual) {
                throw new AssertionFailedError(
                    ($message ?: 'Values are not identical')
                    . ': expected ' . self::export($expected) . ', got ' . self::export($actual),
                );
            }
        }

        public static function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
        {
            self::$assertionCount++;
            if ($expected === $actual) {
                throw new AssertionFailedError($message ?: 'Values are identical but should differ');
            }
        }

        public static function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
        {
            self::$assertionCount++;
            if ($expected != $actual) {
                throw new AssertionFailedError(
                    ($message ?: 'Values are not equal')
                    . ': expected ' . self::export($expected) . ', got ' . self::export($actual),
                );
            }
        }

        public static function assertNull(mixed $value, string $message = ''): void
        {
            self::$assertionCount++;
            if ($value !== null) {
                throw new AssertionFailedError($message ?: 'Expected null, got ' . self::export($value));
            }
        }

        public static function assertNotNull(mixed $value, string $message = ''): void
        {
            self::$assertionCount++;
            if ($value === null) {
                throw new AssertionFailedError($message ?: 'Expected non-null value');
            }
        }

        public static function assertCount(int $expected, mixed $haystack, string $message = ''): void
        {
            self::$assertionCount++;
            $actual = is_countable($haystack) ? count($haystack) : -1;
            if ($actual !== $expected) {
                throw new AssertionFailedError($message ?: "Expected count {$expected}, got {$actual}");
            }
        }

        public static function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
        {
            self::$assertionCount++;
            if (!str_contains($haystack, $needle)) {
                throw new AssertionFailedError($message ?: "String does not contain '{$needle}'");
            }
        }

        public static function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
        {
            self::$assertionCount++;
            if (str_contains($haystack, $needle)) {
                throw new AssertionFailedError($message ?: "String unexpectedly contains '{$needle}'");
            }
        }

        public static function assertInstanceOf(string $class, mixed $value, string $message = ''): void
        {
            self::$assertionCount++;
            if (!$value instanceof $class) {
                throw new AssertionFailedError($message ?: "Expected instance of {$class}");
            }
        }

        public static function assertGreaterThan(mixed $expected, mixed $actual, string $message = ''): void
        {
            self::$assertionCount++;
            if (!($actual > $expected)) {
                throw new AssertionFailedError($message ?: self::export($actual) . " is not > " . self::export($expected));
            }
        }

        public static function assertGreaterThanOrEqual(mixed $expected, mixed $actual, string $message = ''): void
        {
            self::$assertionCount++;
            if (!($actual >= $expected)) {
                throw new AssertionFailedError($message ?: self::export($actual) . " is not >= " . self::export($expected));
            }
        }

        public static function assertLessThan(mixed $expected, mixed $actual, string $message = ''): void
        {
            self::$assertionCount++;
            if (!($actual < $expected)) {
                throw new AssertionFailedError($message ?: self::export($actual) . " is not < " . self::export($expected));
            }
        }

        public static function assertLessThanOrEqual(mixed $expected, mixed $actual, string $message = ''): void
        {
            self::$assertionCount++;
            if (!($actual <= $expected)) {
                throw new AssertionFailedError($message ?: self::export($actual) . " is not <= " . self::export($expected));
            }
        }

        public static function assertFileExists(string $path, string $message = ''): void
        {
            self::$assertionCount++;
            if (!is_file($path)) {
                throw new AssertionFailedError($message ?: "File does not exist: {$path}");
            }
        }

        public static function assertFileDoesNotExist(string $path, string $message = ''): void
        {
            self::$assertionCount++;
            if (is_file($path)) {
                throw new AssertionFailedError($message ?: "File unexpectedly exists: {$path}");
            }
        }

        public static function assertDirectoryExists(string $path, string $message = ''): void
        {
            self::$assertionCount++;
            if (!is_dir($path)) {
                throw new AssertionFailedError($message ?: "Directory does not exist: {$path}");
            }
        }

        public static function assertEmpty(mixed $value, string $message = ''): void
        {
            self::$assertionCount++;
            if (!empty($value)) {
                throw new AssertionFailedError($message ?: 'Expected empty value, got ' . self::export($value));
            }
        }

        public static function assertNotEmpty(mixed $value, string $message = ''): void
        {
            self::$assertionCount++;
            if (empty($value)) {
                throw new AssertionFailedError($message ?: 'Expected non-empty value');
            }
        }

        public static function assertIsArray(mixed $value, string $message = ''): void
        {
            self::$assertionCount++;
            if (!is_array($value)) {
                throw new AssertionFailedError($message ?: 'Expected array');
            }
        }

        public static function assertIsString(mixed $value, string $message = ''): void
        {
            self::$assertionCount++;
            if (!is_string($value)) {
                throw new AssertionFailedError($message ?: 'Expected string');
            }
        }

        public static function assertIsInt(mixed $value, string $message = ''): void
        {
            self::$assertionCount++;
            if (!is_int($value)) {
                throw new AssertionFailedError($message ?: 'Expected int');
            }
        }

        public static function assertArrayHasKey(string|int $key, array $array, string $message = ''): void
        {
            self::$assertionCount++;
            if (!array_key_exists($key, $array)) {
                throw new AssertionFailedError($message ?: "Array does not have key '{$key}'");
            }
        }

        public static function assertMatchesRegularExpression(string $pattern, string $value, string $message = ''): void
        {
            self::$assertionCount++;
            if (preg_match($pattern, $value) !== 1) {
                throw new AssertionFailedError($message ?: "Value does not match {$pattern}");
            }
        }

        public static function assertArrayNotHasKey(string|int $key, array $array, string $message = ''): void
        {
            self::$assertionCount++;
            if (array_key_exists($key, $array)) {
                throw new AssertionFailedError($message ?: "Array unexpectedly has key '{$key}'");
            }
        }

        public static function assertStringStartsWith(string $prefix, string $value, string $message = ''): void
        {
            self::$assertionCount++;
            if (!str_starts_with($value, $prefix)) {
                throw new AssertionFailedError($message ?: "String does not start with '{$prefix}'");
            }
        }

        public static function assertStringEndsWith(string $suffix, string $value, string $message = ''): void
        {
            self::$assertionCount++;
            if (!str_ends_with($value, $suffix)) {
                throw new AssertionFailedError($message ?: "String does not end with '{$suffix}'");
            }
        }

        public static function assertDirectoryDoesNotExist(string $path, string $message = ''): void
        {
            self::$assertionCount++;
            if (is_dir($path)) {
                throw new AssertionFailedError($message ?: "Directory '{$path}' unexpectedly exists");
            }
        }

        public static function assertContains(mixed $needle, array $haystack, string $message = ''): void
        {
            self::$assertionCount++;
            if (!in_array($needle, $haystack, true)) {
                throw new AssertionFailedError($message ?: 'Array does not contain the expected value');
            }
        }

        public static function assertNotContains(mixed $needle, array $haystack, string $message = ''): void
        {
            self::$assertionCount++;
            if (in_array($needle, $haystack, true)) {
                throw new AssertionFailedError($message ?: 'Array unexpectedly contains the value');
            }
        }

        public static function assertIsBool(mixed $value, string $message = ''): void
        {
            self::$assertionCount++;
            if (!is_bool($value)) {
                throw new AssertionFailedError($message ?: 'Value is not a boolean');
            }
        }

        public static function assertIsFloat(mixed $value, string $message = ''): void
        {
            self::$assertionCount++;
            if (!is_float($value)) {
                throw new AssertionFailedError($message ?: 'Value is not a float');
            }
        }

        public static function fail(string $message = ''): void
        {
            throw new AssertionFailedError($message ?: 'Test failed');
        }

        public static function markTestSkipped(string $message = ''): void
        {
            throw new SkippedTestError($message ?: 'Test skipped');
        }

        private static function export(mixed $value): string
        {
            if (is_string($value)) {
                return "'" . (strlen($value) > 120 ? substr($value, 0, 120) . '…' : $value) . "'";
            }
            if (is_scalar($value) || $value === null) {
                return var_export($value, true);
            }
            if (is_array($value)) {
                return 'array(' . count($value) . ')';
            }

            return get_debug_type($value);
        }
    }
}

namespace PHPUnit\Framework\Attributes {
    #[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
    final class DataProvider
    {
        public function __construct(public string $methodName)
        {
        }
    }

    #[\Attribute(\Attribute::TARGET_METHOD)]
    final class Test
    {
    }
}
