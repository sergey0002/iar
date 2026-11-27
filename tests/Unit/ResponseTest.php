<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\Response;
use App\Tests\Support\Factory;
use App\Tests\Support\TestSapi;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $dir = Factory::tempDir();
        $this->file = $dir . '/data.bin';
        file_put_contents($this->file, 'payload');
    }

    protected function tearDown(): void
    {
        Factory::cleanup();
    }

    public function testFileResponseSendsHeadersAndBody(): void
    {
        $sapi = Factory::send(Response::file($this->file, [['Content-Type', 'image/jpeg']]));

        self::assertSame(200, $sapi->status);
        self::assertSame('image/jpeg', $sapi->headerValue('Content-Type'));
        self::assertSame('payload', $sapi->body);
        self::assertSame($this->file, $sapi->fileRead);
        self::assertTrue($sapi->terminated);
    }

    public function testHeadResponseSendsNoBody(): void
    {
        $sapi = Factory::send(Response::file($this->file, [['Content-Type', 'image/jpeg']], true));

        self::assertSame('', $sapi->body);
        self::assertNull($sapi->fileRead);
        self::assertTrue($sapi->terminated);
    }

    public function testRedirectResponse(): void
    {
        $sapi = Factory::send(Response::redirect('/cache/x.jpg', 302, [['Cache-Control', 'no-cache']]));

        self::assertSame(302, $sapi->status);
        self::assertSame('/cache/x.jpg', $sapi->headerValue('Location'));
        self::assertSame('no-cache', $sapi->headerValue('Cache-Control'));
        self::assertSame('', $sapi->body);
    }

    public function testPermanentRedirect(): void
    {
        $sapi = Factory::send(Response::redirect('/cache/x.jpg', 301));

        self::assertSame(301, $sapi->status);
    }

    public function testNotModifiedSendsHeadersWithoutBody(): void
    {
        $sapi = Factory::send(Response::notModified([
            ['ETag', '"abc"'],
            ['Cache-Control', 'no-cache'],
            ['Vary', 'Accept'],
        ]));

        self::assertSame(304, $sapi->status);
        self::assertSame('"abc"', $sapi->headerValue('ETag'));
        self::assertSame('no-cache', $sapi->headerValue('Cache-Control'));
        self::assertSame('Accept', $sapi->headerValue('Vary'));
        self::assertSame('', $sapi->body);
    }

    public function testTextResponse(): void
    {
        $sapi = Factory::send(Response::text(404, "Not found\n", [['Content-Type', 'text/plain']]));

        self::assertSame(404, $sapi->status);
        self::assertSame("Not found\n", $sapi->body);
    }

    public function testTextResponseHeadOnly(): void
    {
        $sapi = Factory::send(Response::text(404, "Not found\n", [], true));

        self::assertSame('', $sapi->body);
    }

    public function testWithHeaderAppends(): void
    {
        $response = Response::text(200, 'x')->withHeader('X-Test', '1');

        self::assertSame('1', $response->headerValue('X-Test'));
        self::assertSame(200, $response->status);
        self::assertSame('x', $response->body);
    }

    public function testHeaderValueIsCaseInsensitive(): void
    {
        $response = Response::text(200, 'x', [['Content-Type', 'text/plain']]);

        self::assertSame('text/plain', $response->headerValue('content-type'));
        self::assertNull($response->headerValue('X-Missing'));
    }

    public function testHeaderValuesReturnsAllMatches(): void
    {
        $response = Response::text(200, 'x', [['X-Multi', 'a'], ['X-Multi', 'b']]);

        self::assertSame(['a', 'b'], $response->headerValues('X-Multi'));
        self::assertSame([], $response->headerValues('X-None'));
    }

    public function testEmptyResponseSendsOnlyStatus(): void
    {
        $sapi = Factory::send(Response::notModified([]));

        self::assertSame(304, $sapi->status);
        self::assertSame([], $sapi->headers);
    }

    public function testSendUsesReplaceSemanticsOfSapi(): void
    {
        $sapi = new TestSapi();
        $response = Response::text(200, 'x', [['X-A', '1'], ['X-A', '2']]);

        try {
            $response->send($sapi);
        } catch (\App\Tests\Support\ExitCalled) {
        }

        // TestSapi повторяет поведение header(): последнее значение вытесняет прежнее.
        self::assertSame(['2'], $sapi->headerValues('X-A'));
    }
}
