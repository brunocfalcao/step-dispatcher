<?php

declare(strict_types=1);

namespace StepDispatcher\Concerns\BaseStepJob;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use StepDispatcher\Exceptions\MaxRetriesReachedException;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Skipped;
use StepDispatcher\States\Stopped;

/**
 * Trait HandlesStepLifecycle
 *
 * Manages the full lifecycle of a Step-based job execution.
 * Provides hooks for custom logic at each phase: preparation, guards,
 * execution, verification, and completion.
 */
trait HandlesStepLifecycle
{
    // ========================================================================
    // STATE TRANSITION HELPERS
    // ========================================================================

    public function stopJob(): void
    {
        $this->finalizeDuration();
        $this->step->state->transitionTo(Stopped::class);
        $this->stepStatusUpdated = true;
    }

    public function skipJob(): void
    {
        $this->finalizeDuration();
        $this->step->state->transitionTo(Skipped::class);
        $this->stepStatusUpdated = true;
    }

    public function retryJob(Carbon|CarbonImmutable|null $dispatchAfter = null): void
    {
        $dispatchTime = $dispatchAfter ?? $this->resolveNextDispatchTime();

        // Check if step should be escalated to high priority
        if (method_exists($this, 'shouldChangeToHighPriority') && $this->shouldChangeToHighPriority() === true) {
            $this->step->update(['priority' => 'high']);
        }

        $this->step->update([
            'dispatch_after' => $dispatchTime,
            'is_throttled' => false,  // Ensure transition WILL increment retries
        ]);

        Step::log($this->step->id, 'retries', sprintf(
            'Retry scheduled | retries=%d | backoff=%s | dispatch_after=%s',
            (int) $this->step->retries + 1,
            $this->resolveBackoffLabel(),
            $dispatchTime->format('H:i:s.u')
        ));

        $this->step->state->transitionTo(Pending::class);

        $this->stepStatusUpdated = true;
    }

    public function rescheduleWithoutRetry(Carbon|CarbonImmutable|null $dispatchAfter = null): void
    {
        $dispatchTime = $dispatchAfter ?? $this->resolveNextDispatchTime();

        // Check if step should be escalated to high priority
        if (method_exists($this, 'shouldChangeToHighPriority') && $this->shouldChangeToHighPriority() === true) {
            $this->step->update(['priority' => 'high']);
        }

        // Set dispatch_after and throttling flags BEFORE transition
        $this->step->dispatch_after = $dispatchTime;
        $this->step->was_throttled = true;  // Historical: step has been throttled at least once
        $this->step->is_throttled = true;   // Current: step is currently waiting due to throttling

        $this->step->save();

        Step::log($this->step->id, 'throttled', sprintf(
            'Throttled | backoff=%s | dispatch_after=%s | queue=%s',
            $this->resolveBackoffLabel(),
            $dispatchTime->format('H:i:s.u'),
            $this->step->queue ?? 'default'
        ));

        // Use proper transition! The is_throttled flag signals to NOT increment retries
        $this->step->state->transitionTo(Pending::class);

        $this->stepStatusUpdated = true;
    }

    public function retryForConfirmation(): void
    {
        $this->step->update(['execution_mode' => 'confirming-completion']);
        $this->step->state->transitionTo(Pending::class);
        $this->stepStatusUpdated = true;
    }

    /**
     * Re-route this step to a different worker's per-hostname queue without
     * consuming a retry slot. Used by ban-aware rotation in BaseApiableJob:
     * when the current worker's IP is blacklisted on the target exchange for
     * the given (account, api_system), the job is rotated onto a clean
     * worker's queue rather than retrying locally against an IP that cannot
     * succeed.
     *
     * Semantics differ from `retryJob()` on three axes:
     *   - retries: NOT incremented (rotation is not a retry; it's a re-route).
     *     Achieved by setting is_throttled=true before the Running→Pending
     *     transition — the transition class skips the retry counter when
     *     is_throttled is true.
     *   - started_at: cleared so the next pickup measures duration fresh.
     *   - queue: switched to $queueName for the next dispatcher tick.
     */
    public function rotateToQueue(string $queueName): void
    {
        $previousQueue = $this->step->queue;

        $this->step->queue = $queueName;
        $this->step->dispatch_after = now();
        $this->step->started_at = null;
        $this->step->is_throttled = true;
        $this->step->save();

        Step::log($this->step->id, 'rotated', sprintf(
            'rotated from %s → %s (current worker blacklisted)',
            $previousQueue ?? 'default',
            $queueName
        ));

        $this->step->state->transitionTo(Pending::class);

        $this->stepStatusUpdated = true;
    }

    /**
     * Pick the next `dispatch_after` timestamp for a retry/reschedule.
     *
     * Callers can set `jobBackoffMs` for millisecond precision (used by the
     * API throttler path where min-delay deficits are commonly tens of ms)
     * or leave it at 0 and let the legacy seconds-based backoff apply.
     */
    protected function resolveNextDispatchTime(): Carbon|CarbonImmutable
    {
        if (isset($this->jobBackoffMs) && $this->jobBackoffMs > 0) {
            return now()->addMilliseconds($this->jobBackoffMs);
        }

        return now()->addSeconds($this->jobBackoffSeconds);
    }

