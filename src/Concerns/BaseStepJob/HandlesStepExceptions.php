<?php

declare(strict_types=1);

namespace StepDispatcher\Concerns\BaseStepJob;

use StepDispatcher\Exceptions\MaxRetriesReachedException;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Failed;
use StepDispatcher\Support\ExceptionParser;
use Throwable;

/**
 * Trait HandlesStepExceptions
 *
 * Centralizes exception handling for Step-based jobs.
 * Supports retrying, ignoring, or resolving exceptions based on
 * custom job logic or delegated exception handlers.
 */
trait HandlesStepExceptions
{
    public function reportAndFail(Throwable $e): void
    {
        // Guard against accessing step before initialization
        if (! isset($this->step)) {
            return;
        }

        $parser = ExceptionParser::with($e);

        $this->whileAttemptOwnsStep(function (Step $row) use ($parser): void {
            // A stale-detector requeue note only says an earlier attempt
            // stalled; the failure of this attempt is the error worth keeping.
            // A message the job wrote itself still wins.
            if (is_null($this->step->error_message) || self::isRequeueNote($this->step->error_message)) {
                $this->step->update([
                    'error_message' => $parser->friendlyMessage(),
                    'error_stack_trace' => $parser->stackTrace(),
                ]);
            }

            $this->finalizeDuration();

            if ($this->keepsCompletedResult($row)) {
                return;
            }

            $this->step->state->transitionTo(Failed::class);
        }, runsOnCompletedParent: true);
    }

    private static function isRequeueNote(string $message): bool
    {
        return str_starts_with($message, 'Recovered by stale detector:')
            && str_contains($message, ', retrying (');
    }
    // ========================================================================
    // MAIN EXCEPTION HANDLER
    // ========================================================================

    protected function handleException(Throwable $e): void
    {
        $stepId = $this->step->id ?? 'unknown';

        Step::log($stepId === 'unknown' ? null : $stepId, 'exceptions', sprintf(
            'Caught %s | message=%s',
            class_basename($e),
            mb_substr($e->getMessage(), 0, 300)
        ));

        $this->reclaimStepAfterProjectRollback();

        // This attempt's outcome is saved, and the failure came from a project's
        // reaction to it. Nothing is left to decide or to retry: the error goes
        // on record and the queue still hears about the failure.
        if ($this->stepOutcomeSavedBeforeFailure) {
            try {
                $this->logExceptionToStep($e);
            } catch (Throwable) {
                // The failure already being reported is the one that matters.
            }

            throw $e;
        }

        // A failure in an attempt that has already lost its step belongs to
        // nobody: failed() skips resolveException() in this case too, and the
        // attempt that owns the row now decides the step's outcome.
        if ($this->attemptIsSuperseded()) {
            return;
        }

        if ($this->isShortcutException($e)) {
            Step::log($stepId === 'unknown' ? null : $stepId, 'exceptions', 'Handler decision: shortcut (MaxRetriesReached)');
            $this->handleShortcutException($e);

            return;
        }

        // Check for permanent database errors (syntax, schema issues) - fail immediately
        if ($this->isPermanentDatabaseError($e)) {
            Step::log($stepId === 'unknown' ? null : $stepId, 'exceptions', 'Handler decision: permanent DB error → fail immediately');
            $this->reportAndFail($e);

            return;
        }

        if ($this->shouldRetryException($e)) {
            Step::log($stepId === 'unknown' ? null : $stepId, 'exceptions', 'Handler decision: retry');
            $this->retryJobWithBackoff($e);

            return;
        }

        if ($this->shouldIgnoreException($e)) {
            Step::log($stepId === 'unknown' ? null : $stepId, 'exceptions', 'Handler decision: ignore → complete');
            $this->completeAndIgnoreException();

            return;
        }

        Step::log($stepId === 'unknown' ? null : $stepId, 'exceptions', 'Handler decision: resolve or fail');

        $this->logExceptionToStep($e);

        // Hook for subclasses to add custom exception logging (e.g., appLog on relatable)
        if (! $this->stepAttemptSuperseded) {
            $this->onExceptionLogged($e);
        }

        $this->resolveExceptionIfPossible($e);

        if (! $this->stepStatusUpdated) {
            $this->reportAndFail($e);
        }
    }

    // ========================================================================
    // EXCEPTION CLASSIFICATION
    // ========================================================================

    protected function isShortcutException(Throwable $e): bool
    {
        return $e instanceof MaxRetriesReachedException;
    }

    protected function isPermanentDatabaseError(Throwable $e): bool
    {
        if (isset($this->databaseExceptionHandler)
            && $this->databaseExceptionHandler->isPermanentError($e)
        ) {
            return true;
        }

        return false;
    }

