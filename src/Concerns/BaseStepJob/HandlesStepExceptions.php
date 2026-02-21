<?php

declare(strict_types=1);

namespace StepDispatcher\Concerns\BaseStepJob;

use StepDispatcher\Exceptions\MaxRetriesReachedException;
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

        if (is_null($this->step->error_message)) {
            $this->step->update([
                'error_message' => $parser->friendlyMessage(),
                'error_stack_trace' => $parser->stackTrace(),
            ]);
        }

        $this->finalizeDuration();
        $this->step->state->transitionTo(Failed::class);
    }
    // ========================================================================
    // MAIN EXCEPTION HANDLER
    // ========================================================================

    protected function handleException(Throwable $e): void
    {
        $stepId = $this->step->id ?? 'unknown';

        if ($this->isShortcutException($e)) {
            $this->handleShortcutException($e);

            return;
        }

        // Check for permanent database errors (syntax, schema issues) - fail immediately
        if ($this->isPermanentDatabaseError($e)) {
            $this->reportAndFail($e);

            return;
        }

        if ($this->shouldRetryException($e)) {
            $this->retryJobWithBackoff($e);

            return;
        }

        if ($this->shouldIgnoreException($e)) {
            $this->completeAndIgnoreException();

            return;
        }

        $this->logExceptionToStep($e);

        // Hook for subclasses to add custom exception logging (e.g., appLog on relatable)
        $this->onExceptionLogged($e);

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
        if (method_exists($this, 'resolveException')) {
            $this->resolveException($e);
        }

        // External handler hook (e.g., API exception handler)
        $this->externalResolveException($e);
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

        $backoffSeconds = $this->jobBackoffSeconds;

        // Use exponential backoff for database exceptions
        if (isset($this->databaseExceptionHandler)
            && $this->databaseExceptionHandler->shouldRetry($e)
        ) {
            $backoffSeconds = $this->databaseExceptionHandler->getBackoffSeconds($this->step->retries);
        }

        $this->step->update([
            'dispatch_after' => now()->addSeconds($backoffSeconds),
        ]);

        $this->retryJob();
    }

    protected function completeAndIgnoreException(): void
    {
        // Guard against accessing step before initialization
        if (! isset($this->step)) {
            return;
        }

        $this->finalizeDuration();
        $this->step->state->transitionTo(Completed::class);
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
