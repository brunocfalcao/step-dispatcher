<?php

declare(strict_types=1);

namespace StepDispatcher\Support;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use StepDispatcher\Models\Step;
use StepDispatcher\Models\StepsDispatcher;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Failed;
use StepDispatcher\States\NotRunnable;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\States\Skipped;
use StepDispatcher\States\Stopped;
use StepDispatcher\Transitions\PendingToDispatched;
use Throwable;

final class StepDispatcher
{
    use DispatchesJobs;

    /**
     * Optional resolver callable invoked at dispatch time to decide the
     * physical queue a step should land on. Consumer apps register one
     * via `setQueueResolver()` to inject routing logic (e.g. picking a
     * clean worker by IP affinity in Kraite). When `null`, the dispatcher
     * uses `step.queue` verbatim — i.e. the framework's default behaviour
     * is preserved when no resolver is registered.
     *
     * Contract: the callable receives the Step model and returns either:
     *   - `string`  — the physical queue name to use; dispatcher persists
     *                 it back to `step.queue` and pushes onto that queue.
     *   - `null`    — no opinion; dispatcher leaves `step.queue` as-is.
     *   - throws `NoCleanWorkerException` — terminal failure; dispatcher
     *     transitions the step to Failed via its standard try/catch.
     *
     * @var (callable(Step): ?string)|null
     */
    private static $queueResolver = null;

    /**
     * Register (or unregister with null) the queue resolver. Stored
     * statically because the resolver applies process-wide and the
     * dispatcher itself is invoked statically. Consumer apps typically
     * call this from a ServiceProvider's boot() so the resolver is
     * active for the entire request/worker lifecycle.
     */
    public static function setQueueResolver(?callable $resolver): void
    {
        self::$queueResolver = $resolver;
    }

    /**
     * Internal accessor used by DispatchesJobs::dispatchSingleStep to
     * resolve the physical queue before pushing onto Redis.
     *
     * @return (callable(Step): ?string)|null
     */
    public static function getQueueResolver(): ?callable
    {
        return self::$queueResolver;
    }

    /**
     * Check if any steps are in an active (non-idle) state.
     * Active states: Pending, Dispatched, Running.
     * Uses EXISTS for sub-millisecond performance on indexed state column.
     */
    public static function hasActiveSteps(): bool
    {
        return Step::whereIn('state', [Pending::class, Dispatched::class, Running::class])
            ->exists();
    }

    /**
     * Activate the dispatcher by creating the flag file.
     * Called when new steps are created.
     */
    public static function activate(): void
    {
        $dir = self::flagDir();

        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }

        $flag = self::flagFilePath();

