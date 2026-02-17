<?php

declare(strict_types=1);

namespace StepDispatcher\Transitions;

use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use Spatie\ModelStates\Transition;

final class PendingToDispatched extends Transition
{
    private Step $step;

    private ?array $stepsCache;

    public function __construct(Step $step, ?array $stepsCache = null)
    {
        $this->step = $step;
        $this->stepsCache = $stepsCache;
    }

    public function canTransition(): bool
    {
        if (! $this->step->state instanceof Pending) {
            return false;
        }

        /**
         * Check if the step is a 'resolve-exception' without index.
         * The logic to put this resolve-exception into pending state is made
         * as a passive decision, no worries.
         */
        if ($this->step->type === 'resolve-exception' && is_null($this->step->index)) {
            return true;
        }

        // Check if the step is a 'resolve-exception' with an index.
        if ($this->step->type === 'resolve-exception' && ! is_null($this->step->index)) {
            // If the index is 1, there's no previous step to check, so allow the transition
            if ($this->step->index === 1) {
                return true;
            }

            // Ensure that the previous step (index - 1) is 'resolve-exception' and completed.
            $previousSteps = Step::where('block_uuid', $this->step->block_uuid)
                ->where('index', $this->step->index - 1)
                ->where('type', 'resolve-exception')
                ->get();

            // If the previous step exists and is completed, allow transition
            if ($previousSteps->isNotEmpty() && in_array(get_class($previousSteps->first()->state), Step::concludedStepStates(), strict: true)) {
                return true;
            }

            return false;
        }

        /**
         * Orphan step:
         * ----------------------------
         * No parent and no child block.
         * If index is null → dispatch immediately.
         * Else → dispatch only if previous index is concluded.
         */
        if ($this->isOrphan()) {
            if (is_null($this->step->index)) {
                return true;
            }

            return $this->previousIndexIsConcluded();
        }

        /**
         * Child step:
         * ----------------------------
         * Belongs to a child block (has a parent).
         * Dispatch if parent has started (Running or Completed)
         * and previous index in same block is concluded.
         */
        if ($this->isChild()) {
            $parent = $this->getParentStep();

            if (! $parent) {
                return false;
            }

            $parentState = get_class($parent->state);
            if (! in_array($parentState, [Running::class, Completed::class], strict: true)) {
                return false;
            }

            return $this->previousIndexIsConcluded();
        }

        /**
         * Parent step:
         * ----------------------------
         * Spawns a child block (has child_block_uuid).
         * Dispatch if previous index is concluded.
         * Children may not exist yet at this point.
         */
        if ($this->isParent()) {
            return $this->previousIndexIsConcluded();
        }

        /**
         * Fallback:
         * ----------------------------
         * Not orphan, not child, not parent.
         * Should never happen, deny dispatch.
         */
        return false;
    }

    public function handle(): Step
    {
        return $this->apply();
    }

    public function apply(): Step
    {
        $this->step->state = new Dispatched($this->step); // Transition to Dispatched state

        // If we have a tick id, let's update the step with it.
        // Cache key includes group suffix to match StepsDispatcher::startDispatch()
        $cacheSuffix = $this->step->group ?? 'global';
        $cacheKey = "current_tick_id:{$cacheSuffix}";

        if (cache()->has($cacheKey)) {
            $tickId = cache($cacheKey);
            $this->step->tick_id = $tickId;
        }

        $this->step->save();

        return $this->step;
    }

    /**
     * Get parent step from cache or database.
     * Replicates Step::parentStep() logic but uses cache when available.
     */
    private function getParentStep(): ?Step
    {
        if ($this->stepsCache !== null) {
            return $this->stepsCache['parents_by_child_block'][$this->step->block_uuid] ?? null;
        }

        return Step::where('child_block_uuid', $this->step->block_uuid)->first();
    }

    /**
     * Check if previous index is concluded from cache or database.
     * Replicates Step::previousIndexIsConcluded() logic but uses cache when available.
     */
    private function previousIndexIsConcluded(): bool
    {
        if ($this->step->index === 1) {
            return true;
        }

        if ($this->step->index === null && $this->isChild() && $this->parentIsRunning()) {
            return true;
        }

        if ($this->stepsCache !== null) {
            return $this->previousIndexIsConcludedFromCache();
        }

        return $this->previousIndexIsConcludedFromDatabase();
    }

    /**
     * Check if previous index is concluded using the cache.
     */
    private function previousIndexIsConcludedFromCache(): bool
    {
        $hasPendingResolveException = isset($this->stepsCache['pending_resolve_exceptions'][$this->step->block_uuid]);

        $key = $this->step->block_uuid.'_'.($this->step->index - 1);
        $previousSteps = $this->stepsCache['steps_by_block_and_index'][$key] ?? collect([]);

        if ($hasPendingResolveException) {
            $previousSteps = $previousSteps->where('type', 'resolve-exception');
        } else {
            $previousSteps = $previousSteps->where('type', 'default');
        }

        if ($previousSteps->isEmpty()) {
            return false;
        }

        return $previousSteps->every(
            static fn ($step) => in_array(get_class($step->state), Step::concludedStepStates(), strict: true)
        );
    }

    /**
     * Check if previous index is concluded using database queries (fallback).
     */
    private function previousIndexIsConcludedFromDatabase(): bool
    {
        $hasPendingResolveException = Step::where('block_uuid', $this->step->block_uuid)
            ->where('type', 'resolve-exception')
            ->where('state', Pending::class)
            ->exists();

        $query = Step::where('block_uuid', $this->step->block_uuid)
            ->where('index', $this->step->index - 1);

        if ($hasPendingResolveException) {
            $query->where('type', 'resolve-exception');
        } else {
            $query->where('type', 'default');
        }

        $previousSteps = $query->get();

        if ($previousSteps->isEmpty()) {
            return false;
        }

        return $previousSteps->every(
            static fn ($step) => in_array(get_class($step->state), Step::concludedStepStates(), strict: true)
        );
    }

    /**
     * Check if parent is running (helper for previousIndexIsConcluded).
     */
    private function parentIsRunning(): bool
    {
        $parent = $this->getParentStep();

        return $parent && $parent->state->equals(Running::class);
    }

    /**
     * Check if step is a child (has a parent) using cache when available.
     * Replicates Step::isChild() logic.
     */
    private function isChild(): bool
    {
        if ($this->stepsCache !== null) {
            return isset($this->stepsCache['parents_by_child_block'][$this->step->block_uuid]);
        }

        return Step::where('child_block_uuid', $this->step->block_uuid)->exists();
    }

    /**
     * Check if step is a parent (has children) using cache when available.
     * Replicates Step::isParent() logic.
     */
    private function isParent(): bool
    {
        return ! is_null($this->step->child_block_uuid);
    }

    /**
     * Check if step is an orphan (no parent, no children) using cache when available.
     * Replicates Step::isOrphan() logic.
     */
    private function isOrphan(): bool
    {
        return is_null($this->step->child_block_uuid) && is_null($this->getParentStep());
    }
}
