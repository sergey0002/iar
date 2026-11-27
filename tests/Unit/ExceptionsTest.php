<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Exception\BadRequestException;
use App\Exception\ConfigException;
use App\Exception\ForbiddenException;
use App\Exception\IarException;
use App\Exception\InputTooLargeException;
use App\Exception\MethodNotAllowedException;
use App\Exception\NotFoundException;
use App\Exception\ProcessingException;
use App\Exception\UnsupportedFormatException;
use App\Http\ErrorResponse;
use App\Tests\Support\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExceptionsTest extends TestCase
{
    public static function exceptions(): array
    {
        return [
            'base' => [new IarException('x'), 500, 'Internal error.'],
            'bad request' => [new BadRequestException('x'), 400, 'Bad request.'],
            'forbidden' => [new ForbiddenException('x'), 403, 'Access denied.'],
            'not found' => [new NotFoundException('x'), 404, 'File not found.'],
            'method' => [new MethodNotAllowedException('x'), 405, 'Method not allowed.'],
            'format' => [new UnsupportedFormatException('x'), 400, 'Unsupported output format.'],
            'config' => [new ConfigException('x'), 500, 'Configuration error.'],
            'too large' => [new InputTooLargeException('x'), 413, 'Source image is too large.'],
            'processing' => [new ProcessingException('detail'), 500, 'Image processing failed.'],
        ];
    }

    #[DataProvider('exceptions')]
    public function testStatusAndPublicMessage(IarException $e, int $status, string $message): void
    {
        self::assertSame($status, $e->status());
        self::assertSame($message, $e->publicMessage());
    }

    public function testProcessingExceptionCarriesReason(): void
    {
        $previous = new \RuntimeException('root cause');
        $e = new ProcessingException('SVG rasterization requires Imagick', $previous);

        self::assertSame('SVG rasterization requires Imagick', $e->reason());
        self::assertSame('SVG rasterization requires Imagick', $e->getMessage());
        self::assertSame($previous, $e->getPrevious());
    }

    /** Аудит E-03: клиент не видит внутренних деталей. */
    #[DataProvider('exceptions')]
    public function testErrorResponseIsNeutral(IarException $e, int $status, string $message): void
    {
        $sapi = Factory::send(ErrorResponse::from($e));

        self::assertSame($status, $sapi->status);
        self::assertSame($message . "\n", $sapi->body);
        self::assertSame('nosniff', $sapi->headerValue('X-Content-Type-Options'));
        self::assertSame('no-store', $sapi->headerValue('Cache-Control'));
        self::assertStringNotContainsString('/www', $sapi->body);
    }

    public function testMethodNotAllowedIncludesAllowHeader(): void
    {
        $sapi = Factory::send(ErrorResponse::from(new MethodNotAllowedException('x')));

        self::assertSame('GET, HEAD', $sapi->headerValue('Allow'));
    }

    public function testErrorResponseHeadOnly(): void
    {
        $sapi = Factory::send(ErrorResponse::from(new NotFoundException('x'), true));

        self::assertSame(404, $sapi->status);
        self::assertSame('', $sapi->body);
    }

    public function testOtherExceptionsHaveNoAllowHeader(): void
    {
        $sapi = Factory::send(ErrorResponse::from(new NotFoundException('x')));

        self::assertNull($sapi->headerValue('Allow'));
    }
}
