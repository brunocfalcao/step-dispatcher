<?php

declare(strict_types=1);

namespace StepDispatcher\Abstracts;

use Closure;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use StepDispatcher\Concerns\BaseStepJob\FormatsStepResult;
use StepDispatcher\Concerns\BaseStepJob\HandlesStepExceptions;
use StepDispatcher\Concerns\BaseStepJob\HandlesStepLifecycle;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Running;
use StepDispatcher\States\Skipped;
use StepDispatcher\Support\ExceptionParser;
use StepDispatcher\Support\RuntimeContext;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Support\Timing;
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

    /**
     * Queue-payload attempt snapshot used by the last-resort failed() guard.
     *
     * The dispatcher stamps the current retry counter and dispatch tick onto
     * the queued job. A later retry can have the same database state class
     * (Running), so state alone cannot prove that a failure callback still
     * owns the attempt it is reporting. Jobs created outside the dispatcher
     * retain the legacy state-only behavior when no snapshot is available.
     */
    public bool $stepAttemptSnapshotAvailable = false;

    public ?int $stepAttemptRetries = null;

    public ?int $stepAttemptTickId = null;

    /**
     * Set when this instance claims a row directly. Queue-level failed()
     * callbacks normally use the persisted dispatch tick instead because
     * Laravel restores the original queue payload before invoking failed().
     */
    public ?string $stepAttemptStartedAt = null;

    /**
     * Set once this instance has learned that its attempt no longer owns the
     * row. Every later write and consumer hook of the instance is then skipped.
     */
    private bool $stepAttemptSuperseded = false;

    /**
     * Set when one of this attempt's own guarded writes moved the row out of
     * Running, for example a retryJob() called from compute().
     */
    private bool $stepAttemptMovedStep = false;

    /**
     * Set when this attempt's outcome reached the row and a project's reaction
     * to it then failed. The outcome stands: there is nothing left to retry.
     */
    private bool $stepOutcomeSavedBeforeFailure = false;

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
     * Return the runtime timeout used by stale-step recovery.
     *
     * A null result keeps the legacy reflected `$timeout` property behavior.
     */
    public static function timeoutSeconds(): ?int
    {
        return null;
    }

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

            // A job built by the dispatcher already carries its queue
            // snapshot. Directly-created jobs have no serialized snapshot,
            // so capture the claimed row here for same-instance callbacks.
            if (! $this->stepAttemptSnapshotAvailable) {
                $this->stepAttemptSnapshotAvailable = true;
                $this->stepAttemptRetries = (int) $this->step->retries;
                $this->stepAttemptTickId = $this->step->tick_id === null
                    ? null
                    : (int) $this->step->tick_id;
            }

            $this->stepAttemptStartedAt = $this->step->started_at?->format('Y-m-d H:i:s.u');

            if ($this->isInConfirmationMode()) {
                $this->handleConfirmationMode();

                return;
            }

            if ($this->shouldExitEarly()) {
                return;
            }

            // A step lost during a start check must not begin its work, and
            // that includes what a consumer's executeJobLogic() does before
            // compute(). There is no check like this after executeJobLogic():
            // consumers release locks in finalizeJobExecution(), so that path
            // still runs and its own steps are guarded one by one. A job with
            // doubleCheck() differs: needsVerification() returns true for a
            // lost step, as it does for a failing double check, so that job
            // must also release its locks when needsVerification() is true.
            if ($this->attemptIsSuperseded()) {
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
            $parser = ExceptionParser::with($e);

            // failed() may run after a worker timeout while recovery,
            // cancellation, or completion has already changed the row. Lock
            // and re-read the authoritative state before writing any failure
            // payload, so an obsolete callback cannot overwrite the outcome
            // that won the race.
            $failureRecorded = $this->step->getConnection()->transaction(function () use ($parser, $e): bool {
                $locked = Step::query()
                    ->whereKey($this->step->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($locked === null) {
                    return false;
                }

                $this->step = $locked;

                // Only Running/Dispatched may fail here. Terminal states
                // (Cancelled, Stopped, …) and a recovered Pending step have
                // an authoritative outcome already; leave every field alone.
                if (! ($this->step->state instanceof Running || $this->step->state instanceof Dispatched)) {
                    return false;
                }

                if (! $this->failedAttemptIsCurrent($this->step)) {
                    return false;
                }

                $this->step->update([
                    'error_message' => $parser->friendlyMessage(),
                    'error_stack_trace' => $parser->stackTrace(),
                    'response' => ['exception' => $e->getMessage()],
                ]);

                $this->finalizeDuration();
                $this->step->state->transitionTo(Failed::class);

                return true;
            });

            if ($failureRecorded) {
                // This attempt moved the row itself, so its own failure must
                // not read as having lost the step.
                $this->stepAttemptMovedStep = true;

                // handle()'s catch block is the only other place that runs the
                // job's resolveException() hook, and a queue-level kill
                // (timeout, SIGKILL) never reaches it. Run the hook only after
                // the guarded failure transaction commits.
                $this->resolveExceptionIfPossible($e);
            }
        } finally {
            $context->pop();
        }
    }

    /**
     * Confirm that this failed callback still owns the current execution
     * attempt. The row lock in failed() makes the comparison authoritative.
     */
    private function failedAttemptIsCurrent(Step $current): bool
    {
        if (! $this->queuedGenerationIsCurrent($current)) {
            return false;
        }

        // A direct same-instance callback may have captured started_at. A
        // normal queue-level callback is restored from the original payload,
        // where started_at is still null; its tick/retry snapshot is the
        // authoritative comparison above.
        if ($this->stepAttemptStartedAt !== null) {
            return $current->started_at?->format('Y-m-d H:i:s.u') === $this->stepAttemptStartedAt;
        }

        return true;
    }

    /**
     * Run a post-run write only while this attempt still owns the row.
     *
     * A worker can outlive its claim: recovery may re-queue the step while the
     * old worker is still alive, and another process may stop or cancel it.
     * The model transitions validate only this instance's copy of the state,
     * so the row is locked and compared first, as failed() does. A refused
     * attempt changes nothing and stays refused for the rest of the run.
     *
     * A parent the dispatcher completed under this attempt keeps that result.
     * A write that would decide the outcome is left out, and the attempt carries
     * on. Only a write that cannot change the outcome passes
     * runsOnCompletedParent. The write receives the locked row, or this
     * attempt's own copy when ownership does not apply.
     *
     * Consumer transactions may wrap this call. The lock is taken where the
     * write would have locked the row anyway, so the lock order is unchanged.
     */
    private function whileAttemptOwnsStep(Closure $write, bool $runsOnCompletedParent = false): void
    {
        if ($this->stepAttemptSuperseded) {
            return;
        }

        if (! $this->attemptOwnershipApplies()) {
            $write($this->step);

            return;
        }

        $completedUnderAttempt = false;
        $stateBeforeWrite = get_class($this->step->state);

        try {
            $owned = Step::whileRowLocked($this->step->getKey(), function (?Step $locked) use ($write, $runsOnCompletedParent, &$completedUnderAttempt): bool {
                if (! $this->attemptOwnsRow($locked)) {
                    return false;
                }

                $completedUnderAttempt = $this->rowCompletedUnderAttempt($locked);

                if ($completedUnderAttempt && ! $runsOnCompletedParent) {
                    return true;
                }

                $write($locked);

                return true;
            });
        } catch (Throwable $e) {
            $this->settleCopyAfterFailedWrite($stateBeforeWrite);

            throw $e;
        }

        if (! $owned) {
            $this->markAttemptSuperseded();

            return;
        }

        if ($completedUnderAttempt) {
            if (! $runsOnCompletedParent) {
                $this->logKeptCompletedResult();
            }

            return;
        }

        if (! ($this->step->state instanceof Running)) {
            $this->stepAttemptMovedStep = true;
        }
    }

    /**
     * A failed write can leave this copy holding a state the row never got, or a
     * saved state still marked as unsaved. Its next save would then write the
     * refused state or fire a project's reaction a second time, so the copy goes
     * back to the row.
     *
     * A write that moved the state and reached the row was saved, and only a
     * project's reaction to it failed: the attempt must not read that move as
     * having lost the step. Anything else saved no outcome, whatever this copy
     * says. A failed read proves nothing either way, and must not replace the
     * failure being reported: a statement in a transaction Postgres aborted always
     * fails. The copy then goes back to what it last read from the row.
     */
    private function settleCopyAfterFailedWrite(string $stateBeforeWrite): void
    {
        $attemptedState = get_class($this->step->state);

        try {
            $row = Step::query()->whereKey($this->step->getKey())->first();
        } catch (Throwable) {
            $row = null;
        }

        if ($row !== null
            && $attemptedState !== $stateBeforeWrite
            && get_class($row->state) === $attemptedState) {
            $this->stepAttemptMovedStep = true;
            $this->stepOutcomeSavedBeforeFailure = true;
        }

        $this->returnCopyToRow($row);
    }

    /**
     * A project's own transaction can undo a state this attempt saved, as the
     * failure unwinds through it. The row is then Running under this same attempt,
     * so the step has not been lost: it is still this attempt's to conclude.
     */
    private function reclaimStepAfterProjectRollback(): void
    {
        if (! $this->stepAttemptMovedStep
            || $this->step->state instanceof Running
            || ! $this->attemptOwnershipApplies()) {
            return;
        }

        try {
            $row = Step::query()->whereKey($this->step->getKey())->first();
        } catch (Throwable) {
            return;
        }

        if ($row === null || ! ($row->state instanceof Running) || ! $this->failedAttemptIsCurrent($row)) {
            return;
        }

        $this->stepAttemptMovedStep = false;
        $this->stepOutcomeSavedBeforeFailure = false;
        $this->stepStatusUpdated = false;

        $this->returnCopyToRow($row);
    }

    /**
     * In this order: asking setRawAttributes() to sync would sync before the cached
     * state is cleared, and that cache would put the refused state straight back.
     */
    private function returnCopyToRow(?Step $row): void
    {
        $this->step->setRawAttributes($row?->getRawOriginal() ?? $this->step->getRawOriginal());
        $this->step->syncOriginal();
    }

    /**
     * Read-only check before a consumer hook. No lock is held across the hook,
     * because hooks can make slow outside calls; the write that follows a hook
     * goes through whileAttemptOwnsStep() and makes the authoritative check.
     */
    private function attemptIsSuperseded(): bool
    {
        if ($this->stepAttemptSuperseded) {
            return true;
        }

        if (! $this->attemptOwnershipApplies()) {
            return false;
        }

        if ($this->attemptOwnsRow(Step::query()->whereKey($this->step->getKey())->first())) {
            return false;
        }

        $this->markAttemptSuperseded();

        return true;
    }

    /**
     * Ownership is only defined for an attempt claimed through handle(). A
     * helper called directly keeps its plain behaviour.
     */
    private function attemptOwnershipApplies(): bool
    {
        return $this->stepAttemptStartedAt !== null;
    }

    /**
     * A claimed attempt owns the row while it is Running in the attempt's own
     * generation. A parent is the one exception: the dispatcher's sweep can
     * complete it once its children conclude, while its compute() is still
     * finishing. That attempt has not been replaced by anything, so it still
     * stores its result and runs its hooks, but it no longer decides the outcome.
     *
     * An attempt that moved the row itself can no longer be compared by
     * generation, because its own move changed it. It still finishes its run,
     * for example storing the result returned after a retryJob(), but only
     * while the row still shows that move: a successor's claim or an
     * operator's cancel changes it. A consumer transaction that rolled the
     * move back changes it too, until the consumer reloads its copy. The
     * throttle flag tells this attempt's retry apart from a successor that
     * rescheduled, which leaves the row Pending with the same retry count.
     */
    private function attemptOwnsRow(?Step $row): bool
    {
        if ($row === null) {
            return false;
        }

        if ($this->stepAttemptMovedStep && ! ($this->step->state instanceof Running)) {
            return get_class($row->state) === get_class($this->step->state)
                && (int) $row->retries === (int) $this->step->retries
                && (bool) $row->is_throttled === (bool) $this->step->is_throttled;
        }

        if (! $this->failedAttemptIsCurrent($row)) {
            return false;
        }

        return $row->state instanceof Running
            || ($row->state instanceof Completed && $row->isParent());
    }

    /**
     * The dispatcher completed this parent under this attempt: the row is
     * Completed and the attempt did not move it. attemptOwnsRow() refuses any
     * other Completed row, and a helper called directly keeps its plain behaviour.
     */
    private function rowCompletedUnderAttempt(Step $row): bool
    {
        return $this->attemptOwnershipApplies()
            && ! $this->stepAttemptMovedStep
            && $row->state instanceof Completed;
    }

    /**
     * Whether a failure this attempt reports stays on record only, because the
     * dispatcher already completed this parent. The error text and duration are
     * still stored; the outcome is not changed.
     */
    private function keepsCompletedResult(Step $row): bool
    {
        if (! $this->rowCompletedUnderAttempt($row)) {
            return false;
        }

        $this->logKeptCompletedResult();

        return true;
    }

    private function logKeptCompletedResult(): void
    {
        Step::log($this->step->id, 'states', 'Parent kept its completed result | the decision of its own job was not applied');

        Log::warning('Parent step kept its completed result', [
            'step_id' => $this->step->id,
            'class' => static::class,
        ]);
    }

    private function markAttemptSuperseded(): void
    {
        $this->stepAttemptSuperseded = true;
        $this->stepStatusUpdated = true;

        Step::log($this->step->id, 'states', sprintf(
            'Obsolete attempt stopped writing | attempt_retries=%s | attempt_started_at=%s',
            $this->stepAttemptRetries ?? 'n/a',
            $this->stepAttemptStartedAt ?? 'n/a'
        ));

        Log::warning('Obsolete step attempt stopped writing', [
            'step_id' => $this->step->id,
            'class' => static::class,
            'attempt_retries' => $this->stepAttemptRetries,
            'attempt_started_at' => $this->stepAttemptStartedAt,
        ]);
    }

    /**
     * Compare the queued dispatch generation with the locked row. A dispatch
     * tick is the durable generation marker, and a dispatch with no current
     * tick is its own generation: the row must have no tick either. Retries
     * cover redeliveries of the same tick. Jobs without a snapshot (queued by
     * an older release, or created outside the dispatcher) keep the legacy
     * state-only behavior.
     */
    private function queuedGenerationIsCurrent(Step $current): bool
    {
        if (! $this->stepAttemptSnapshotAvailable) {
            return true;
        }

        $currentTickId = $current->tick_id === null ? null : (int) $current->tick_id;

        if ($currentTickId !== $this->stepAttemptTickId) {
            return false;
        }

        return $this->stepAttemptRetries === null
            || (int) $current->retries === $this->stepAttemptRetries;
    }

    final public function startDuration(): void
    {
        $this->startMicrotime = microtime(true);
    }

    final public function finalizeDuration(): void
    {
        // startMicrotime is only stamped when handle() actually ran. When
        // failed() fires for a job that never started (Horizon timeout
        // while queued), computing (now - 0.0) would persist a ~55-year
        // duration; leave duration unknown instead.
        if ($this->startMicrotime <= 0.0) {
            return;
        }

        $this->step->update(['duration' => Timing::elapsedMs($this->startMicrotime)]);
    }

    final public function uuid(): string
    {
        return $this->step->child_block_uuid ?? Str::uuid()->toString();
    }

    /**
     * Determine if this step should be escalated to high priority.
     * Default: escalate when step has reached 50% of max retries.
     * Override in child jobs for custom priority escalation logic.
     *
     * Jobs with a non-positive retry budget never escalate — a zero
     * budget would make the threshold 0.0 and flag every step 'high'
     * before its first retry.
     */
    protected function shouldChangeToHighPriority(): bool
    {
        return $this->retries > 0 && $this->step->retries >= ($this->retries / 2);
    }

    protected function prepareJobExecution(): bool
    {
        // The start is a claim: the row stays locked from the state read to
        // the Running save, so a canceller that changes the state in between
        // either lands first (and the guards below see it) or waits and then
        // finds the step already Running. Never both.
        //
        // The claim runs up to three times. A deadlock rolls all of it back,
        // and without another try the step would end Failed having never run.
        $refusedByAncestor = false;

        $started = $this->step->getConnection()->transaction(
            function () use (&$refusedByAncestor): bool {
                return $this->claimStart($refusedByAncestor);
            },
            3,
        );

        // Moved here rather than left to the skip sweep, which works one group
        // at a time and would never reach a step whose ancestor is in another.
        if ($refusedByAncestor) {
            StepDispatcher::batchTransitionSteps([$this->step->getKey()], Skipped::class, [Running::class]);
        }

        if (! $started) {
            return false;
        }

        $this->startDuration();
        $this->attachRelatable();

        // Initialize database exception handler (auto-detect DB driver)
        $this->databaseExceptionHandler = BaseDatabaseExceptionHandler::make();

        return true;
    }

    private function claimStart(bool &$refusedByAncestor): bool
    {
        $refusedByAncestor = false;

        Step::query()->whereKey($this->step->getKey())->lockForUpdate()->first(['id']);

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

        // A job delivered after recovery re-queued its step belongs to an
        // older dispatch generation. The newer dispatch owns the run; letting
        // the old job start would also leave its later failed() callback
        // rejected by the generation guard there.
        if (! $this->queuedGenerationIsCurrent($this->step)) {
            return false;
        }

        // Nothing starts under a Skipped ancestor, whatever was true when the
        // dispatcher chose this step. The caller moves the step to Skipped once
        // this transaction ends. The ancestors stay share-locked until the
        // Running save commits: see Step::hasSkippedAncestor.
        if ($this->step->hasSkippedAncestor(holdRows: true)) {
            Step::log($this->step->id, 'states', 'Start refused | an ancestor is Skipped');

            $refusedByAncestor = true;

            return false;
        }

        $this->step->state->transitionTo(Running::class);

        // The transition persists started_at through the model's date
        // formatter and the database column may retain less precision than
        // Carbon::now(). Canonicalize the claimed attempt from the row that
        // failed() will later lock and reload, so same-instance callbacks do
        // not compare an unsaved fractional timestamp to persisted data.
        $this->step->refresh();

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
            if (! $this->stepStatusUpdated) {
                $this->retryJob();
            }

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
