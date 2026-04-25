<?php

declare(strict_types=1);

namespace StepDispatcher\Commands;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use ReflectionException;
use StepDispatcher\Events\StaleStepsDetected;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\Support\BaseCommand;

/**
 * Consolidated stall-recovery command.
 *
 * Default behaviour (no flags) — unchanged from the original package:
 *   Sweeps Running steps whose worker has gone away, flips them back to
 *   Pending (or Failed once retries are exhausted).
 *
 * Opt-in behaviours (flags):
 *   --recover-dispatched : scan Dispatched steps stuck past --step-threshold
 *                          seconds and promote them to priority/high so a
 *                          free worker grabs them next tick.
 *   --release-locks      : force-unlock steps_dispatcher rows held by a dead
 *                          tick for longer than --lock-threshold seconds.
 *
 * Every branch that detects a stall fires StepDispatcher\Events\StaleStepsDetected
 * so consuming apps can hook notifications without modifying the package.
 */
final class RecoverStaleStepsCommand extends BaseCommand
{
    /**
     * Buffer added to the job timeout before considering a Running step stale.
     */
    private const RUNNING_BUFFER_SECONDS = 60;

    /**
     * Fallback timeout when the job class cannot be resolved.
     */
    private const DEFAULT_TIMEOUT = 300;

    protected $signature = 'steps:recover-stale
                            {--recover-dispatched : Also promote stuck Dispatched steps to priority/high}
                            {--release-locks : Also release dispatcher locks held beyond the threshold}
                            {--watchdog-progress : Also alert on groups that have Pending steps but no recent terminal-state progress}
                            {--step-threshold=300 : Seconds in Dispatched before a step counts as stuck}
                            {--lock-threshold=30 : Seconds a dispatcher lock can be held before force-release}
                            {--progress-threshold=600 : Seconds without terminal-state progress before a group counts as stalled}
                            {--output : Display command output (silent by default)}';

    protected $description = 'Recover stuck steps (Running zombies, Dispatched stalls) and release wedged dispatcher locks';

    public function handle(): int
    {
        if ((bool) $this->option('release-locks')) {
            $this->releaseStaleDispatcherLocks((int) $this->option('lock-threshold'));
        }

        if ((bool) $this->option('recover-dispatched')) {
            $this->recoverStaleDispatchedSteps((int) $this->option('step-threshold'));
        }

        if ((bool) $this->option('watchdog-progress')) {
            $this->detectGroupNoProgress((int) $this->option('progress-threshold'));
        }

        $this->recoverStaleRunningSteps();

        return self::SUCCESS;
    }

    // ========================================================================
    // Running state recovery (legacy default path)
    // ========================================================================

    private function recoverStaleRunningSteps(): void
    {
        $staleSteps = Step::where('state', Running::class)
            ->whereNotNull('started_at')
            ->get();

        if ($staleSteps->isEmpty()) {
            return;
        }

        $recovered = 0;

        foreach ($staleSteps as $step) {
            $timeout = $this->resolveJobTimeout($step);
            $staleThreshold = $timeout + self::RUNNING_BUFFER_SECONDS;
            $runningSeconds = (int) $step->started_at->diffInSeconds(now());

            if ($runningSeconds < $staleThreshold) {
                continue;
            }

            // Parent steps stay in Running until every descendant reaches a
            // terminal state — that is the state machine's design, not a
            // zombie condition. Reclaiming such a parent causes its compute()
            // to rerun and duplicate-dispatch the child block (including any
            // DB rows created by child jobs). Skip parents whose tree is
            // still in-flight; recover them only if every descendant is
            // terminal (genuine zombie: parent stuck, tree already settled).
            if ($step->isParent() && $this->hasActiveDescendants($step)) {
                continue;
            }

            $maxRetries = $this->resolveJobMaxRetries($step);

            if ($step->retries >= $maxRetries) {
                $step->update([
                    'error_message' => "Recovered by stale detector: Running for {$runningSeconds}s (threshold: {$staleThreshold}s), retries exhausted ({$step->retries}/{$maxRetries})",
                ]);
                $step->state->transitionTo(Failed::class);

                Log::warning('Stale step failed (retries exhausted)', [
                    'step_id' => $step->id,
                    'class' => $step->class,
                    'label' => $step->label,
                    'running_seconds' => $runningSeconds,
                    'retries' => $step->retries,
                ]);
            } else {
                // Worker-death recovery is NOT a throttle event — it is a
                // retry attempt. If we left `is_throttled` set (inherited
                // from an earlier legitimate throttle), `RunningToPending`
                // would skip the retries++ and the step would loop forever
                // here, never exhausting its budget. Clear the flag so the
                // retry counter advances.
                $step->update([
                    'error_message' => "Recovered by stale detector: Running for {$runningSeconds}s (threshold: {$staleThreshold}s), retrying ({$step->retries}/{$maxRetries})",
                    'is_throttled' => false,
                ]);
                $step->state->transitionTo(Pending::class);

                Log::warning('Stale step recovered to Pending', [
                    'step_id' => $step->id,
                    'class' => $step->class,
                    'label' => $step->label,
                    'running_seconds' => $runningSeconds,
                    'retries' => $step->retries + 1,
                ]);
            }

            $recovered++;
            $this->verboseLine("[Step {$step->id}] {$step->label}: recovered ({$step->class})");
        }

        if ($recovered === 0) {
            return;
        }

        $this->verboseInfo("Recovered {$recovered} stale step(s).");

        StaleStepsDetected::dispatch(
            severity: 'warning',
            reason: 'stale_running_steps_recovered',
            count: $recovered,
            oldestStep: $staleSteps->first(),
            context: ['hostname' => gethostname()],
        );
    }