    /**
     * Short log label for the backoff value picked by `resolveNextDispatchTime`.
     */
    protected function resolveBackoffLabel(): string
    {
        if (isset($this->jobBackoffMs) && $this->jobBackoffMs > 0) {
            return $this->jobBackoffMs.'ms';
        }

        return $this->jobBackoffSeconds.'s';
    }
    // ========================================================================
    // PREPARATION PHASE
    // ========================================================================

    protected function attachRelatable(): void
    {
        if (! method_exists($this, 'relatable')) {
            return;
        }

        $relatable = $this->relatable();

        if ($relatable && method_exists($this->step, 'relatable')) {
            $this->step->relatable()->associate($relatable);
            $this->step->save();
        }
    }

    protected function checkMaxRetries(): void
    {
        if ($this->step->retries >= $this->retries) {
            $diagnostics = $this->getRetryDiagnostics();

            $message = "Max retries ({$this->step->retries}) reached for Step ID {$this->step->id}.";
            if (! empty($diagnostics)) {
                $message .= ' | Diagnostics: '.implode(separator: ', ', array: $diagnostics);
            }

            throw new MaxRetriesReachedException($message);
        }
    }

    /**
     * Get diagnostic information for retry failures.
     * Override in subclasses to provide domain-specific diagnostics.
     */
    protected function getRetryDiagnostics(): array
    {
        return [];
    }

    // ========================================================================
    // LIFECYCLE GUARD HOOKS (Override in child job)
    // ========================================================================

    protected function shouldStartOrStop(): bool
    {
        return ! method_exists($this, 'startOrStop') || $this->startOrStop() !== false;
    }

    protected function shouldStartOrSkip(): bool
    {
        return ! method_exists($this, 'startOrSkip') || $this->startOrSkip() !== false;
    }

    protected function shouldStartOrFail(): bool
    {
        return ! method_exists($this, 'startOrFail') || $this->startOrFail() !== false;
    }

    protected function shouldStartOrRetry(): bool
    {
        return ! method_exists($this, 'startOrRetry') || $this->startOrRetry() !== false;
    }

    protected function shouldConfirmOrRetry(): bool
    {
        return ! method_exists($this, 'confirmOrRetry') || $this->confirmOrRetry() !== false;
    }

    protected function shouldComplete(): void
    {
        // Guard: Don't call complete() if step status was already updated
        // (e.g., retryJob() transitioned to Pending, stopJob() would fail)
        if ($this->stepStatusUpdated) {
            return;
        }

        if (method_exists($this, 'complete')) {
            $this->complete();
        }
    }

    // ========================================================================
    // VERIFICATION & DOUBLE-CHECK LOGIC
    // ========================================================================

    protected function shouldDoubleCheck(): bool
    {
        if (! method_exists($this, 'doubleCheck')) {
            return false;
        }

        // Exhausted double-check budget. Pre-fix, this branch fell
        // through to needsVerification()=false → finalizeJobExecution()
        // → shouldComplete() — the step was COMPLETED even though
        // doubleCheck() never returned true. For exchange-facing
        // jobs (PlaceMarketOrderJob, PlaceLimitOrderJob, …) where
        // doubleCheck() is the only confirmation that the order was
        // accepted, fail-open after exhaustion is unsafe — the parent
        // workflow advances against unverified state. Fail the step
        // explicitly so the parent's resolve-exception path runs.
        if ($this->step->double_check >= 2) {
            $this->step->update([
                'error_message' => 'doubleCheck() failed twice — verification budget exhausted without confirmation',
            ]);
            $this->step->state->transitionTo(Failed::class);
            $this->stepStatusUpdated = true;

            return true;
        }

        if ($this->doubleCheck() === false) {
            $this->step->increment('double_check');
            $this->retryJob();

            return true;
        }

        $this->step->update(['double_check' => 99]);

        return false;
    }

    protected function shouldRunConfirmingCompletionMode(): bool
    {
        return $this->step->execution_mode === 'confirming-completion';
    }

    protected function confirmCompletionOrRetry(): void
    {
        if (! method_exists($this, 'confirmOrRetry')) {
            return;
        }

        $result = $this->confirmOrRetry();

        if ($result === false) {
            $this->retryForConfirmation();

            return;
        }

        $this->completeIfNotHandled();
    }

    protected function completeIfNotHandled(): void
    {
        if ($this->stepStatusUpdated) {
            return;
        }

        $this->finalizeDuration();
        $this->step->state->transitionTo(Completed::class);
        $this->stepStatusUpdated = true;
    }

    // ========================================================================
    // COMPUTE & RESULT STORAGE
    // ========================================================================

    protected function computeAndStoreResult(): void
    {
        $result = $this->compute();

        if (! $result || ! is_null($this->step->response)) {
            return;
        }

        $this->step->update([
            'response' => $this->formatResultForStorage($result),
        ]);
    }
}
