<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\IarException;
use App\Exception\MethodNotAllowedException;

/**
 * Единый маппинг исключений в нейтральные HTTP-ответы (аудит E-02, E-03).
 * Детали ошибки уходят только в лог, клиент видит нейтральное сообщение.
 */
final class ErrorResponse
{
    public static function from(IarException $e, bool $headOnly = false): Response
    {
        $headers = [
            ['Content-Type', 'text/plain; charset=UTF-8'],
            ['X-Content-Type-Options', 'nosniff'],
            ['Cache-Control', 'no-store'],
        ];

        if ($e instanceof MethodNotAllowedException) {
            $headers[] = ['Allow', 'GET, HEAD'];
        }

        return Response::text($e->status(), $e->publicMessage() . "\n", $headers, $headOnly);
    }
}
