<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\PathRouter;
use App\Http\Route;
use App\Tests\Support\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Маршруты из aliases.json: псевдонимы каталогов, пресеты и макросы
 * последней папки. Проверяем и правила разбора, и политику надёжности
 * (сломанный конфиг не роняет картинки).
 */
final class PathRouterTest extends TestCase
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

    /** @return array<string,mixed> */
    private function sample(): array
    {
        return [
            'aliases' => ['z' => '/z/s/d/f/', 'img' => 'assets/images'],
            'presets' => [
                '1000' => ['w' => 1000],
                'thumb' => ['w' => 300, 'h' => 300, 'm' => 'cover'],
            ],
            'macros' => ['enabled' => true, 'width' => 'x-', 'height' => 'y-'],
        ];
    }

    private function router(): PathRouter
    {
        return PathRouter::fromArray($this->sample(), $this->site . '/');
    }

    // -----------------------------------------------------------------
    // Загрузка конфигурации
    // -----------------------------------------------------------------

    public function testDisabledRouterKeepsPathUntouched(): void
    {
        $router = PathRouter::disabled();

        self::assertTrue($router->isEmpty());
        self::assertNull($router->error());
        self::assertSame('z/file.png', $router->route('z/file.png')->name);
    }

    public function testMissingFileIsNotAnError(): void
    {
        $router = PathRouter::fromFile($this->site . '/absent.json', $this->site . '/');

        self::assertTrue($router->isEmpty());
        self::assertNull($router->error());
    }

    public function testBrokenJsonDisablesRoutingAndReportsReason(): void
    {
        $file = $this->site . '/aliases.json';
        file_put_contents($file, '{not json');

        $router = PathRouter::fromFile($file, $this->site . '/');

        self::assertTrue($router->isEmpty());
        self::assertSame('Aliases file must contain a JSON object.', $router->error());
        self::assertSame('z/file.png', $router->route('z/file.png')->name);
    }

    public function testScalarJsonIsRejected(): void
    {
        $file = $this->site . '/aliases.json';
        file_put_contents($file, '"just a string"');

        self::assertSame('Aliases file must contain a JSON object.', PathRouter::fromFile($file)->error());
    }

    public function testUnreadableFileDisablesRouting(): void
    {
        $file = $this->site . '/aliases.json';
        file_put_contents($file, '{}');
        chmod($file, 0000);
        clearstatcache();

        if (@file_get_contents($file) !== false) {
            chmod($file, 0644);
            self::markTestSkipped('Права не ограничивают чтение (запуск от root).');
        }

        try {
            $router = PathRouter::fromFile($file, $this->site . '/');
            self::assertTrue($router->isEmpty());
            self::assertSame('Aliases file is not readable.', $router->error());
        } finally {
            chmod($file, 0644);
        }
    }

    public function testSampleFileFromRepositoryIsLoadable(): void
    {
        $router = PathRouter::fromFile(dirname(__DIR__, 2) . '/aliases.json', $this->site . '/');

        self::assertNull($router->error());
        self::assertFalse($router->isEmpty());

        $route = $router->route('z/file.png');
        self::assertSame('z/s/d/f/file.png', $route->name);
        self::assertSame('z', $route->alias);
    }

    public function testEnabledFalseTurnsEverythingOff(): void
    {
        $raw = $this->sample();
        $raw['enabled'] = false;

        self::assertTrue(PathRouter::fromArray($raw)->isEmpty());
    }

    public function testSectionsOfWrongTypeAreIgnored(): void
    {
        $router = PathRouter::fromArray([
            'aliases' => 'nope',
            'presets' => 42,
            'macros' => 'nope',
        ]);

        // Макросы включены по умолчанию, поэтому роутер не «пустой».
        self::assertFalse($router->isEmpty());
        self::assertSame(['w' => '200'], $router->route('x-200/a.png')->params);
    }

    // -----------------------------------------------------------------
    // Псевдонимы каталогов
    // -----------------------------------------------------------------

    public function testAliasRewritesDirectory(): void
    {
        $route = $this->router()->route('z/file.png');

        self::assertSame('z/s/d/f/file.png', $route->name);
        self::assertSame('z/file.png', $route->original);
        self::assertSame('z', $route->alias);
        self::assertNull($route->preset);
        self::assertTrue($route->isRewritten());
        self::assertTrue($route->hasAlias());
        self::assertSame([], $route->params);
    }

    public function testAliasKeepsNestedTail(): void
    {
        self::assertSame('assets/images/2024/a.png', $this->router()->route('img/2024/a.png')->name);
    }

    public function testLongestAliasWins(): void
    {
        $router = PathRouter::fromArray([
            'aliases' => ['a' => 'short', 'a/b' => 'long'],
        ]);

        self::assertSame('long/c.png', $router->route('a/b/c.png')->name);
        self::assertSame('short/c.png', $router->route('a/c.png')->name);
    }

    public function testAliasesWithSameDepthAreOrderedByName(): void
    {
        $router = PathRouter::fromArray(['aliases' => ['b' => 'second', 'a' => 'first']]);

        self::assertSame('first/x.png', $router->route('a/x.png')->name);
        self::assertSame('second/x.png', $router->route('b/x.png')->name);
    }

    public function testAliasWithoutTrailingFilenameIsNotRewritten(): void
    {
        $route = $this->router()->route('z');

        self::assertSame('z', $route->name);
        self::assertFalse($route->isRewritten());
    }

    public function testUnknownAliasIsLeftAsIs(): void
    {
        $route = $this->router()->route('unknown/file.png');

        self::assertSame('unknown/file.png', $route->name);
        self::assertNull($route->alias);
        self::assertFalse($route->isRewritten());
    }

    public function testAliasToRootDirectory(): void
    {
        $router = PathRouter::fromArray(['aliases' => ['root' => '/']]);

        self::assertSame('file.png', $router->route('root/file.png')->name);
    }

    public function testBackslashesAndSlashesAreNormalized(): void
    {
        $router = PathRouter::fromArray(['aliases' => ['\\z\\' => '\\z\\s\\']]);

        self::assertSame('z/s/file.png', $router->route('\\z\\file.png')->name);
    }

    public function testLeadingSlashAndDotSegmentsAreHandled(): void
    {
        self::assertSame('z/s/d/f/file.png', $this->router()->route('/z/file.png')->name);
    }

    #[DataProvider('unsafeAliases')]
    public function testUnsafeAliasKeysAreDropped(string $key): void
    {
        $router = PathRouter::fromArray([
            'aliases' => [$key => 'target'],
            'macros' => ['enabled' => false],
        ]);

        self::assertTrue($router->isEmpty());
    }

    /** @return array<string,array{0:string}> */
    public static function unsafeAliases(): array
    {
        return [
            'пустой' => [''],
            'служебный комментарий' => ['_readme'],
            'выход наверх' => ['../up'],
            'текущий каталог' => ['./here'],
            'схема' => ['http://evil'],
            'диск' => ['C:'],
            'nul' => ["a\0b"],
        ];
    }

    #[DataProvider('unsafeTargets')]
    public function testUnsafeAliasTargetsAreDropped(mixed $target): void
    {
        $router = PathRouter::fromArray([
            'aliases' => ['z' => $target],
            'macros' => ['enabled' => false],
        ]);

        self::assertTrue($router->isEmpty());
        self::assertSame('z/a.png', $router->route('z/a.png')->name);
    }

    /** @return array<string,array{0:mixed}> */
    public static function unsafeTargets(): array
    {
        return [
            'не строка' => [123],
            'выход наверх' => ['../../etc'],
            'текущий каталог' => ['./x'],
            'схема' => ['file:///etc'],
            'диск' => ['D:\\files'],
            'nul' => ["a\0b"],
        ];
    }

    // -----------------------------------------------------------------
    // Пресеты: последняя папка как набор параметров
    // -----------------------------------------------------------------

    public function testPresetFolderBecomesParameters(): void
    {
        $route = $this->router()->route('1000/photo.jpg');

        self::assertSame('photo.jpg', $route->name);
        self::assertSame(['w' => '1000'], $route->params);
        self::assertSame('1000', $route->preset);
        self::assertNull($route->alias);
        self::assertTrue($route->isRewritten());
        self::assertFalse($route->hasAlias());
    }

    public function testPresetWithSeveralParameters(): void
    {
        $route = $this->router()->route('thumb/photo.jpg');

        self::assertSame(['w' => '300', 'h' => '300', 'm' => 'cover'], $route->params);
    }

    public function testOnlyLastFolderIsInterpreted(): void
    {
        // «1000» стоит не перед именем файла — это обычный каталог.
        $route = $this->router()->route('1000/nested/photo.jpg');

        self::assertSame('1000/nested/photo.jpg', $route->name);
        self::assertSame([], $route->params);
        self::assertFalse($route->isRewritten());
    }

    public function testPresetAndAliasTogether(): void
    {
        $route = $this->router()->route('z/x-2000/file.png');

        self::assertSame('z/s/d/f/file.png', $route->name);
        self::assertSame(['w' => '2000'], $route->params);
        self::assertSame('z', $route->alias);
        self::assertSame('x-2000', $route->preset);
    }

    public function testPresetBeatsMacroWithSameName(): void
    {
        $router = PathRouter::fromArray([
            'presets' => ['x-100' => ['h' => 50]],
            'macros' => ['enabled' => true],
        ]);

        self::assertSame(['h' => '50'], $router->route('x-100/a.png')->params);
    }

    #[DataProvider('invalidPresets')]
    public function testInvalidPresetDefinitionsAreIgnored(mixed $value): void
    {
        $router = PathRouter::fromArray(['presets' => ['thumb' => $value], 'macros' => ['enabled' => false]]);

        self::assertTrue($router->isEmpty());
    }

    /** @return array<string,array{0:mixed}> */
    public static function invalidPresets(): array
    {
        return [
            'не массив' => ['300'],
            'пустой набор' => [[]],
            'только неизвестные ключи' => [['width' => 300, 'quality' => 80]],
            'только не скаляры' => [['w' => [300]]],
        ];
    }

    public function testPresetNameWithSlashIsIgnored(): void
    {
        $router = PathRouter::fromArray(['presets' => ['a/b' => ['w' => 10]], 'macros' => ['enabled' => false]]);

        self::assertTrue($router->isEmpty());
    }

    public function testUnderscoreKeysAreComments(): void
    {
        $router = PathRouter::fromArray([
            '_readme' => ['комментарий'],
            'aliases' => ['_note' => 'x', 'z' => 'real'],
            'presets' => ['_note' => ['w' => 10], 'thumb' => ['w' => 20]],
            'macros' => ['enabled' => false],
        ]);

        self::assertSame('_note/a.png', $router->route('_note/a.png')->name);
        self::assertSame(['w' => '20'], $router->route('thumb/a.png')->params);
        self::assertSame('real/a.png', $router->route('z/a.png')->name);
    }

    public function testPresetKeepsOnlyWhitelistedScalarParams(): void
    {
        $router = PathRouter::fromArray([
            'presets' => ['p' => ['w' => 200, 'fm' => 'webp', 'name' => '../etc/passwd', 'x' => [1], 'ct' => 10]],
        ]);

        self::assertSame(['w' => '200', 'fm' => 'webp', 'ct' => '10'], $router->route('p/a.png')->params);
    }

    // -----------------------------------------------------------------
    // Макросы: ровно один параметр
    // -----------------------------------------------------------------

    public function testWidthMacro(): void
    {
        $route = $this->router()->route('x-2000/file.png');

        self::assertSame('file.png', $route->name);
        self::assertSame(['w' => '2000'], $route->params);
        self::assertCount(1, $route->params);
    }

    public function testHeightMacro(): void
    {
        self::assertSame(['h' => '1000'], $this->router()->route('y-1000/file.png')->params);
    }

    public function testCustomMacroPrefixes(): void
    {
        $router = PathRouter::fromArray(['macros' => ['width' => 'w', 'height' => 'h_']]);

        self::assertSame(['w' => '800'], $router->route('w800/a.png')->params);
        self::assertSame(['h' => '600'], $router->route('h_600/a.png')->params);
    }

    #[DataProvider('invalidPrefixes')]
    public function testInvalidMacroPrefixFallsBackToDefault(mixed $prefix): void
    {
        $router = PathRouter::fromArray(['macros' => ['width' => $prefix, 'height' => $prefix]]);

        self::assertSame(['w' => '10'], $router->route('x-10/a.png')->params);
        self::assertSame(['h' => '10'], $router->route('y-10/a.png')->params);
    }

    /** @return array<string,array{0:mixed}> */
    public static function invalidPrefixes(): array
    {
        return [
            'не строка' => [7],
            'пустой' => [''],
            'слишком длинный' => ['aaaaaaaaaaaaaaaaaaaa'],
            'со слэшем' => ['x/'],
            'с пробелом' => ['x -'],
        ];
    }

    public function testMacrosCanBeDisabled(): void
    {
        $router = PathRouter::fromArray([
            'presets' => ['thumb' => ['w' => 10]],
            'macros' => ['enabled' => false],
        ]);

        self::assertSame('x-2000/a.png', $router->route('x-2000/a.png')->name);
        self::assertSame(['w' => '10'], $router->route('thumb/a.png')->params);
    }

    #[DataProvider('invalidMacros')]
    public function testInvalidMacroValueIsNotAParameter(string $folder): void
    {
        $route = $this->router()->route($folder . '/file.png');

        self::assertSame($folder . '/file.png', $route->name);
        self::assertSame([], $route->params);
    }

    /** @return array<string,array{0:string}> */
    public static function invalidMacros(): array
    {
        return [
            'без числа' => ['x-'],
            'ноль' => ['x-0'],
            'слишком длинное число' => ['x-1234567'],
            'не цифры' => ['x-12a'],
            'дробное' => ['x-1.5'],
            'знак' => ['x--10'],
            'пробел' => ['x- 10'],
            'высота без числа' => ['y-'],
        ];
    }

    public function testSixDigitMacroIsAccepted(): void
    {
        self::assertSame(['w' => '999999'], $this->router()->route('x-999999/a.png')->params);
    }

    // -----------------------------------------------------------------
    // Приоритет реального файла и дешёвый путь
    // -----------------------------------------------------------------

    public function testRealFileWinsOverPreset(): void
    {
        mkdir($this->site . '/1000', 0777, true);
        copy($this->site . '/photo.jpg', $this->site . '/1000/photo.jpg');

        $route = $this->router()->route('1000/photo.jpg');

        self::assertSame('1000/photo.jpg', $route->name);
        self::assertSame([], $route->params);
        self::assertFalse($route->isRewritten());
    }

    public function testRealFileWinsOverAlias(): void
    {
        mkdir($this->site . '/z', 0777, true);
        copy($this->site . '/photo.jpg', $this->site . '/z/photo.jpg');

        self::assertSame('z/photo.jpg', $this->router()->route('z/photo.jpg')->name);
    }

    public function testMissingRealFileKeepsRewrite(): void
    {
        self::assertSame('z/s/d/f/file.png', $this->router()->route('z/file.png')->name);
    }

    public function testRootLevelFileIsNotRouted(): void
    {
        $route = $this->router()->route('photo.jpg');

        self::assertSame('photo.jpg', $route->name);
        self::assertSame('photo.jpg', $route->original);
        self::assertFalse($route->isRewritten());
    }

    public function testEmptyPathIsNotRouted(): void
    {
        self::assertSame('', $this->router()->route('')->name);
    }

    public function testNullByteInPathIsNotRouted(): void
    {
        $path = "z/\0file.png";

        self::assertSame($path, $this->router()->route($path)->name);
    }

    public function testRouterWithoutBaseDirStillRewrites(): void
    {
        $router = PathRouter::fromArray($this->sample());

        self::assertSame('z/s/d/f/file.png', $router->route('z/file.png')->name);
    }

    public function testLiteralCheckIsOverridable(): void
    {
        $router = new class ($this->site) extends PathRouter {
            public function __construct(string $site)
            {
                parent::__construct(['z' => 'real/'], [], false, 'x-', 'y-', $site . '/', null);
            }

            protected function isFile(string $path): bool
            {
                return str_ends_with($path, 'exists.png');
            }
        };

        self::assertSame('z/exists.png', $router->route('z/exists.png')->name);
        self::assertSame('real/other.png', $router->route('z/other.png')->name);
    }

    // -----------------------------------------------------------------
    // Значение объекта Route
    // -----------------------------------------------------------------

    public function testPlainRouteValueObject(): void
    {
        $route = Route::plain('a/b.png');

        self::assertSame('a/b.png', $route->name);
        self::assertSame('a/b.png', $route->original);
        self::assertSame([], $route->params);
        self::assertNull($route->alias);
        self::assertNull($route->preset);
        self::assertFalse($route->isRewritten());
        self::assertFalse($route->hasAlias());
    }

    public function testRewrittenRouteValueObject(): void
    {
        $route = Route::rewritten('real/b.png', 'z/thumb/b.png', ['w' => '10'], 'z', 'thumb');

        self::assertSame('real/b.png', $route->name);
        self::assertSame('z/thumb/b.png', $route->original);
        self::assertSame(['w' => '10'], $route->params);
        self::assertTrue($route->isRewritten());
        self::assertTrue($route->hasAlias());
    }
}
