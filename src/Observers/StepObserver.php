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

        // Ensure group is never null on updates
        if (empty($step->group)) {
            if (! empty($step->block_uuid)) {
                // First, check if there's a parent step that spawned this child block
                $parentStep = Step::query()
                    ->where('child_block_uuid', $step->block_uuid)
                    ->whereNotNull('group')
                    ->first();

                if ($parentStep) {
                    $step->group = $parentStep->group;
                }

                // If no parent, look for siblings in the same block
                if (empty($step->group)) {
                    $siblingStep = Step::query()
                        ->where('block_uuid', $step->block_uuid)
                        ->whereNotNull('group')
                        ->first();

                    if ($siblingStep) {
                        $step->group = $siblingStep->group;
                    }
                }
            }

            // If still no group (no parent/sibling found or first step in chain), assign via round-robin
            if (empty($step->group)) {
                $step->group = Step::getDispatchGroup();
            }
        }
    }

    public function created(Step $step): void
    {
        // Activate the dispatcher when a new step is created
        StepDispatcher::activate();

        // Seed the step's diagnostic log folder with a creation marker. The
        // log trait early-returns when logging is disabled, so this is a
        // no-op in production unless STEP_DISPATCHER_LOGGING_ENABLED is on.
        Step::log($step->id, 'states', sprintf(
            'CREATED | class=%s | block=%s | child_block=%s | index=%s | group=%s | priority=%s | queue=%s',
            $step->class ?? 'null',
            $step->block_uuid ?? 'null',
            $step->child_block_uuid ?? 'null',
            $step->index ?? 'null',
            $step->group ?? 'null',
            $step->priority ?? 'normal',
            $step->queue ?? 'default',
        ));
    }

    public function updated(Step $step): void
    {
        // Observer hook - add custom logic here if needed
    }

    public function deleted(Step $step): void
    {
        // Step row is gone (archived or purged) — remove its log folder so
        // we don't accumulate orphaned directories. Safe to call unconditionally;
        // the method no-ops when logging was never enabled.
        $step->clearLogs();
    }
}
