<?php

declare(strict_types=1);

namespace StepDispatcher\Tests\Fixtures;

use StepDispatcher\Abstracts\BaseStepJob;

/**
 * Test fixture for the confirming-completion execution mode. confirmOrRetry()
 * returns the static toggle so a test can drive both entry points:
 *   - normal run whose verification fails → step flips to confirming-completion
 *     mode and reschedules,
 *   - a step already in confirming-completion mode → confirmOrRetry decides
 *     complete-vs-retry WITHOUT re-running compute().
 */
final class ConfirmCompletionTestJob extends BaseStepJob
{
    public int $retries = 5;

    public static bool $confirm = true;

    public static int $computeRuns = 0;

    protected function compute(): mixed
    {
        static::$computeRuns++;

        return ['v' => 1];
    }

    public function confirmOrRetry(): bool
    {
        return static::$confirm;
    }

    public static function reset(): void
    {
        static::$confirm = true;
        static::$computeRuns = 0;
    }
}