        if (! file_exists($flag)) {
            @file_put_contents($flag, (string) time());
        }
    }

    /**
     * Deactivate the dispatcher by removing the flag file.
     * Called when no active steps remain.
     */
    public static function deactivate(): void
    {
        $flag = self::flagFilePath();

        if (file_exists($flag)) {
            @unlink($flag);
        }
    }

    /**
     * Check if the dispatcher is active (flag file exists).
     */
    public static function isActive(): bool
    {
        return file_exists(self::flagFilePath());
    }

    /**
     * Run a single "tick" of the dispatcher, optionally constrained to a group.
     *
     * @param  string|null  $group  If provided, ALL Step selections are filtered by this group.
     */
    public static function dispatch(?string $group = null): void
    {
        // Acquire the DB lock authoritatively; bail if already running.
        if (! StepsDispatcher::startDispatch($group)) {
            Step::logGlobal('dispatcher', sprintf(
                'Tick skipped | group=%s | reason=lock_not_acquired',
                $group ?? 'NULL'
            ));

            return;
        }

        Step::logGlobal('dispatcher', sprintf(
            'Tick started | group=%s',
            $group ?? 'NULL'
        ));

        $tickStartedAt = microtime(true);

        $progress = 0;

        try {
            // Marks as skipped all children steps on a skipped step.
            $result = self::skipAllChildStepsOnParentAndChildSingleStep($group);
            if ($result) {
                return;
            }

            $progress = 1;

            // Perform cascading cancellation for failed steps and return early if needed
            $result = self::cascadeCancelledSteps($group);
            if ($result) {
                return;
            }

            $progress = 2;

            $result = self::promoteResolveExceptionSteps($group);
            if ($result) {
                return;
            }

            $progress = 3;

            // Check if we need to transition parent steps to Failed first, but only if no cancellations occurred
            $result = self::transitionParentsToFailed($group);
            if ($result) {
                return;
            }

            $progress = 4;

            // Check if we need to transition parent steps to Stopped (child was stopped)
            $result = self::transitionParentsToStopped($group);
            if ($result) {
                return;
            }

            $progress = 5;

            $result = self::cascadeCancellationToChildren($group);
            if ($result) {
                return;
            }

            $progress = 6;

            // Check if we need to transition parent steps to Completed
            $result = self::transitionParentsToComplete($group);
            if ($result) {
                return;
            }

            $progress = 7;

            // Distribute the steps to be dispatched (only if no cancellations or failures happened)
            $dispatchedSteps = collect();

            // Two-pass selection so that priority='high' steps are never
            // hidden behind a non-priority backlog that fills the
            // max_per_tick window:
            //
            //   Pass 1 — fetch ALL priority='high' Pending steps. No cap.
            //     Priority is the framework's escape hatch for
            //     latency-sensitive workflows (observer-dispatched
            //     corrections, position closes, WAP). They must always
            //     be visible to the dispatcher regardless of how many
            //     non-priority rows sit in front of them in FIFO order.
            //     Production trigger: 2026-05-01, a 1700-row hourly
            //     leverage-bracket batch buried an observer-dispatched
            //     PrepareOrderCorrectionJob for ~8 minutes — the step
            //     was Pending the whole time, never reaching the
            //     dispatcher's 100-row visible window.
            //
            //   Pass 2 — only when no priority work exists, fetch up to
            //     max_per_tick non-priority Pending steps. The cap still
            //     protects the dispatcher from runaway non-priority
            //     groups (the 2026-04-25 wedge).
            //
            // Trade-off: if a priority flood ever materialises (5,000+
            // priority='high' rows in one tick), the cap can be defeated.
            // In practice priority='high' is reserved for individual
            // observer-dispatched workflow steps — not bulk batches.
            // If that assumption ever breaks, add a separate
            // priority_max_per_tick knob.

            $baseQuery = static fn () => Step::pending()
                ->forGroup($group)
                ->where(static function ($q) {
                    $q->whereNull('dispatch_after')
                        ->orWhere('dispatch_after', '<=', now());
                })
                ->orderBy('id', 'asc');

            $prioritySteps = $baseQuery()
                ->where('priority', 'high')
                ->get();

            $pendingSteps = $prioritySteps;
            $stepsCache = self::buildStepsCache($pendingSteps, $group);
            $dispatchableSteps = self::computeDispatchableSteps($pendingSteps, $stepsCache);

            // Pass 1 fall-through: when priority='high' rows exist but NONE
            // of them are actually dispatchable this tick (e.g. an orphan
            // priority step whose previous index is missing — a poison
            // pill), the original implementation skipped pass 2 entirely
            // and the entire group's non-priority backlog starved.
            // Production trigger (2026-05-07, group eta): one undispatchable
            // priority='high' step wedged 660+ Pending rows for 11+ minutes
            // before the group-stall watchdog fired.
            //
            // Contract: pass 2 must run whenever pass 1 produces zero
            // *dispatchable* work — not only when pass 1 fetches zero rows.
            if ($prioritySteps->isEmpty() || $dispatchableSteps->isEmpty()) {
                $pendingQuery = $baseQuery();

                $maxPerTick = (int) config('step-dispatcher.dispatch.max_per_tick', 0);
                if ($maxPerTick > 0) {
                    $pendingQuery->limit($maxPerTick);
                }

                $pendingSteps = $pendingQuery->get();
                $stepsCache = self::buildStepsCache($pendingSteps, $group);
                $dispatchableSteps = self::computeDispatchableSteps($pendingSteps, $stepsCache);
            }

            // Apply transitions for all dispatchable steps. apply() is an
            // atomic claim (UPDATE ... WHERE state = Pending) — a null
            // return means an external writer moved the step (e.g. a
            // cancel) between selection and claim; never push those.
            $dispatchableSteps->each(static function (Step $step) use ($dispatchedSteps, $stepsCache) {
                $transition = new PendingToDispatched($step, $stepsCache);

                if ($transition->apply() !== null) {
                    $dispatchedSteps->push($step);
                }
            });

            // Dispatch all steps that are ready
            $dispatchedSteps->each(static function ($step) {
                (new self)->dispatchSingleStep($step);
            });

            $progress = 8;

            // Per-tick saturation counters. Cheap Redis INCRs keyed by
            // (group, minute-bucket). A host-app cron flushes them to
            // a persistent table once per minute. The dashboard reads
            // the persistent table — the dispatcher tick pays only the
            // four sub-millisecond increments and never touches MySQL
            // for telemetry.
            //
            // Bucket keying uses UTC minute precision so flushers
            // always read the *previous* completed minute and never
            // race with in-flight ticks of the current minute.
            $maxPerTick = (int) config('step-dispatcher.dispatch.max_per_tick', 0);
            $dispatchableCount = $dispatchableSteps->count();
            $wasCapped = $maxPerTick > 0 && $dispatchableCount >= $maxPerTick;
            $pendingAfterTick = $wasCapped
                ? Step::pending()
                    ->forGroup($group)
                    ->where(static function ($q) {
                        $q->whereNull('dispatch_after')
                            ->orWhere('dispatch_after', '<=', now());
                    })
                    ->count()
                : 0;
            $hasLeftover = $pendingAfterTick > 0;

            self::recordTickMetrics(
                group: $group,
                dispatchableCount: $dispatchableCount,
                wasCapped: $wasCapped,
                hasLeftover: $hasLeftover,
                pendingAfterTick: $pendingAfterTick,
            );

            Step::logGlobal('dispatcher', sprintf(
                'Tick dispatched | group=%s | dispatchable=%d | pending_seen=%d | blocks_seen=%d',
                $group ?? 'NULL',
                $dispatchableCount,
                $pendingSteps->count(),
                $pendingSteps->pluck('block_uuid')->unique()->count(),
            ));
        } finally {
            StepsDispatcher::endDispatch($progress, $group);

            Step::logGlobal('dispatcher', sprintf(
                'Tick finished | group=%s | progress=%d | duration_ms=%d',
                $group ?? 'NULL',
                $progress,
                Timing::elapsedMs($tickStartedAt),
            ));

            // Check if any active steps remain; deactivate if idle
            if (! self::hasActiveSteps()) {
                self::deactivate();
            }
        }
    }

    /**
     * Transition running parents to Completed if all their (nested) children concluded.
     */
    public static function transitionParentsToComplete(?string $group = null): bool
    {
        $runningParents = Step::where('state', Running::class)
            ->forGroup($group)
            ->whereNotNull('child_block_uuid')
            ->orderBy('index')
            ->orderBy('id')
            ->get();

        if ($runningParents->isEmpty()) {
            return false;
        }

        $childBlockUuids = self::collectAllNestedChildBlocks($runningParents, $group);

        $childStepsByBlock = Step::whereIn('block_uuid', $childBlockUuids)
            ->forGroup($group)
            ->get()
            ->groupBy('block_uuid');

        $changed = false;

        foreach ($runningParents as $step) {
            try {
                $areConcluded = $step->childStepsAreConcludedFromMap($childStepsByBlock);

                if ($areConcluded) {
                    $step->state->transitionTo(Completed::class);
                    $changed = true;
                }
            } catch (Exception $e) {
                // Don't let a single parent's transition failure abort the
                // whole sweep — other parents may still resolve cleanly. But
                // surface the failure so operators can diagnose a parent
                // stuck Running. Silent swallow here was the original bug:
                // DB deadlocks, TransitionNotFound from stale state, etc.
                // would vanish and the stuck parent looked like a mystery.
                Log::error('transitionParentsToComplete failed for parent step', [
                    'step_id' => $step->id,
                    'class' => $step->class,
                    'block_uuid' => $step->block_uuid,
                    'child_block_uuid' => $step->child_block_uuid,
                    'group' => $group,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $changed;
    }

    /**
     * If a parent was skipped, mark all its descendants as skipped.
     *
     * Returns true ONLY when at least one descendant was actually transitioned.
     * The dispatcher's main loop interprets a true return from any cleanup
     * phase as "we did work this tick, exit" — so returning true on a no-op
     * path (empty child block, or child block populated only by descendants
     * already in terminal states) blocks the dispatch phase forever for the
     * affected group. That was the second wedge class in the 2026-04-25
     * production incident: four groups stalled ~16h on a single long-Skipped
     * parent each, whose `child_block_uuid` pointed at a block whose
     * descendants had all already concluded.
     */
    public static function skipAllChildStepsOnParentAndChildSingleStep(?string $group = null): bool
    {
        $skippedParents = Step::where('state', Skipped::class)
            ->forGroup($group)
            ->whereNotNull('child_block_uuid')
            ->get();

        if ($skippedParents->isEmpty()) {
            return false;
        }

        $allChildBlocks = self::collectAllNestedChildBlocks($skippedParents, $group);

        if (empty($allChildBlocks)) {
            return false;
        }

        // Only consider non-terminal descendants — terminal -> Skipped is
        // rejected by the state machine (silently swallowed by
        // batchTransitionSteps) and counts as zero work done.
        $descendantIds = Step::whereIn('block_uuid', $allChildBlocks)
            ->forGroup($group)
            ->whereNotIn('state', Step::terminalStepStates())
            ->pluck('id')
            ->all();

        if (empty($descendantIds)) {
            return false;
        }

        self::batchTransitionSteps($descendantIds, Skipped::class);

        return true;
    }

    /**
     * Transition running parents to Failed if any child in their block failed.
     * Note: Only handles Failed children. Stopped children are handled by transitionParentsToStopped().
     */
    public static function transitionParentsToFailed(?string $group = null): bool
    {
        return self::transitionParentsOnChildOutcome($group, Failed::class, 'failed');
    }

    /**
     * Transition running parents to Stopped if any child in their block was stopped.
     */
    public static function transitionParentsToStopped(?string $group = null): bool
    {
        return self::transitionParentsOnChildOutcome($group, Stopped::class, 'stopped');
    }

    /**
     * Shared parent-conclusion sweep: when a child reaches $childOutcome
     * (Failed/Stopped), its Running parent follows — but only after two
     * wait-gates that both outcomes share:
     *
     *   1. Non-terminal resolve-exception steps in the child block get to
     *      run first (they may repair the block).
     *   2. Parallel siblings at the SAME index as the concluded child must
     *      all reach a terminal state first — concluding the parent while
     *      a sibling is still Running would orphan that sibling's outcome.
     */
    private static function transitionParentsOnChildOutcome(?string $group, string $childOutcome, string $label): bool
    {
        $runningParents = Step::where('state', Running::class)
            ->forGroup($group)
            ->whereNotNull('child_block_uuid')
            ->get();

        if ($runningParents->isEmpty()) {
            return false;
        }

        $childBlockUuids = $runningParents->pluck('child_block_uuid')->filter()->unique()->all();

        $childStepsByBlock = Step::whereIn('block_uuid', $childBlockUuids)
            ->forGroup($group)
            ->get()
            ->groupBy('block_uuid');

        foreach ($runningParents as $parentStep) {
            $childSteps = $childStepsByBlock->get($parentStep->child_block_uuid, collect());

            if ($childSteps->isEmpty()) {
                continue;
            }

            $concludedChildSteps = $childSteps->filter(
                static fn ($step) => get_class($step->state) === $childOutcome
            );

            if ($concludedChildSteps->isEmpty()) {
                continue;
            }

            // Wait-gate 1: non-terminal resolve-exception steps run first.
            $nonTerminalResolveExceptions = $childSteps->filter(
                static function ($step) {
                    return $step->type === 'resolve-exception'
                        && ! in_array(get_class($step->state), Step::terminalStepStates(), strict: true);
                }
            );

            if ($nonTerminalResolveExceptions->isNotEmpty()) {
                continue;
            }

            // Wait-gate 2: all parallel siblings at the same index must be
            // terminal before the parent concludes.
            $concludedIndices = $concludedChildSteps->pluck('index')->unique();

            $nonTerminalParallelSiblings = $childSteps->filter(
                static function ($step) use ($concludedIndices) {
                    if (! $concludedIndices->contains($step->index)) {
                        return false;
                    }

                    return ! in_array(get_class($step->state), Step::terminalStepStates(), strict: true);
                }
            );

            if ($nonTerminalParallelSiblings->isNotEmpty()) {
                continue;
            }

            $childIds = $concludedChildSteps->pluck('id')->join(', ');

            // Set error message before transitioning
            $parentStep->update([
                'error_message' => "Child step(s) {$label}: [{$childIds}]",
            ]);

            $parentStep->state->transitionTo($childOutcome);

            return true;
        }

        return false;
    }

    /**
     * Cancel downstream runnable steps after a failure/stop/cancellation.
     */
    public static function cascadeCancelledSteps(?string $group = null): bool
    {
        $cancellationsOccurred = false;

        // Find all failed/stopped/cancelled steps that should trigger
        // downstream cancellation. Cancelled is included because consumers
        // cancel in-flight steps externally (direct state write, bypassing
        // the state machine — e.g. Kraite's RecoverPositionsCommand);
        // Cancelled is terminal but not "concluded", so successors at
        // higher indexes would otherwise sit Pending forever and wedge
        // the block.
        $failedSteps = Step::whereIn('state', array_merge(Step::failedStepStates(), [Cancelled::class]))
            ->forGroup($group)
            ->whereNotNull('index')
            ->get();

        if ($failedSteps->isEmpty()) {
            return false;
        }

        foreach ($failedSteps as $failedStep) {
            $blockUuid = $failedStep->block_uuid;

            // Cancel only non-terminal, runnable steps at higher indexes in the same block.
            $stepsToCancel = Step::where('block_uuid', $blockUuid)
                ->forGroup($group)
                ->where('index', '>', $failedStep->index)
                ->whereNotIn('state', array_merge(Step::terminalStepStates(), [NotRunnable::class]))
                ->where('type', 'default')
                ->get();

            if ($stepsToCancel->isEmpty()) {
                continue;
            }

            $stepIdsToCancel = [];
            $parentSteps = [];

            foreach ($stepsToCancel as $step) {
                // Double guard: never attempt to transition terminal states.
                if (in_array(get_class($step->state), Step::terminalStepStates(), strict: true)) {
                    continue;
                }

                $stepIdsToCancel[] = $step->id;

                // Track parent steps to cancel their children
                if ($step->isParent()) {
                    $parentSteps[] = $step;
                }
            }

            if (! empty($stepIdsToCancel)) {
                self::batchTransitionSteps($stepIdsToCancel, Cancelled::class);
                $cancellationsOccurred = true;

                // Cancel child blocks
                foreach ($parentSteps as $parentStep) {
                    self::cancelChildBlockSteps($parentStep, $group);
                }
            }
        }

        return $cancellationsOccurred;
    }

    /**
     * Cancel all pending children in a child block.
     */
    public static function cancelChildBlockSteps(Step $parentStep, ?string $group = null): void
    {
        $childBlockUuid = $parentStep->child_block_uuid;

        $childStepIds = Step::where('block_uuid', $childBlockUuid)
            ->forGroup($group)
            ->where('state', Pending::class)
            ->pluck('id')
            ->all();

        if (! empty($childStepIds)) {
            self::batchTransitionSteps($childStepIds, Cancelled::class);
        }
    }

    /**
     * Promote resolve-exception steps in blocks that have failures.
     */
    public static function promoteResolveExceptionSteps(?string $group = null): bool
    {
        $candidateBlocks = Step::where('type', 'resolve-exception')
            ->forGroup($group)
            ->where('state', NotRunnable::class)
            ->pluck('block_uuid')
            ->filter()
            ->unique()
            ->values();

        if ($candidateBlocks->isEmpty()) {
            return false;
        }

        $failingBlocks = Step::whereIn('block_uuid', $candidateBlocks)
            ->forGroup($group)
            ->where('type', '<>', 'resolve-exception')
            ->whereIn('state', Step::failedStepStates())
            ->pluck('block_uuid')
            ->unique()
            ->values();

        if ($failingBlocks->isEmpty()) {
            return false;
        }

        $block = $failingBlocks->first();

        $stepIds = Step::where('block_uuid', $block)
            ->forGroup($group)
            ->where('type', 'resolve-exception')
            ->where('state', NotRunnable::class)
            ->pluck('id')
            ->all();

        if (empty($stepIds)) {
            // Race: another tick / worker promoted the resolve-exception
            // between the candidate-blocks scan above and this re-query.
            // Returning true on this no-op path would block the dispatch
            // phase for the rest of the tick — same wedge class as the
            // skipAll* phase. Yield to the next phase instead.
            return false;
        }

        self::batchTransitionSteps($stepIds, Pending::class);

        return true;
    }

    /**
     * If a parent failed/stopped/cancelled, cancel all its non-terminal children.
     * Children that never ran should be Cancelled, not Failed.
     * Also handles recursive cancellation when a cancelled parent has children.
     */
    public static function cascadeCancellationToChildren(?string $group = null): bool
    {
        // Also include Cancelled - a cancelled parent must also cancel its children recursively
        $failedOrStoppedParents = Step::whereIn('state', [Failed::class, Stopped::class, Cancelled::class])
            ->forGroup($group)
            ->whereNotNull('child_block_uuid')
            ->get();

        if ($failedOrStoppedParents->isEmpty()) {
            return false;
        }

        foreach ($failedOrStoppedParents as $parent) {
            $childBlock = $parent->child_block_uuid;

            $nonTerminalChildIds = Step::where('block_uuid', $childBlock)
                ->forGroup($group)
                ->whereNotIn('state', Step::terminalStepStates())
                ->pluck('id')
                ->all();

            if (empty($nonTerminalChildIds)) {
                continue;
            }

            self::batchTransitionSteps($nonTerminalChildIds, Cancelled::class);

            return true; // end tick after cancelling one block
        }

        return false;
    }

    /**
     * Collect all nested child block UUIDs reachable from the given parent steps.
     * Optimized with recursive CTE query for better performance.
     */
    public static function collectAllNestedChildBlocks($parents, ?string $group = null): array
    {
        $rootBlocks = $parents->pluck('child_block_uuid')->filter()->unique()->values();

        if ($rootBlocks->isEmpty()) {
            return [];
        }

        $placeholders = implode(separator: ',', array: array_fill(0, $rootBlocks->count(), '?'));

        // Quote `group` through the Step's OWN connection grammar — the
        // default-connection grammar could differ from the connection the
        // Step model actually uses.
        $groupCol = Step::wrapColumn('group');
        // Resolve the live table name through the Step model so the
        // CTE follows the active runtime prefix. Hardcoding `steps`
        // here would silently scan the wrong table when a prefixed
        // dispatcher is running.
        $stepsTable = Step::tableName();

        // Same lane semantics as Step::forGroup(): null means the
        // NULL-group lane, never "all groups".
        //
        // The depth column bounds the recursion: nothing in the schema
        // prevents a child_block_uuid cycle, and an unbounded recursive
        // CTE on one would loop forever (sqlite) or abort the tick with
        // a driver recursion error (MySQL/PostgreSQL). Real trees are a
        // handful of levels deep; 100 is a generous ceiling.
        $sql = "
            WITH RECURSIVE descendants AS (
                SELECT child_block_uuid AS block_uuid, 1 AS depth
                FROM {$stepsTable}
                WHERE child_block_uuid IN ({$placeholders})
                  AND child_block_uuid IS NOT NULL
                  ".($group !== null ? "AND {$groupCol} = ?" : "AND {$groupCol} IS NULL").'

                UNION ALL

                SELECT s.child_block_uuid, d.depth + 1
                FROM '.$stepsTable.' s
                INNER JOIN descendants d ON s.block_uuid = d.block_uuid
                WHERE s.child_block_uuid IS NOT NULL
                  AND d.depth < 100
                  '.($group !== null ? "AND s.{$groupCol} = ?" : "AND s.{$groupCol} IS NULL").'
            )
            SELECT DISTINCT block_uuid FROM descendants
        ';

        $bindings = $rootBlocks->values()->all();
        if ($group !== null) {
            $bindings[] = $group;
            $bindings[] = $group;
        }

        $results = DB::select($sql, $bindings);

        return collect($results)->pluck('block_uuid')->unique()->values()->all();
    }

    /**
     * Batch transition steps to a new state using proper state machine transitions.
     *
     * CRITICAL: Uses $step->state->transitionTo() to ensure:
     * - Transition classes execute (handle() methods with business logic)
     * - Observers fire (StepObserver::updated())
     * - State machine guards enforced (canTransition() checks)
     * - Additional fields set (completed_at, is_throttled, etc.)
     *
     * Previous implementation used DB::table()->update() which bypassed ALL of this.
     */
    public static function batchTransitionSteps(array $stepIds, string $toState): void
    {
        if (empty($stepIds)) {
            return;
        }

        $steps = Step::whereIn('id', $stepIds)->get();

        foreach ($steps as $step) {
            try {
                // Use proper state transition - triggers transition class handle() and observers
                $step->state->transitionTo($toState);
            } catch (Exception $e) {
                // Log step id, current/target state, group, class, and
                // exception so the operator can diagnose dispatcher
                // wedges. Pre-fix the catch was silent — a stuck step
                // would show up on the dashboard with no breadcrumb to
                // explain why the cleanup phase failed to clear it.
                Log::channel('jobs')->warning('[StepDispatcher::batchTransitionSteps] transition failed — continuing batch', [
                    'step_id' => $step->id,
                    'class' => $step->class ?? null,
                    'group' => $step->group ?? null,
                    'current_state' => is_object($step->state) ? get_class($step->state) : (string) $step->state,
                    'target_state' => $toState,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Record the per-tick saturation counters into Redis. Keyed by
     * (group, minute-bucket) so a host-app flush cron can roll them
     * up into a persistent table without contention with the live
     * dispatcher. Uses Cache::increment for the hot-path writes;
     * defaults to no-op silently if the cache store does not support
     * atomic increment (e.g. an array store under unit tests).
     *
     * Five counters per (group, bucket):
     *  - ticks_observed              total ticks in this bucket
     *  - ticks_capped                ticks where dispatchable_count == max_per_tick
     *  - ticks_capped_with_leftover  capped ticks AND Pending still > 0 after promotion
     *  - total_dispatched            sum of dispatchable_count across the bucket
     *  - max_pending_after           tracked via Cache::put + max-on-replace
     */
    public static function recordTickMetrics(
        ?string $group,
        int $dispatchableCount,
        bool $wasCapped,
        bool $hasLeftover,
        int $pendingAfterTick,
    ): void {
        $bucket = now()->utc()->format('Y-m-d-H-i');
        $groupKey = $group ?? 'global';
        // Include the runtime prefix in the cache key so two
        // dispatchers running different prefixes but sharing a group
        // name (`trading_alpha` vs `calc_alpha`) don't stomp each
        // other's saturation counters.
        $runtimePrefix = app(RuntimeContext::class)->current();
        $prefix = "dispatcher:saturation:{$runtimePrefix}{$groupKey}:{$bucket}";

        try {
            Cache::increment("{$prefix}:ticks_observed");

            if ($wasCapped) {
                Cache::increment("{$prefix}:ticks_capped");

                if ($hasLeftover) {
                    Cache::increment("{$prefix}:ticks_capped_with_leftover");
                }
            }

            if ($dispatchableCount > 0) {
                Cache::increment("{$prefix}:total_dispatched", $dispatchableCount);
            }

            // Track running max for pending-after via read-then-conditionally-write.
            // Not atomic; in a tight race two ticks may both write the same value.
            // Acceptable for a max gauge.
            $maxKey = "{$prefix}:max_pending_after";
            $existingMax = (int) (Cache::get($maxKey) ?? 0);
            if ($pendingAfterTick > $existingMax) {
                // 90s TTL: bucket is 1 minute long, plus 30s grace for the flush
                // cron to consume the bucket before it expires.
                Cache::put($maxKey, $pendingAfterTick, 90);
            }
        } catch (Throwable) {
            // Telemetry must never break dispatch. Swallow.
        }
    }

    /**
     * Build the per-tick lookup cache the dispatcher needs to avoid N+1
     * canTransition() queries: parents keyed by child block, every step in
     * the touched blocks keyed by block+index, and the set of blocks that
     * have a Pending resolve-exception step.
     *
     * Returns null when there are no pending steps to evaluate; callers
     * pass the null straight into computeDispatchableSteps which short-
     * circuits to an empty collection.
     *
     * @param  Collection<int, Step>  $pendingSteps
     * @return array<string, mixed>|null
     */
    public static function buildStepsCache(Collection $pendingSteps, ?string $group = null): ?array
    {
        $blockUuids = $pendingSteps->pluck('block_uuid')->unique()->values();

        if ($blockUuids->isEmpty()) {
            return null;
        }

        // Scope the cache queries to the active dispatcher group lane.
        // Pre-fix, parent / sibling / resolve-exception lookups read
        // unscoped state — a group-scoped pending-step selector could
        // then evaluate transitions against rows from other groups.
        // UUID collisions are unlikely, but the boundary is a real
        // isolation contract.
        $parentsByChildBlock = Step::whereIn('child_block_uuid', $blockUuids)
            ->forGroup($group)
            ->get()
            ->keyBy('child_block_uuid');

        $blockSteps = Step::whereIn('block_uuid', $blockUuids)
            ->forGroup($group)
            ->get();

        $stepsByBlockAndIndex = $blockSteps
            ->groupBy(static fn (Step $s): string => $s->block_uuid.'_'.$s->index);

        // Derived in PHP from the block fetch above — the same rows; a
        // third SELECT for this subset was a wasted round-trip per tick.
        $pendingResolveExceptions = $blockSteps
            ->filter(static fn (Step $s): bool => $s->type === 'resolve-exception' && $s->state instanceof Pending)
            ->pluck('block_uuid')
            ->flip()
            ->all();

        return [
            'parents_by_child_block' => $parentsByChildBlock,
            'steps_by_block_and_index' => $stepsByBlockAndIndex,
            'pending_resolve_exceptions' => $pendingResolveExceptions,
        ];
    }

    /**
     * Pre-compute which pending steps can be dispatched using batch operations.
     * Eliminates per-step canTransition() overhead by computing decisions in bulk.
     *
     * @param  Collection<int, Step>  $pendingSteps
     * @param  array<string, mixed>|null  $stepsCache
     * @return Collection<int, Step>
     */
    public static function computeDispatchableSteps(
        Collection $pendingSteps,
        ?array $stepsCache
    ): Collection {
        if ($pendingSteps->isEmpty() || $stepsCache === null) {
            return collect();
        }

        $parentsByChildBlock = $stepsCache['parents_by_child_block'];
        $stepsByBlockAndIndex = $stepsCache['steps_by_block_and_index'];
        $pendingResolveExceptions = $stepsCache['pending_resolve_exceptions'];

        // Pre-compute concluded indices per block
        $concludedIndicesByBlock = self::computeConcludedIndices($stepsByBlockAndIndex);

        return $pendingSteps->filter(static function (Step $step) use (
            $parentsByChildBlock,
            $concludedIndicesByBlock,
            $pendingResolveExceptions
        ) {
            // Must be in Pending state
            if (! $step->state instanceof Pending) {
                return false;
            }

            // 1. resolve-exception without index → always dispatch
            if ($step->type === 'resolve-exception' && is_null($step->index)) {
                return true;
            }

            // 2. resolve-exception with index → check previous resolve-exception concluded
            if ($step->type === 'resolve-exception' && ! is_null($step->index)) {
                if ($step->index === 1) {
                    return true;
                }
                $key = $step->block_uuid.'_'.($step->index - 1).'_resolve-exception';

                return isset($concludedIndicesByBlock[$key]);
            }

            // Determine step category
            $hasParent = isset($parentsByChildBlock[$step->block_uuid]);
            $isParentStep = ! is_null($step->child_block_uuid);

            // 3. Orphan (no parent, no children)
            if (! $hasParent && ! $isParentStep) {
                if (is_null($step->index)) {
                    return true;
                }

                return self::previousIndexConcludedBatch($step, $concludedIndicesByBlock, $pendingResolveExceptions);
            }

            // 4. Child step → parent must be Running/Completed
            if ($hasParent) {
                $parent = $parentsByChildBlock[$step->block_uuid];
                $parentState = get_class($parent->state);
                if (! in_array($parentState, [Running::class, Completed::class], strict: true)) {
                    return false;
                }

                // Child with null index and parent running → dispatch
                if (is_null($step->index)) {
                    return $parent->state instanceof Running;
                }

                return self::previousIndexConcludedBatch($step, $concludedIndicesByBlock, $pendingResolveExceptions);
            }

            // 5. Parent step (remaining case: not orphan, not child)
            return self::previousIndexConcludedBatch($step, $concludedIndicesByBlock, $pendingResolveExceptions);
        });
    }

    /**
     * Pre-compute which (block_uuid, index, type) combinations are concluded.
     *
     * @return array<string, bool>
     */
    public static function computeConcludedIndices(Collection $stepsByBlockAndIndex): array
    {
        $concluded = [];

        foreach ($stepsByBlockAndIndex as $key => $steps) {
            // Key format: "block_uuid_index"
            $parts = explode('_', $key);
            $index = array_pop($parts);
            $blockUuid = implode(separator: '_', array: $parts);

            // Check default type steps
            $defaultSteps = $steps->where('type', 'default');
            if ($defaultSteps->isNotEmpty() && $defaultSteps->every(
                static function ($s) {
                    return in_array(get_class($s->state), Step::concludedStepStates(), strict: true);
                }
            )) {
                $concluded[$blockUuid.'_'.$index.'_default'] = true;
            }

            // Check resolve-exception type steps
            $resolveSteps = $steps->where('type', 'resolve-exception');
            if ($resolveSteps->isNotEmpty() && $resolveSteps->every(
                static function ($s) {
                    return in_array(get_class($s->state), Step::concludedStepStates(), strict: true);
                }
            )) {
                $concluded[$blockUuid.'_'.$index.'_resolve-exception'] = true;
            }
        }

        return $concluded;
    }

    /**
     * Check if previous index is concluded using pre-computed data.
     *
     * @param  array<string, bool>  $concludedIndicesByBlock
     * @param  array<string, int>  $pendingResolveExceptions
     */
    public static function previousIndexConcludedBatch(
        Step $step,
        array $concludedIndicesByBlock,
        array $pendingResolveExceptions
    ): bool {
        if ($step->index === 1) {
            return true;
        }

        $type = isset($pendingResolveExceptions[$step->block_uuid]) ? 'resolve-exception' : 'default';
        $key = $step->block_uuid.'_'.($step->index - 1).'_'.$type;

        return isset($concludedIndicesByBlock[$key]);
    }

    /**
     * Resolve the per-prefix active-flag file path. Default prefix
     * `''` keeps the legacy `active.flag` filename. A prefixed
     * dispatcher writes to `{prefix}active.flag`, e.g.
     * `trading_active.flag`. Without per-prefix flag scoping a
     * prefixed dispatcher going idle would deactivate the shared
     * flag and the default dispatcher would skip its next tick on
     * the `isActive() === false` early return — masking real work.
     */
    private static function flagFilePath(): string
    {
        $prefix = app(RuntimeContext::class)->current();

        return self::flagDir().'/'.$prefix.'active.flag';
    }

    /**
     * Resolve the configured flag directory path.
     *
     * @throws RuntimeException if flag_path is not configured
     */
    private static function flagDir(): string
    {
        $path = config('step-dispatcher.flag_path');

        if (empty($path)) {
            throw new RuntimeException(
                'step-dispatcher.flag_path is not configured. '
                .'Publish the step-dispatcher config or ensure storage_path() is available. '
                .'All applications sharing the same database must point to the same path.'
            );
        }

        return $path;
    }
}
