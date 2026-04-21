<?php

declare(strict_types=1);

namespace StepDispatcher\Concerns\BaseStepJob;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use StepDispatcher\Exceptions\MaxRetriesReachedException;
use StepDispatcher\States\Completed;
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
        $dispatchTime = $dispatchAfter ?? now()->addSeconds($this->jobBackoffSeconds);

        // Check if step should be escalated to high priority
        if (method_exists($this, 'shouldChangeToHighPriority') && $this->shouldChangeToHighPriority() === true) {
            $this->step->update(['priority' => 'high']);
        }

        $this->step->update([
            'dispatch_after' => $dispatchTime,
            'is_throttled' => false,  // Ensure transition WILL increment retries
        ]);

        $this->step->state->transitionTo(Pending::class);

        $this->stepStatusUpdated = true;
    }

    public function rescheduleWithoutRetry(Carbon|CarbonImmutable|null $dispatchAfter = null): void
    {
        $dispatchTime = $dispatchAfter ?? now()->addSeconds($this->jobBackoffSeconds);

        // Check if step should be escalated to high priority
        if (method_exists($this, 'shouldChangeToHighPriority') && $this->shouldChangeToHighPriority() === true) {
            $this->step->update(['priority' => 'high']);
        }

        // Set dispatch_after and throttling flags BEFORE transition
        $this->step->dispatch_after = $dispatchTime;
        $this->step->was_throttled = true;  // Historical: step has been throttled at least once
        $this->step->is_throttled = true;   // Current: step is currently waiting due to throttling

        $this->step->save();

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
        if (! method_exists($this, 'doubleCheck') || $this->step->double_check >= 2) {
            return false;
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
