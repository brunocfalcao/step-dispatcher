<?php

declare(strict_types=1);

namespace StepDispatcher\Observers;

use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\NotRunnable;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;

use StepDispatcher\Support\StepDispatcher;

final class StepObserver
{
    public function creating(Step $step): void
    {
        if (empty($step->block_uuid)) {
            $step->block_uuid = Str::uuid()->toString();
        }

        if ($step->type === 'resolve-exception') {
            $step->state = new NotRunnable($step);
        }

        // Default index to 1 if not provided (null) or set to 0
        // This allows parallel execution: all steps with index=1 can run simultaneously
        if ($step->index === null || $step->index === 0) {
            $step->index = 1;
        }

        // Workflow ID inheritance:
        // 1) If explicit workflow_id provided, use it (genesis step)
        // 2) If parent step exists, inherit from parent
        // 3) If sibling step in same block_uuid has one, inherit
        // 4) Otherwise, generate new UUID
        if (empty($step->workflow_id)) {
            if (! empty($step->block_uuid)) {
                // First, check if there's a parent step that spawned this child block
                $parentStep = Step::query()
                    ->where('child_block_uuid', $step->block_uuid)
                    ->whereNotNull('workflow_id')
                    ->first();

                if ($parentStep) {
                    $step->workflow_id = $parentStep->workflow_id;
                }

                // If no parent, look for siblings in the same block
                if (empty($step->workflow_id)) {
                    $siblingStep = Step::query()
                        ->where('block_uuid', $step->block_uuid)
                        ->whereNotNull('workflow_id')
                        ->first();

                    if ($siblingStep) {
                        $step->workflow_id = $siblingStep->workflow_id;
                    }
                }
            }

            // If still no workflow_id, generate new UUID (new workflow)
            if (empty($step->workflow_id)) {
                $step->workflow_id = Str::uuid()->toString();
            }
        }
    }

    public function saving(Step $step): void
    {
        // Clear hostname when step transitions to Pending state (e.g., throttled jobs, retries)
        // This ensures the step can be picked up by any worker server, not tied to a specific host
        if ($step->state instanceof Pending) {
            $step->hostname = null;
        }

        // Automatically route high priority steps to the priority queue
        if ($step->priority === 'high') {
            $step->queue = 'priority';
        }

        // Queue validation: fallback to 'default' if queue is not valid or empty
        // Valid queues: from config, plus 'default', 'priority', and hostname-based queue
        $validQueues = array_merge(
            ['default', 'priority', mb_strtolower(gethostname())],
            config('step-dispatcher.queues.valid', [])
        );

        if (empty($step->queue) || ! in_array($step->queue, $validQueues, strict: true)) {
            $step->queue = 'default';
        }

        // Set started_at when transitioning TO Running state (if not already set)
        // This covers transitions like PendingToRunning that don't set started_at
        // Only applies to updates (transitions), not initial creates
        $isNowRunning = $step->state instanceof Running;

        $originalState = $step->getOriginal('state');
        $wasRunningBefore = $originalState instanceof Running;

        // Only apply transition logic if this is an UPDATE (step already exists in DB)
        // Check $step->exists to ensure we're not in a create() call
        $isTransition = $step->exists && $originalState !== null;

        if ($isTransition && $isNowRunning && ! $wasRunningBefore && $step->started_at === null) {
            $step->started_at = now();
        }

        // Also set hostname when transitioning TO Running if not already set
        // Defense in depth: ensures hostname is always set when job starts
        if ($isTransition && $isNowRunning && ! $wasRunningBefore && $step->hostname === null) {
            $step->hostname = gethostname();
        }

        // Clear is_throttled when transitioning TO Running
        // Defense in depth: ensures throttle flag is cleared when job actually starts
        if ($isTransition && $isNowRunning && ! $wasRunningBefore) {
            $step->is_throttled = false;
        }

        // Clear is_throttled when step transitions to Completed state
        // This ensures throttled steps that complete have their flag cleared
        if ($step->state instanceof Completed) {
            $step->is_throttled = false;
        }

        if (empty($step->group)) {
            $step->group = $this->resolveGroupForStep($step);
        }
    }

    /**
     * Pick a dispatcher group for a step that was created without one.
     *
     * Resolution order:
     *   1. Fan-out guard — once the step's block has reached the configured
     *      threshold of siblings, skip inheritance and round-robin so bulk
     *      dispatches spread across groups instead of piling onto one.
     *   2. Parent step's group (the orchestrator that spawned this block).
     *   3. Earlier sibling's group in the same block.
     *   4. Round-robin via steps_dispatcher.last_selected_at.
     */
    private function resolveGroupForStep(Step $step): ?string
    {
        if (empty($step->block_uuid)) {
            return Step::getDispatchGroup();
        }

        if ($this->blockHasReachedFanoutThreshold($step->block_uuid)) {
            return Step::getDispatchGroup();
        }

        $parentStep = Step::query()
            ->where('child_block_uuid', $step->block_uuid)
            ->whereNotNull('group')
            ->first();

        if ($parentStep) {
            return $parentStep->group;
        }

        $siblingStep = Step::query()
            ->where('block_uuid', $step->block_uuid)
            ->whereNotNull('group')
            ->first();

        if ($siblingStep) {
            return $siblingStep->group;
        }

        return Step::getDispatchGroup();
    }

    /**
     * Decide whether this block has grown past the fan-out threshold.
     *
     * The count is index-covered by (block_uuid, …) on the steps table and
     * typically costs a sub-millisecond seek. Setting the threshold to 0
     * in config disables fan-out — inheritance becomes the only rule.
     */
    private function blockHasReachedFanoutThreshold(string $blockUuid): bool
    {
        $threshold = (int) config('step-dispatcher.fanout_threshold', 50);

        if ($threshold <= 0) {
            return false;
        }

        return Step::where('block_uuid', $blockUuid)->count() >= $threshold;
    }

    public function created(Step $step): void
    {
        // Activate the dispatcher when a new step is created
        StepDispatcher::activate();
    }

    public function updated(Step $step): void
    {
        // Observer hook - add custom logic here if needed
    }
}
