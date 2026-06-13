<?php

declare(strict_types=1);

namespace StepDispatcher\Tests\Fixtures;

use StepDispatcher\Abstracts\BaseStepJob;

/**
 * Test fixture for the double-check verification path. doubleCheck()
 * returns the static toggle so a test can drive: a passing verification
 * (step completes, double_check pinned to 99), a failing one (double_check
 * increments, step retries), and the exhausted-budget fail-open guard
 * (double_check >= 2 fails the step instead of silently completing).
 */
final class DoubleCheckTestJob extends BaseStepJob
{
    public int $retries = 5;

    public static bool $pass = true;

    public static int $computeRuns = 0;

    protected function compute(): mixed
    {
        static::$computeRuns++;

        return ['v' => 1];
    }

    public function doubleCheck(): bool
    {
        return static::$pass;
    }

    public static function reset(): void
    {
        static::$pass = true;
        static::$computeRuns = 0;
    }
}
