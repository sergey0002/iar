<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Format;
use App\Domain\Gravity;
use App\Domain\Mode;
use App\Domain\Side;
use App\Domain\Unit;
use App\Exception\BadRequestException;
use App\Exception\MethodNotAllowedException;
use App\Http\Request;
use App\Tests\Support\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
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

    public function testMinimalRequest(): void
    {
        $request = Factory::request($this->site);

        self::assertSame('photo.jpg', $request->name);
        self::assertSame(0, $request->width);
        self::assertSame(0, $request->height);
        self::assertNull($request->mode);
        self::assertNull($request->gravity);
        self::assertNull($request->format);
        self::assertNull($request->crop);
        self::assertFalse($request->multipleCropParams);
        self::assertFalse($request->isHead());
        self::assertSame('/iar', $request->scriptDir());
    }

    public function testDimensionsAreParsed(): void
    {
        $request = Factory::request($this->site, ['w' => '800', 'h' => '600']);

        self::assertSame(800, $request->width);
        self::assertSame(600, $request->height);
    }

    public function testEmptyDimensionsBecomeZero(): void
    {
        $request = Factory::request($this->site, ['w' => '', 'h' => '']);

        self::assertSame(0, $request->width);
        self::assertSame(0, $request->height);
    }

    public function testIntegerDimensionsAccepted(): void
    {
        $request = Factory::request($this->site, ['w' => 800]);

        self::assertSame(800, $request->width);
    }

    /** Аудит B-11: массивы в параметрах больше не валят скрипт. */
    public static function arrayParams(): array
    {
        return [
            'name' => [['name' => ['photo.jpg']]],
            'w' => [['w' => ['5']]],
            'h' => [['h' => ['5']]],
            'm' => [['m' => ['fit']]],
            'g' => [['g' => ['top']]],
            'fm' => [['fm' => ['webp']]],
            'ct' => [['ct' => ['10']]],
        ];
    }

    #[DataProvider('arrayParams')]
    public function testArrayParameterRejected(array $get): void
    {
        $this->expectException(BadRequestException::class);
        Factory::request($this->site, $get);
    }

    public static function invalidDimensions(): array
    {
        return [
            'non-numeric' => ['abc'],
            'float' => ['1.5'],
            'negative' => ['-5'],
            'hex' => ['0x10'],
            'spaces' => ['1 2'],
        ];
    }

    #[DataProvider('invalidDimensions')]
    public function testInvalidDimensionRejected(string $value): void
    {
        $this->expectException(BadRequestException::class);
        Factory::request($this->site, ['w' => $value]);
    }

    public function testMissingNameRejected(): void
    {
        $this->expectException(BadRequestException::class);
        Request::fromArray([], ['REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/iar/index.php']);
    }

    public function testBlankNameRejected(): void
    {
        $this->expectException(BadRequestException::class);
        Request::fromArray(['name' => '   '], ['REQUEST_METHOD' => 'GET']);
    }

    /** Аудит S-06: путь из URI имеет приоритет над пользовательским ?name=. */
    public function testUriPathWinsOverQueryName(): void
    {
        $request = Request::fromArray(
            ['name' => 'private.png', 'w' => '100'],
            [
                'REQUEST_METHOD' => 'GET',
                'SCRIPT_NAME' => '/iar/index.php',
                'REQUEST_URI' => '/iar/photo.jpg?name=private.png&w=100',
            ],
        );

        self::assertSame('photo.jpg', $request->name);
    }

    public function testNestedUriPathIsResolved(): void
    {
        $request = Request::fromArray([], [
            'REQUEST_METHOD' => 'GET',
            'SCRIPT_NAME' => '/iar/index.php',
            'REQUEST_URI' => '/iar/catalog/phones/2026/iphone15.jpg?w=400',
        ]);

        self::assertSame('catalog/phones/2026/iphone15.jpg', $request->name);
    }

    public function testUriPathIsUrlDecoded(): void
    {
        $request = Request::fromArray([], [
            'REQUEST_METHOD' => 'GET',
            'SCRIPT_NAME' => '/iar/index.php',
            'REQUEST_URI' => '/iar/photo%20copy.jpg',
        ]);

        self::assertSame('photo copy.jpg', $request->name);
    }

    public function testDirectIndexPhpAccessFallsBackToQueryName(): void
    {
        $request = Request::fromArray(['name' => 'photo.jpg'], [
            'REQUEST_METHOD' => 'GET',
            'SCRIPT_NAME' => '/iar/index.php',
            'REQUEST_URI' => '/iar/index.php?name=photo.jpg',
        ]);

        self::assertSame('photo.jpg', $request->name);
    }

    public function testRootLevelScriptDirectory(): void
    {
        $request = Request::fromArray([], [
            'REQUEST_METHOD' => 'GET',
            'SCRIPT_NAME' => '/index.php',
            'REQUEST_URI' => '/photo.jpg',
        ]);

        self::assertSame('photo.jpg', $request->name);
        self::assertSame('', $request->scriptDir());
    }

    /** Аудит H-05: только GET и HEAD. */
    public static function forbiddenMethods(): array
    {
        return [['POST'], ['PUT'], ['DELETE'], ['PATCH'], ['OPTIONS']];
    }

    #[DataProvider('forbiddenMethods')]
    public function testNonGetMethodRejected(string $method): void
    {
        $this->expectException(MethodNotAllowedException::class);
        Factory::request($this->site, [], ['REQUEST_METHOD' => $method]);
    }

    public function testHeadIsAllowed(): void
    {
        $request = Factory::request($this->site, [], ['REQUEST_METHOD' => 'HEAD']);

        self::assertTrue($request->isHead());
    }

    public function testMethodIsCaseInsensitive(): void
    {
        $request = Factory::request($this->site, [], ['REQUEST_METHOD' => 'get']);

        self::assertSame('GET', $request->method);
    }

    public function testModeAndGravityAreParsed(): void
    {
        $request = Factory::request($this->site, ['m' => 'cover', 'g' => 'top-left']);

        self::assertSame(Mode::Cover, $request->mode);
        self::assertSame(Gravity::TopLeft, $request->gravity);
    }

    public function testUnknownModeAndGravityFallBackToNull(): void
    {
        $request = Factory::request($this->site, ['m' => 'stretch', 'g' => 'middle']);

        self::assertNull($request->mode);
        self::assertNull($request->gravity);
    }

    public function testEmptyEnumParamsAreNull(): void
    {
        $request = Factory::request($this->site, ['m' => '', 'g' => '', 'fm' => '']);

        self::assertNull($request->mode);
        self::assertNull($request->gravity);
        self::assertNull($request->format);
    }

    /** Аудит B-13: верхний регистр fm больше не игнорируется. */
    public function testFormatIsCaseInsensitive(): void
    {
        self::assertSame(Format::Webp, Factory::request($this->site, ['fm' => 'WEBP'])->format);
        self::assertSame(Format::Webp, Factory::request($this->site, ['fm' => 'WebP'])->format);
        self::assertSame(Format::Jpg, Factory::request($this->site, ['fm' => ' JPG '])->format);
    }

    public function testUnknownFormatIsIgnored(): void
    {
        self::assertNull(Factory::request($this->site, ['fm' => 'tiff'])->format);
    }

    public function testCropIsParsed(): void
    {
        $request = Factory::request($this->site, ['cb' => '15p']);

        self::assertNotNull($request->crop);
        self::assertSame(Side::Bottom, $request->crop->side);
        self::assertSame(15.0, $request->crop->value);
        self::assertSame(Unit::Percent, $request->crop->unit);
    }

    public function testCropPriorityOrder(): void
    {
        $request = Factory::request($this->site, ['cl' => '5', 'cb' => '6', 'cr' => '7', 'ct' => '8']);

        self::assertSame(Side::Top, $request->crop->side);
        self::assertTrue($request->multipleCropParams);
    }

    public function testEmptyCropParamsAreSkipped(): void
    {
        $request = Factory::request($this->site, ['ct' => '', 'cb' => '10']);

        self::assertSame(Side::Bottom, $request->crop->side);
        self::assertFalse($request->multipleCropParams);
    }

    public function testNumericCropAccepted(): void
    {
        $request = Factory::request($this->site, ['ct' => 50]);

        self::assertSame(50.0, $request->crop->value);
    }

    public function testAcceptAndIfNoneMatchAreCaptured(): void
    {
        $request = Factory::request($this->site, [], [
            'HTTP_ACCEPT' => 'image/avif,image/webp',
            'HTTP_IF_NONE_MATCH' => '"abc"',
        ]);

        self::assertSame('image/avif,image/webp', $request->accept);
        self::assertSame('"abc"', $request->ifNoneMatch);
    }

    public function testNonStringServerValuesFallBackToDefaults(): void
    {
        $request = Request::fromArray(['name' => 'photo.jpg'], [
            'REQUEST_METHOD' => ['GET'],
            'SCRIPT_NAME' => 5,
            'HTTP_ACCEPT' => ['image/webp'],
            'HTTP_IF_NONE_MATCH' => ['x'],
        ]);

        self::assertSame('GET', $request->method);
        self::assertSame('', $request->accept);
        self::assertNull($request->ifNoneMatch);
    }

    public function testQueryIsPreservedForLogging(): void
    {
        $request = Factory::request($this->site, ['w' => '100']);

        self::assertArrayHasKey('w', $request->query);
        self::assertArrayHasKey('name', $request->query);
    }
}
