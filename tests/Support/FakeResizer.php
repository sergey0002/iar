<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Format;
use App\Domain\ResizeParams;
use App\Domain\ResizerInterface;
use App\Exception\ProcessingException;

/** Подменный обработчик для тестов оркестрации без реального GD. */
final class FakeResizer implements ResizerInterface
{
    public int $calls = 0;

    public ?ResizeParams $lastParams = null;

    public ?Format $lastTarget = null;

    public ?string $lastSource = null;

    public ?string $failure = null;

    public function __construct(private readonly string $bytes = 'FAKE-IMAGE-BYTES')
    {
    }

    public function render(string $sourcePath, ResizeParams $params, Format $target): string
    {
        $this->calls++;
        $this->lastSource = $sourcePath;
        $this->lastParams = $params;
        $this->lastTarget = $target;

        if ($this->failure !== null) {
            throw new ProcessingException($this->failure);
        }

        return $this->bytes;
    }
}