    private function resolveJobTimeout(Step $step): int
    {
        if (! $step->class || ! class_exists($step->class)) {
            return self::DEFAULT_TIMEOUT;
        }

        try {
            $reflection = new ReflectionClass($step->class);
            $property = $reflection->getProperty('timeout');
            $value = (int) $property->getDefaultValue();

            // $timeout = 0 is a Laravel convention meaning "rely on the queue worker's
            // own timeout". For stale detection we need a positive number, so fall back
            // to DEFAULT_TIMEOUT. Without this, every such job is considered stale after
            // 60s (0 + BUFFER), which kills legitimate long-running work.
            return $value > 0 ? $value : self::DEFAULT_TIMEOUT;
        } catch (ReflectionException) {
            return self::DEFAULT_TIMEOUT;
        }
    }

    private function resolveJobMaxRetries(Step $step): int
    {
        if (! $step->class || ! class_exists($step->class)) {
            return 2;
        }

        try {
            $reflection = new ReflectionClass($step->class);
            $property = $reflection->getProperty('retries');

            return (int) $property->getDefaultValue();
        } catch (ReflectionException) {
            return 2;
        }
    }

    /**
     * Walk the parent's child_block_uuid and any nested parent blocks. Returns
     * true if any descendant is not in a terminal state (Completed, Skipped,
     * Cancelled, Failed, Stopped). Zero children anywhere → returns false,
     * which correctly flags a never-dispatched parent as recoverable.
     */
    private function hasActiveDescendants(Step $parent): bool
    {
        $children = Step::where('block_uuid', $parent->child_block_uuid)->get();

        if ($children->isEmpty()) {
            return false;
        }

        $terminalStates = Step::terminalStepStates();

        foreach ($children as $child) {
            if (! in_array(get_class($child->state), $terminalStates, strict: true)) {
                return true;
            }

            if ($child->isParent() && $this->hasActiveDescendants($child)) {
                return true;
            }
        }

        return false;
    }

    // ========================================================================
    // Dispatched state recovery (opt-in with --recover-dispatched)
    // ========================================================================

    private function recoverStaleDispatchedSteps(int $thresholdSeconds): void
    {
        $staleThreshold = now()->subSeconds($thresholdSeconds);

        $baseQuery = Step::query()
            ->where('state', Dispatched::class)
            ->where('updated_at', '<', $staleThreshold);

        $count = (clone $baseQuery)->count();

        if ($count === 0) {
            return;
        }

        $alreadyPromoted = (clone $baseQuery)
            ->where('queue', 'priority')
            ->where('priority', 'high')
            ->count();

        $oldestStep = (clone $baseQuery)
            ->orderBy('updated_at', 'asc')
            ->first();

        // CRITICAL path: every stuck step was already promoted to priority/high
        // on a previous run and is still Dispatched. Promotion by itself isn't
        // enough — the Redis payload is gone (worker died between pop and state
        // transition) so the queue-column rename achieves nothing. Alert the
        // operator AND flip state back to Pending so the next dispatcher tick
        // re-pushes them to the priority queue with a fresh payload.
        if ($alreadyPromoted > 0 && $alreadyPromoted === $count) {
            $requeued = $this->requeueDispatchedSteps($baseQuery);

            $this->verboseError("CRITICAL: {$alreadyPromoted} priority step(s) still stuck after promotion; re-queued {$requeued}.");

            StaleStepsDetected::dispatch(
                severity: 'critical',
                reason: 'stale_dispatched_steps_still_stuck',
                count: $count,
                alreadyPromotedCount: $alreadyPromoted,
                oldestStep: $oldestStep,
                context: [
                    'step_threshold_seconds' => $thresholdSeconds,
                    'requeued_count' => $requeued,
                    'hostname' => gethostname(),
                ],
            );

            return;
        }

        $promoted = (clone $baseQuery)
            ->where(static function ($q): void {
                $q->where('queue', '!=', 'priority')
                    ->orWhere('priority', '!=', 'high');
            })
            ->update([
                'priority' => 'high',
                'queue' => 'priority',
            ]);

        // After promotion, transition state back to Pending on every stale
        // Dispatched step (both newly-promoted and already-promoted). Without
        // this, promotion is just a queue-column rename — step-dispatcher never
        // re-pushes a step whose state is still Dispatched, so the priority
        // queue stays empty and workers sit idle.
        $requeued = $this->requeueDispatchedSteps($baseQuery);

        $this->verboseInfo("Promoted {$promoted} stale Dispatched step(s) to priority/high; re-queued {$requeued}.");

        StaleStepsDetected::dispatch(
            severity: 'warning',
            reason: 'stale_dispatched_steps_promoted',
            count: $count,
            alreadyPromotedCount: $alreadyPromoted,
            promotedCount: $promoted,
            oldestStep: $oldestStep,
            context: [
                'step_threshold_seconds' => $thresholdSeconds,
                'requeued_count' => $requeued,
                'hostname' => gethostname(),
            ],
        );
    }

