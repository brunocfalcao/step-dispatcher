<?php

declare(strict_types=1);

namespace StepDispatcher\Abstracts;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Log;
use RuntimeException;
use StepDispatcher\Concerns\BaseStepJob\FormatsStepResult;
use StepDispatcher\Concerns\BaseStepJob\HandlesStepExceptions;
use StepDispatcher\Concerns\BaseStepJob\HandlesStepLifecycle;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Running;
use StepDispatcher\Support\ExceptionParser;
use Throwable;

/*
 * BaseStepJob
 *
 * - Core abstract class for all step-based jobs in the StepDispatcher system.
 * - Manages lifecycle transitions, status guards, retries, skips, and failures.
 * - Integrates with `Step` model and uses traits for flow control.
 * - Supports optional "confirming-completion" mode via `confirmCompletionOrRetry()`.
 * - Executes compute logic and handles result formatting/storage.
 * - Provides structured logging and exception capture for robust debugging.
 * - Designed to be extended by consuming packages that add domain-specific logic.
 */
abstract class BaseStepJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use FormatsStepResult;
    use HandlesStepExceptions;
    use HandlesStepLifecycle;

    public Step $step;

    public int $jobBackoffSeconds = 10;

    public bool $stepStatusUpdated = false;

    public float $startMicrotime = 0.0;

    public ?BaseDatabaseExceptionHandler $databaseExceptionHandler = null;

    // Must be implemented by subclasses to define the compute logic.
    abstract protected function compute();

    final public function handle(): void
    {
        try {
            if (! $this->prepareJobExecution()) {
                return;
            }

            if ($this->isInConfirmationMode()) {
                $this->handleConfirmationMode();
                return;
            }

            if ($this->shouldExitEarly()) {
                return;
            }

            $this->executeJobLogic();

            if ($this->needsVerification()) {
                return;
            }

            $this->finalizeJobExecution();
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    final public function failed(Throwable $e): void
    {
        /*
         * Last-resort handler if the Laravel queue system catches an unhandled error.
         * This is called when Horizon kills a job due to timeout or other unhandled exceptions.
         * Update step error_message, error_stack_trace, and transition to Failed state.
         */

        // Check if step property is initialized before accessing it
        if (! isset($this->step)) {
            // Job failed before step was initialized - log and exit
            Log::error('[JOB FAILED] Job failed before step initialization: '.$e->getMessage());

            return;
        }

        $stepId = $this->step->id;

        // Parse exception for friendly message and stack trace
        $parser = ExceptionParser::with($e);

        // Update error_message, error_stack_trace, and response
        $this->step->update([
            'error_message' => $parser->friendlyMessage(),
            'error_stack_trace' => $parser->stackTrace(),
            'response' => ['exception' => $e->getMessage()],
        ]);

        // Finalize duration
        $this->finalizeDuration();

        // Transition to Failed state (only if not already in a terminal state)
        if (! $this->step->state instanceof Failed) {
            $this->step->state->transitionTo(Failed::class);
        }
    }

    final public function startDuration(): void
    {
        $this->startMicrotime = microtime(true);
    }

    final public function finalizeDuration(): void
    {
        $durationMs = abs((int) ((microtime(true) - $this->startMicrotime) * 1000));

        $this->step->update(['duration' => $durationMs]);
    }

    final public function uuid(): string
    {
        return $this->step->child_block_uuid ?? Str::uuid()->toString();
    }

    /**
     * Determine if this step should be escalated to high priority.
     * Default: escalate when step has reached 50% of max retries.
     * Override in child jobs for custom priority escalation logic.
     */
    protected function shouldChangeToHighPriority(): bool
    {
        return $this->step->retries >= ($this->retries / 2);
    }

    protected function prepareJobExecution(): bool
    {
        // Refresh step from database to get latest state (it should be Dispatched)
        $this->step->refresh();

        // Guard against duplicate execution - if step is already Running,
        // this is a retry from Horizon after a timeout/crash.
        if ($this->step->state instanceof Running) {
            return false;
        }

        $this->step->state->transitionTo(Running::class);
        $this->startDuration();
        $this->attachRelatable();

        // Initialize database exception handler (auto-detect DB driver)
        $this->databaseExceptionHandler = BaseDatabaseExceptionHandler::make();

        return true;
    }

    protected function isInConfirmationMode(): bool
    {
        return $this->shouldRunConfirmingCompletionMode();
    }

    protected function handleConfirmationMode(): void
    {
        $this->confirmCompletionOrRetry();
    }

    protected function shouldExitEarly(): bool
    {
        if (! $this->shouldStartOrStop()) {
            $this->stopJob();

            return true;
        }

        if (! $this->shouldStartOrFail()) {
            throw new RuntimeException("startOrFail() returned false for Step ID {$this->step->id}");
        }

        if (! $this->shouldStartOrSkip()) {
            $this->skipJob();

            return true;
        }

        if (! $this->shouldStartOrRetry()) {
            $this->retryJob();

            return true;
        }

        // Check max retries after business logic checks
        $this->checkMaxRetries();

        return false;
    }

    protected function executeJobLogic(): void
    {
        if ($this->step->double_check === 0) {
            $this->computeAndStoreResult();
        }
    }

    protected function needsVerification(): bool
    {
        if ($this->shouldDoubleCheck()) {
            return true;
        }

        if (! $this->shouldConfirmOrRetry()) {
            $this->retryForConfirmation();

            return true;
        }

        return false;
    }

    protected function finalizeJobExecution(): void
    {
        if ($this->shouldComplete()) {
            $this->complete();
        }

        $this->completeIfNotHandled();
    }

    // ========================================================================
    // HOOK METHODS (Override in consuming packages for domain-specific behavior)
    // ========================================================================

    /**
     * External retry check hook.
     * Override to delegate retry decisions to external exception handlers (e.g., API handlers).
     */
    protected function externalRetryException(Throwable $e): bool
    {
        return false;
    }

    /**
     * External ignore check hook.
     * Override to delegate ignore decisions to external exception handlers.
     */
    protected function externalIgnoreException(Throwable $e): bool
    {
        return false;
    }

    /**
     * External resolve hook.
     * Override to delegate exception resolution to external handlers.
     */
    protected function externalResolveException(Throwable $e): void
    {
        // No-op by default
    }
}
