<?php

declare(strict_types=1);

namespace StepDispatcher\Tests\Fixtures;

use StepDispatcher\Abstracts\BaseStepJob;

/**
 * Test fixture for the BaseStepJob::shouldExitEarly() guard chain. Each
 * lifecycle guard (startOrStop / startOrSkip / startOrFail / startOrRetry)
 * reads a static toggle that defaults to "pass" (return true), so a test
 * flips exactly one to assert that branch — stop, skip, fail, or retry —
 * without the others interfering. computeRuns counts compute() executions
 * so a test can prove a guard short-circuited before the work ran.
 */
final class GuardedTestJob extends BaseStepJob
{
    public int $retries = 5;

    public static bool $stop = false;

    public static bool $skip = false;

    public static bool $fail = false;

    public static bool $retry = false;

    public static int $computeRuns = 0;

    protected function compute(): mixed
    {
        static::$computeRuns++;

        return ['ran' => true];
    }

    public function startOrStop(): bool
    {
        return ! static::$stop;
    }

    public function startOrSkip(): bool
    {
        return ! static::$skip;
    }

    public function startOrFail(): bool
    {
        return ! static::$fail;
    }

    public function startOrRetry(): bool
    {
        return ! static::$retry;
    }

    public static function reset(): void
    {
        static::$stop = false;
        static::$skip = false;
        static::$fail = false;
        static::$retry = false;
        static::$computeRuns = 0;
    }
}
