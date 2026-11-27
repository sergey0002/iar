<?php

declare(strict_types=1);

namespace App\Tests\Support;

/** Бросается TestSapi вместо exit, чтобы тест мог продолжить выполнение. */
final class ExitCalled extends \RuntimeException
{
}
