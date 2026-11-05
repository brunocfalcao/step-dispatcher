<?php

declare(strict_types=1);

namespace StepDispatcher\Tests\Fixtures;

use Closure;
use StepDispatcher\Abstracts\BaseStepJob;

/**
 * Test fixture for a job with no consumer hooks at all, so a lost step is
 * only ever met by the engine's own writes.
 */
final class ObsoleteAttemptPlainTestJob extends BaseStepJob
{
    public int $retries = 5;

    public static ?Closure $whileRunning = null;

    public static function reset(): void
    {
        self::$whileRunning = null;
    }

    protected function compute(): mixed
    {
        (self::$whileRunning)($this);

        return ['from' => 'plain attempt'];
    }
}