    protected function shouldRetryException(Throwable $e): bool
    {
        // PRIORITY 1: Database handler (transient DB errors - deadlocks, connection failures)
        if (isset($this->databaseExceptionHandler)
            && $this->databaseExceptionHandler->shouldRetry($e)
        ) {
            return true;
        }

        // PRIORITY 2: Job-specific retry logic
        if (method_exists($this, 'retryException') && $this->retryException($e)) {
            return true;
        }

        // PRIORITY 3: External handler hook (e.g., API exception handler)
        if ($this->externalRetryException($e)) {
            return true;
        }

        return false;
    }

    protected function shouldIgnoreException(Throwable $e): bool
    {
        // PRIORITY 1: Job-specific ignore logic
        if (method_exists($this, 'ignoreException') && $this->ignoreException($e)) {
            return true;
        }

        // PRIORITY 2: External handler hook (e.g., API exception handler)
        if ($this->externalIgnoreException($e)) {
            return true;
        }

        // PRIORITY 3: Database handler (very rare - idempotent duplicate entries)
        if (isset($this->databaseExceptionHandler)
            && $this->databaseExceptionHandler->shouldIgnore($e)
        ) {
            return true;
        }

        return false;
    }

    // ========================================================================
    // EXCEPTION RESOLUTION
    // ========================================================================

    protected function handleShortcutException(Throwable $e): void
    {
        $this->resolveExceptionIfPossible($e);

        if (! $this->stepStatusUpdated) {
            $this->reportAndFail($e);
        }
    }

    protected function resolveExceptionIfPossible(Throwable $e): void
    {
        if (method_exists($this, 'resolveException') && ! $this->attemptIsSuperseded()) {
            $this->resolveException($e);
        }

        // External handler hook (e.g., API exception handler)
        if (! $this->attemptIsSuperseded()) {
            $this->externalResolveException($e);
        }
    }

    // ========================================================================
    // EXCEPTION ACTIONS
    // ========================================================================

    protected function retryJobWithBackoff(Throwable $e): void
    {
        // Guard against accessing step before initialization
        if (! isset($this->step)) {
            return;
        }

        // Database-specific exceptions use their own exponential backoff
        // (still second-precision — sub-ms retries don't help connection
        // recovery). For everything else, delegate to retryJob() so it can
        // pick up the `jobBackoffMs` override when sub-second precision is
        // warranted (e.g. the API throttler path after a min-delay deficit).
        if (isset($this->databaseExceptionHandler)
            && $this->databaseExceptionHandler->shouldRetry($e)
        ) {
            $backoffSeconds = $this->databaseExceptionHandler->getBackoffSeconds($this->step->retries);

            // Pass the computed dispatch time directly to retryJob() so
            // its internal "resolve dispatch time" logic doesn't
            // overwrite the database-specific backoff. Pre-fix, this
            // method computed the backoff, wrote `dispatch_after`, then
            // called `retryJob()` with no argument — retryJob then
            // re-resolved its own dispatch time and overwrote the
            // value, defeating the database-saturation/deadlock
            // backoff and producing a tighter retry loop than intended.
            $this->retryJob(now()->addSeconds($backoffSeconds));

            return;
        }

        $this->retryJob();
    }

    protected function completeAndIgnoreException(): void
    {
        // Guard against accessing step before initialization
        if (! isset($this->step)) {
            return;
        }

        $this->whileAttemptOwnsStep(function (Step $row): void {
            $this->finalizeDuration();

            // Same rule as completeIfNotHandled(): an unconcluded parent stays
            // Running for the dispatcher's transitionParentsToComplete sweep.
            if ($this->rowCompletedUnderAttempt($row) || $this->parentMustAwaitChildren()) {
                return;
            }

            $this->step->state->transitionTo(Completed::class);
        }, runsOnCompletedParent: true);

        $this->stepStatusUpdated = true;
    }

    // ========================================================================
    // EXCEPTION LOGGING
    // ========================================================================

    protected function logExceptionToStep(Throwable $e): void
    {
        // Guard against accessing step before initialization
        if (! isset($this->step)) {
            return;
        }

        $parser = ExceptionParser::with($e);

        $this->whileAttemptOwnsStep(function () use ($parser): void {
            if (is_null($this->step->error_message)) {
                $this->step->updateSaving([
                    'error_message' => $parser->friendlyMessage(),
                ]);
            }

            if (is_null($this->step->error_stack_trace)) {
                $this->step->updateSaving([
                    'error_stack_trace' => $parser->stackTrace(),
                ]);
            }
        }, runsOnCompletedParent: true);
    }

    /**
     * Hook for subclasses to add custom logging after an exception is logged to the step.
     * Default: no-op. Override to add domain-specific logging (e.g., appLog on relatable).
     */
    protected function onExceptionLogged(Throwable $e): void
    {
        // No-op by default - override in subclasses
    }
}
