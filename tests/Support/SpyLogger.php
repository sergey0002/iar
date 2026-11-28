<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Infrastructure\LoggerInterface;

final class SpyLogger implements LoggerInterface
{
    /** @var list<array{event:string,context:array<string,mixed>}> */
    public array $records = [];

    public function __construct(private readonly bool $logsRequests = true)
    {
    }

    public function logsRequests(): bool
    {
        return $this->logsRequests;
    }

    public function log(string $event, array $context): void
    {
        $this->records[] = ['event' => $event, 'context' => $context];
    }

    public function lastEvent(): ?string
    {
        $last = end($this->records);

        return $last === false ? null : $last['event'];
    }

    /** @return array<string,mixed>|null */
    public function lastContext(): ?array
    {
        $last = end($this->records);

        return $last === false ? null : $last['context'];
    }
}