    /**
     * Transition each stale Dispatched step back to Pending via the registered
     * state-machine transition. Returns the number of rows flipped.
     *
     * Uses the transition (not a bulk `update`) so the state machine fires
     * correctly — timers reset, retries increment, diagnostic log entry lands.
     *
     * Duplicate-execution risk if the original Redis payload resurfaces is
     * absorbed by `BaseStepJob::prepareJobExecution()` which bails when it
     * sees a step already in Running state.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Step>  $query
     */
    private function requeueDispatchedSteps($query): int
    {
        $requeued = 0;

        (clone $query)->get()->each(function (Step $step) use (&$requeued): void {
            // Re-read DB truth before firing the transition. The collection
            // was hydrated moments ago; between that fetch and this
            // iteration a legit worker can have popped the Redis payload
            // and advanced the step Dispatched → Running. Acting on the
            // stale in-memory snapshot would clobber the active run — reset
            // started_at, burn a retry, force a duplicate execution. A
            // refresh closes the window: if state has moved past Dispatched
            // we leave it alone.
            $step->refresh();

            if (! ($step->state instanceof Dispatched)) {
                return;
            }

            $step->state->transitionTo(Pending::class);
            $requeued++;
        });

        return $requeued;
    }

    // ========================================================================
    // Dispatcher lock release (opt-in with --release-locks)
    // ========================================================================

    private function releaseStaleDispatcherLocks(int $thresholdSeconds): void
    {
        $staleThreshold = now()->subSeconds($thresholdSeconds);

        $released = DB::table('steps_dispatcher')
            ->where('can_dispatch', false)
            ->where('updated_at', '<', $staleThreshold)
            ->update([
                'can_dispatch' => true,
                'current_tick_id' => null,
                'updated_at' => now(),
            ]);

        if ($released === 0) {
            return;
        }

        $this->verboseWarn("Released {$released} stale dispatcher lock(s).");

        StaleStepsDetected::dispatch(
            severity: 'warning',
            reason: 'stale_dispatcher_locks_released',
            count: $released,
            releasedLocksCount: $released,
            context: [
                'lock_threshold_seconds' => $thresholdSeconds,
                'hostname' => gethostname(),
            ],
        );
    }

    // ========================================================================
    // Group-progress watchdog (opt-in with --watchdog-progress)
    // ========================================================================

    /**
     * Detect groups whose Pending queue is non-empty but where no terminal-
     * state step has been updated within the threshold. The 2026-04-25 wedge
     * proved the per-step detector is necessary but not sufficient — phase 0
     * was returning `true` early on Skipped parents with empty / all-terminal
     * child blocks, so dispatch never ran. No individual step looked stale,
     * no Dispatched step was stuck, no lock was held. The detector found
     * nothing while four groups silently bled for ~16h.
     *
     * Signal shape: per group, Pending count > 0 AND (no terminal-state step
     * exists OR the most recent terminal `updated_at` is older than the
     * threshold). Fires a `group_no_progress` event with severity=critical so
     * the consuming app can route it to a high-priority pushover canonical.
     * Idle groups (zero Pending, no work to drain) are explicitly suppressed
     * to avoid paging on quiet 03:00 UTC windows.
     */
    private function detectGroupNoProgress(int $thresholdSeconds): void
    {
        $cutoff = now()->subSeconds($thresholdSeconds);

        $pendingByGroup = Step::where('state', Pending::class)
            ->whereNotNull('group')
            ->groupBy('group')
            ->selectRaw('`group` as g, COUNT(*) as c')
            ->pluck('c', 'g');

        if ($pendingByGroup->isEmpty()) {
            return;
        }

        foreach ($pendingByGroup as $groupName => $pendingCount) {
            $latestTerminal = Step::whereIn('state', Step::terminalStepStates())
                ->where('group', $groupName)
                ->max('updated_at');

            $stalled = $latestTerminal === null
                || \Illuminate\Support\Carbon::parse($latestTerminal)->lt($cutoff);

            if (! $stalled) {
                continue;
            }

            $this->verboseError(sprintf(
                'CRITICAL: group "%s" has %d Pending step(s) but no terminal progress since %s (threshold: %ds)',
                $groupName,
                $pendingCount,
                $latestTerminal ?? 'never',
                $thresholdSeconds,
            ));

            StaleStepsDetected::dispatch(
                severity: 'critical',
                reason: 'group_no_progress',
                count: (int) $pendingCount,
                context: [
                    'group' => $groupName,
                    'pending_count' => (int) $pendingCount,
                    'last_terminal_update' => $latestTerminal,
                    'progress_threshold_seconds' => $thresholdSeconds,
                    'hostname' => gethostname(),
                ],
            );
        }
    }
}
