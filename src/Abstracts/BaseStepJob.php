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
use StepDispatcher\Support\RuntimeContext;
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

    /**
     * Runtime prefix this step belongs to. Stamped onto the job
     * payload when DispatchesJobs::dispatchSingleStep() pushes the
     * job to the queue, so the worker (which boots in a fresh
     * process / scoped container with an empty prefix stack) can
     * restore the right ambient prefix BEFORE any DB read against
     * the Step model. Empty string `''` keeps the default unprefixed
     * behaviour for hosts that aren't using the prefix feature.
     */
    public string $stepPrefix = '';

    public int $jobBackoffSeconds = 10;

    /**
     * Optional millisecond-precision backoff. When set to a positive value,
     * `retryJob()` and `rescheduleWithoutRetry()` use `addMilliseconds($jobBackoffMs)`
     * instead of `addSeconds($jobBackoffSeconds)`. Lets callers that need
     * sub-second retry precision (e.g. the API throttler path) schedule
     * retries at the exact remainder of the configured min-delay instead
     * of being forced up to the next whole second. Zero disables the
     * override and the legacy seconds-based backoff applies.
     */
    public int $jobBackoffMs = 0;

    public bool $stepStatusUpdated = false;

    public float $startMicrotime = 0.0;

    public ?BaseDatabaseExceptionHandler $databaseExceptionHandler = null;

    // Must be implemented by subclasses to define the compute logic.
    abstract protected function compute();

    /**
     * Restore prefix BEFORE the SerializesModels trait re-fetches
     * the Step model from the queue payload. Laravel's CallQueuedHandler
     * calls `__unserialize()` immediately after pulling the job off
     * the queue — that pass calls `getRestoredPropertyValue()` per
     * model property, which issues a `Model::find()` against the
     * Step table. Without the prefix on the RuntimeContext stack at
     * that moment, the find queries the default `steps` table for a
     * row that lives in `trading_steps` (or whichever prefix), and
     * `findOrFail()` throws ModelNotFoundException — every prefixed
     * job lands in the failed_jobs bucket.
     *
     * The prefix is read from the raw payload values (where it is a
     * plain string, not yet assigned to `$this->stepPrefix`) so the
     * push happens BEFORE the trait's restoration loop runs. After
     * the trait restores all properties we pop — the matching push
     * for the actual handle() execution is added by handle() itself.
     */
    public function __unserialize(array $values): void
    {
        $prefix = '';

        if (array_key_exists('stepPrefix', $values) && is_string($values['stepPrefix'])) {
            $prefix = $values['stepPrefix'];
        }

        $context = app(RuntimeContext::class);
        $context->push($prefix);

        try {
            // Delegate to the trait's restoration logic. Calling
            // it via `parent::__unserialize` is not an option (it's
            // a trait method), so we replicate the trait's loop
            // here verbatim — this is the intentional "decorator"
            // pattern for trait methods that need preconditions.
            $properties = (new \ReflectionClass($this))->getProperties();
            $class = static::class;

            foreach ($properties as $property) {
                if ($property->isStatic()) {
                    continue;
                }

                $name = $property->getName();

                if ($property->isPrivate()) {
                    $name = "\0{$class}\0{$name}";
                } elseif ($property->isProtected()) {
                    $name = "\0*\0{$name}";
                }

                if (! array_key_exists($name, $values)) {
                    continue;
                }

                $property->setValue(
                    $this,
                    $this->getRestoredPropertyValue($values[$name])
                );
            }
        } finally {
            $context->pop();
        }
    }

    final public function handle(): void
    {
        // Restore the ambient prefix the dispatcher tick stamped onto
        // this job. Must happen BEFORE prepareJobExecution() — the
        // very first call there is `$this->step->refresh()` which
        // resolves the Step model's table from the active prefix.
        // The earlier push in __unserialize() already popped after
        // model restoration; this push covers the entire handle()
        // execution body and is balanced by the matching pop in the
        // finally block below.
        $context = app(RuntimeContext::class);
        $context->push($this->stepPrefix);

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
        } finally {
            $context->pop();
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

        // Laravel calls failed() directly (bypassing handle()), so
        // the prefix push from handle() is not active here. Restore
        // the ambient prefix the same way handle() does, with the
        // matching pop in finally so a throw inside still cleans up.
        $context = app(RuntimeContext::class);
        $context->push($this->stepPrefix);

        try {
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
        } finally {
            $context->pop();
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

        // Guard against terminal state execution - if the step was cancelled,
        // failed, completed, stopped, or skipped between dispatch and worker
        // pickup, bail out silently. Without this guard, attempting an
        // unregistered transition (e.g. Cancelled → Running) throws an
        // exception that the error handler also can't recover from
        // (Cancelled → Failed is also unregistered), creating an infinite
        // retry loop under Horizon's --tries=0 configuration.
        $stateClass = get_class($this->step->state);
        if (in_array($stateClass, Step::terminalStepStates(), strict: true)) {
            return false;
        }

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
        $this->shouldComplete();
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
