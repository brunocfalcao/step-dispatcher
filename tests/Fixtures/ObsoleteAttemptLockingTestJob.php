<?php

declare(strict_types=1);

namespace StepDispatcher\Tests\Fixtures;

use Closure;
use Illuminate\Support\Facades\DB;
use StepDispatcher\Abstracts\BaseStepJob;
use StepDispatcher\Models\Step;
use Throwable;

/**
 * Test fixture shaped like a consumer that takes an outside lock for the run
 * and releases it in `finally` blocks around the engine's own steps, reloads
 * its copy of the step on the way, and settles failures inside its own
 * transaction. A lost step must still let every one of those blocks run.
 */
final class ObsoleteAttemptLockingTestJob extends BaseStepJob
{
    public int $retries = 5;

    public static ?Closure $whileRunning = null;

    public static bool $lockHeld = false;

    public static bool $reschedulesFailures = false;

    public static function reset(): void
    {
        self::$whileRunning = null;
        self::$lockHeld = false;
        self::$reschedulesFailures = false;
    }

    protected function compute(): mixed
    {
        (self::$whileRunning)($this);

        return ['from' => 'locking attempt'];
    }

    protected function executeJobLogic(): void
    {
        self::$lockHeld = true;

        parent::executeJobLogic();
    }

    protected function needsVerification(): bool
    {
        $needsVerification = false;

        try {
            $this->step->refresh();

            if (in_array(get_class($this->step->state), Step::terminalStepStates(), true)) {
                return $needsVerification = true;
            }

            return $needsVerification = parent::needsVerification();
        } finally {
            if ($needsVerification) {
                self::$lockHeld = false;
            }
        }
    }

    protected function finalizeJobExecution(): void
    {
        try {
            parent::finalizeJobExecution();
        } finally {
            self::$lockHeld = false;
        }
    }

    protected function handleException(Throwable $e): void
    {
        try {
            DB::transaction(function () use ($e): void {
                $this->step->refresh();

                if (in_array(get_class($this->step->state), Step::terminalStepStates(), true)) {
                    return;
                }

                if (self::$reschedulesFailures) {
                    $this->rescheduleWithoutRetry(now()->addSeconds(30));

                    return;
                }

                parent::handleException($e);
            });
        } finally {
            self::$lockHeld = false;
        }
    }
}
