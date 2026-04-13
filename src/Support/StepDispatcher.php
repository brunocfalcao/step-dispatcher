<?php

declare(strict_types=1);

namespace StepDispatcher\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

final class StepDispatcher
{
    use DispatchesJobs;

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
        $dir = storage_path('step-dispatcher');

        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }

        $flag = $dir.'/active.flag';

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
        $flag = storage_path('step-dispatcher/active.flag');

        if (file_exists($flag)) {
            @unlink($flag);
        }
    }

    /**
     * Check if the dispatcher is active (flag file exists).
     */
    public static function isActive(): bool
    {
        return file_exists(storage_path('step-dispatcher/active.flag'));
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
            return;
        }

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

            $pendingQuery = Step::pending()
                ->when($group !== null, static fn ($q) => $q->where('group', $group), static fn ($q) => $q->whereNull('group'))
                ->where(static function ($q) {
                    $q->whereNull('dispatch_after')
                        ->orWhere('dispatch_after', '<=', now());
                });

            $pendingSteps = $pendingQuery->get();

            // Priority Queue System: If any high-priority steps exist, filter to only those
            if ($pendingSteps->contains('priority', 'high')) {
                $pendingSteps = $pendingSteps->where('priority', 'high')->values();
            }

            // Build cache to avoid N+1 queries in PendingToDispatched::canTransition()
            $stepsCache = null;
            $blockUuids = $pendingSteps->pluck('block_uuid')->unique()->values();

            if ($blockUuids->isNotEmpty()) {
                // Query 1: Get parent steps (for isChild/getParentStep checks)
                $parentsByChildBlock = Step::whereIn('child_block_uuid', $blockUuids)
                    ->get()
                    ->keyBy('child_block_uuid');

                // Query 2: Get all steps in these blocks (for previousIndexIsConcluded)
                $stepsByBlockAndIndex = Step::whereIn('block_uuid', $blockUuids)
                    ->get()
                    ->groupBy(static fn (Step $s): string => $s->block_uuid.'_'.$s->index);

                // Query 3: Get blocks with pending resolve-exceptions
                $pendingResolveExceptions = Step::whereIn('block_uuid', $blockUuids)
                    ->where('type', 'resolve-exception')
                    ->where('state', Pending::class)
                    ->pluck('block_uuid')
                    ->flip()
                    ->all();

                $stepsCache = [
                    'parents_by_child_block' => $parentsByChildBlock,
                    'steps_by_block_and_index' => $stepsByBlockAndIndex,
                    'pending_resolve_exceptions' => $pendingResolveExceptions,
                ];
            }

            // Batch pre-compute which steps can be dispatched (eliminates per-step canTransition() calls)
            $dispatchableSteps = self::computeDispatchableSteps($pendingSteps, $stepsCache);

            // Apply transitions for all dispatchable steps
            $dispatchableSteps->each(static function (Step $step) use ($dispatchedSteps, $stepsCache) {
                $transition = new PendingToDispatched($step, $stepsCache);
                $transition->apply();
                $dispatchedSteps->push($step);
            });

            // Dispatch all steps that are ready
            $dispatchedSteps->each(static function ($step) {
                (new self)->dispatchSingleStep($step);
            });

            $progress = 8;
        } finally {
            StepsDispatcher::endDispatch($progress, $group);

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
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->whereNotNull('child_block_uuid')
            ->orderBy('index')
            ->orderBy('id')
            ->get();

        if ($runningParents->isEmpty()) {
            return false;
        }

        $childBlockUuids = self::collectAllNestedChildBlocks($runningParents, $group);

        $childStepsByBlock = Step::whereIn('block_uuid', $childBlockUuids)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
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
            } catch (\Exception $e) {
                // Log exception if needed
            }
        }

        return $changed;
    }

    /**
     * If a parent was skipped, mark all its descendants as skipped.
     */
    public static function skipAllChildStepsOnParentAndChildSingleStep(?string $group = null): bool
    {
        $skippedParents = Step::where('state', Skipped::class)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->whereNotNull('child_block_uuid')
            ->get();

        if ($skippedParents->isEmpty()) {
            return false;
        }

        $allChildBlocks = self::collectAllNestedChildBlocks($skippedParents, $group);

        if (empty($allChildBlocks)) {
            return true;
        }

        $descendantIds = Step::whereIn('block_uuid', $allChildBlocks)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->pluck('id')
            ->all();

        if (! empty($descendantIds)) {
            self::batchTransitionSteps($descendantIds, Skipped::class);
        }

        return true;
    }

    /**
     * Transition running parents to Failed if any child in their block failed.
     * Note: Only handles Failed children. Stopped children are handled by transitionParentsToStopped().
     */
    public static function transitionParentsToFailed(?string $group = null): bool
    {
        $runningParents = Step::where('state', Running::class)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->whereNotNull('child_block_uuid')
            ->get();

        if ($runningParents->isEmpty()) {
            return false;
        }

        $childBlockUuids = $runningParents->pluck('child_block_uuid')->filter()->unique()->all();

        $childStepsByBlock = Step::whereIn('block_uuid', $childBlockUuids)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->get()
            ->groupBy('block_uuid');

        foreach ($runningParents as $parentStep) {
            $childSteps = $childStepsByBlock->get($parentStep->child_block_uuid, collect());

            if ($childSteps->isEmpty()) {
                continue;
            }

            // Only check for Failed children (not Stopped - that's handled by transitionParentsToStopped)
            $failedChildSteps = $childSteps->filter(
                static fn ($step) => get_class($step->state) === Failed::class
            );

            if ($failedChildSteps->isNotEmpty()) {
                $failedIds = $failedChildSteps->pluck('id')->join(', ');

                // Check if there are any non-terminal resolve-exception steps in the child block.
                // If so, wait for them to complete before failing the parent.
                $nonTerminalResolveExceptions = $childSteps->filter(
                    static function ($step) {
                        return $step->type === 'resolve-exception'
                            && ! in_array(get_class($step->state), Step::terminalStepStates(), strict: true);
                    }
                );

                if ($nonTerminalResolveExceptions->isNotEmpty()) {
                    continue;
                }

                // Get the indices of all failed children to check parallel siblings
                $failedIndices = $failedChildSteps->pluck('index')->unique();

                // Check if there are any non-terminal siblings at the same index as the failed children.
                // When steps run in parallel (same index), we must wait for ALL to reach terminal state.
                $nonTerminalParallelSiblings = $childSteps->filter(
                    static function ($step) use ($failedIndices) {
                        // Only check steps at the same index as failed steps
                        if (! $failedIndices->contains($step->index)) {
                            return false;
                        }

                        // Skip steps that are already in terminal state
                        return ! in_array(get_class($step->state), Step::terminalStepStates(), strict: true);
                    }
                );

                if ($nonTerminalParallelSiblings->isNotEmpty()) {
                    continue;
                }

                // Set error message before transitioning to Failed
                $parentStep->update([
                    'error_message' => "Child step(s) failed: [{$failedIds}]",
                ]);

                $parentStep->state->transitionTo(Failed::class);

                return true;
            }
        }

        return false;
    }

    /**
     * Transition running parents to Stopped if any child in their block was stopped.
     */
    public static function transitionParentsToStopped(?string $group = null): bool
    {
        $runningParents = Step::where('state', Running::class)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->whereNotNull('child_block_uuid')
            ->get();

        if ($runningParents->isEmpty()) {
            return false;
        }

        $childBlockUuids = $runningParents->pluck('child_block_uuid')->filter()->unique()->all();

        $childStepsByBlock = Step::whereIn('block_uuid', $childBlockUuids)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->get()
            ->groupBy('block_uuid');

        foreach ($runningParents as $parentStep) {
            $childSteps = $childStepsByBlock->get($parentStep->child_block_uuid, collect());

            if ($childSteps->isEmpty()) {
                continue;
            }

            // Check for Stopped children
            $stoppedChildSteps = $childSteps->filter(
                static fn ($step) => get_class($step->state) === Stopped::class
            );

            if ($stoppedChildSteps->isNotEmpty()) {
                $stoppedIds = $stoppedChildSteps->pluck('id')->join(', ');

                // Set error message before transitioning to Stopped
                $parentStep->update([
                    'error_message' => "Child step(s) stopped: [{$stoppedIds}]",
                ]);

                $parentStep->state->transitionTo(Stopped::class);

                return true;
            }
        }

        return false;
    }

    /**
     * Cancel downstream runnable steps after a failure/stop.
     */
    public static function cascadeCancelledSteps(?string $group = null): bool
    {
        $cancellationsOccurred = false;

        // Find all failed/stopped steps that should trigger downstream cancellation.
        $failedSteps = Step::whereIn('state', Step::failedStepStates())
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->whereNotNull('index')
            ->get();

        if ($failedSteps->isEmpty()) {
            return false;
        }

        foreach ($failedSteps as $failedStep) {
            $blockUuid = $failedStep->block_uuid;

            // Cancel only non-terminal, runnable steps at higher indexes in the same block.
            $stepsToCancel = Step::where('block_uuid', $blockUuid)
                ->when($group !== null, static fn ($q) => $q->where('group', $group))
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
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
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
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->where('state', NotRunnable::class)
            ->pluck('block_uuid')
            ->filter()
            ->unique()
            ->values();

        if ($candidateBlocks->isEmpty()) {
            return false;
        }

        $failingBlocks = Step::whereIn('block_uuid', $candidateBlocks)
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
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
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->where('type', 'resolve-exception')
            ->where('state', NotRunnable::class)
            ->pluck('id')
            ->all();

        if (! empty($stepIds)) {
            self::batchTransitionSteps($stepIds, Pending::class);
        }

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
            ->when($group !== null, static fn ($q) => $q->where('group', $group))
            ->whereNotNull('child_block_uuid')
            ->get();

        if ($failedOrStoppedParents->isEmpty()) {
            return false;
        }

        foreach ($failedOrStoppedParents as $parent) {
            $childBlock = $parent->child_block_uuid;

            $nonTerminalChildIds = Step::where('block_uuid', $childBlock)
                ->when($group !== null, static fn ($q) => $q->where('group', $group))
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

        $sql = "
            WITH RECURSIVE descendants AS (
                SELECT child_block_uuid AS block_uuid
                FROM steps
                WHERE child_block_uuid IN ({$placeholders})
                  AND child_block_uuid IS NOT NULL
                  ".($group !== null ? 'AND `group` = ?' : '').'

                UNION ALL

                SELECT s.child_block_uuid
                FROM steps s
                INNER JOIN descendants d ON s.block_uuid = d.block_uuid
                WHERE s.child_block_uuid IS NOT NULL
                  '.($group !== null ? 'AND s.`group` = ?' : '').'
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
            } catch (\Exception $e) {
                // Log but continue - don't fail entire batch due to one invalid transition
            }
        }
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

            // 5. Parent step
            if ($isParentStep) {
                return self::previousIndexConcludedBatch($step, $concludedIndicesByBlock, $pendingResolveExceptions);
            }

            return false;
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
}
