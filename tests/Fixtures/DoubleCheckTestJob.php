<?php

declare(strict_types=1);

namespace StepDispatcher\Tests\Fixtures;

use Closure;
use StepDispatcher\Abstracts\BaseStepJob;
use Throwable;

/**
 * Test fixture for the double-check verification path. doubleCheck()
 * returns the static toggle so a test can drive: a passing verification
 * (step completes, double_check sealed at 99), a failing one (double_check
 * increments, step retries), and the exhausted-budget guard (double_check
 * of 2 or more, other than the seal, fails the step instead of silently
 * completing).
 *
 * Every hook counts its runs, and complete(), confirmOrRetry() and
 * executeJobLogic() can run a closure, so a test can stop an attempt after
 * its check passed and watch what the next attempt does.
 */
final class DoubleCheckTestJob extends BaseStepJob
{
    /** Message of the exception retryException() classifies as a retry. */
    public const RETRYABLE_MESSAGE = 'retry this attempt';

    public int $retries = 5;

    public static bool $pass = true;

    public static bool $confirms = true;

    public static int $computeRuns = 0;

    public static int $doubleCheckRuns = 0;

    public static int $confirmRuns = 0;

    public static int $completeRuns = 0;

    public static int $finalizeRuns = 0;

    public static int $startDelayMicroseconds = 0;

    /** Runs inside compute(), with the job and the number of this compute() run. */
    public static ?Closure $onCompute = null;

    /** Runs inside doubleCheck(), with the job and the number of this run. */
    public static ?Closure $onDoubleCheck = null;

    /** Runs inside complete(), with the job and the number of this complete() run. */
    public static ?Closure $onComplete = null;

    /** Runs inside confirmOrRetry(), with the job and the number of this run. */
    public static ?Closure $onConfirm = null;

    /** Runs after executeJobLogic(), with the job. */
    public static ?Closure $afterExecute = null;

    public function startOrSkip(): bool
    {
        usleep(self::$startDelayMicroseconds);

        return true;
    }

    protected function compute(): mixed
    {
        self::$computeRuns++;

        if (self::$onCompute !== null) {
            (self::$onCompute)($this, self::$computeRuns);
        }

        return ['v' => 1];
    }

    public function doubleCheck(): bool
    {
        self::$doubleCheckRuns++;

        if (self::$onDoubleCheck !== null) {
            (self::$onDoubleCheck)($this, self::$doubleCheckRuns);
        }

        return self::$pass;
    }

    public function confirmOrRetry(): bool
    {
        self::$confirmRuns++;

        if (self::$onConfirm !== null) {
            (self::$onConfirm)($this, self::$confirmRuns);
        }

        return self::$confirms;
    }

    public function complete(): void
    {
        self::$completeRuns++;

        if (self::$onComplete !== null) {
            (self::$onComplete)($this, self::$completeRuns);
        }
    }

    public function retryException(Throwable $e): bool
    {
        return $e->getMessage() === self::RETRYABLE_MESSAGE;
    }

    protected function executeJobLogic(): void
    {
        parent::executeJobLogic();

        if (self::$afterExecute !== null) {
            (self::$afterExecute)($this);
        }
    }

    protected function finalizeJobExecution(): void
    {
        self::$finalizeRuns++;

        parent::finalizeJobExecution();
    }

    public static function reset(): void
    {
        self::$pass = true;
        self::$confirms = true;
        self::$computeRuns = 0;
        self::$doubleCheckRuns = 0;
        self::$confirmRuns = 0;
        self::$completeRuns = 0;
        self::$finalizeRuns = 0;
        self::$startDelayMicroseconds = 0;
        self::$onCompute = null;
        self::$onDoubleCheck = null;
        self::$onComplete = null;
        self::$onConfirm = null;
        self::$afterExecute = null;
    }
}
