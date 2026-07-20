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

        // workflow_id / priority / group inheritance happens in saving()
        // (which Eloquent fires BEFORE creating on inserts) from a single
        // consolidated parent lookup — see saving().
    }

    public function saving(Step $step): void
    {
        // Clear hostname when step transitions to Pending state (e.g., throttled jobs, retries)
        // This ensures the step can be picked up by any worker server, not tied to a specific host
        if ($step->state instanceof Pending) {
            $step->hostname = null;
        }

        // Inheritance — at CREATION time only, from ONE consolidated
        // parent lookup (a parent is the step whose `child_block_uuid`
        // matches this step's `block_uuid`):
        //
        //   workflow_id — chain identity flows down the tree; falls back
        //     to a sibling in the same block, else a fresh UUID.
        //
        //   priority — children spawned by a priority='high' parent
        //     inherit 'high' so the whole workflow chain stays on the
        //     priority lane. Only when `priority` is null on the new
        //     step — explicit priority on the child wins. Production
        //     trigger (2026-05-01): an observer-dispatched
        //     PreparePositionReplacementJob (priority='high') spawned
        //     children at priority=null; SmartReplaceOrdersJob landed
        //     behind 150 pending non-priority steps, stalling the SL
        //     recreation.
        //
        //   group — the dispatcher lane; falls back to a sibling, else
        //     round-robin. Creation-only, so a step never changes lane
        //     mid-flight.
        //
        // Runs in `saving` (not `creating`) because Eloquent fires
        // `saving` before `creating`, and the priority→queue routing
        // below depends on the inherited priority being set first.
        // Bulk block creation fires this per row — that is why the
        // previous three separate parent lookups were collapsed into one.
        if (! $step->exists && ! empty($step->block_uuid)) {
            $needsWorkflow = empty($step->workflow_id);
            $needsPriority = $step->priority === null;
            $needsGroup = empty($step->group);

            $parentStep = ($needsWorkflow || $needsPriority || $needsGroup)
                ? Step::query()->where('child_block_uuid', $step->block_uuid)->first()
                : null;

            if ($needsWorkflow && $parentStep !== null && ! empty($parentStep->workflow_id)) {
                $step->workflow_id = $parentStep->workflow_id;
                $needsWorkflow = false;
            }

            if ($needsPriority && $parentStep !== null && $parentStep->priority === 'high') {
                $step->priority = 'high';
            }

            if ($needsGroup && $parentStep !== null && ! empty($parentStep->group)) {
                $step->group = $parentStep->group;
                $needsGroup = false;
            }

            // Sibling fallbacks (same block) only when the parent lookup
            // didn't resolve the field — the uncommon path.
            if ($needsWorkflow) {
                $siblingStep = Step::query()
                    ->where('block_uuid', $step->block_uuid)
                    ->whereNotNull('workflow_id')
                    ->first();

                if ($siblingStep !== null) {
                    $step->workflow_id = $siblingStep->workflow_id;
                    $needsWorkflow = false;
                }
            }

            if ($needsGroup) {
                $siblingStep = Step::query()
                    ->where('block_uuid', $step->block_uuid)
                    ->whereNotNull('group')
                    ->first();

                if ($siblingStep !== null) {
                    $step->group = $siblingStep->group;
                    $needsGroup = false;
                }
            }

            if ($needsWorkflow) {
                $step->workflow_id = Str::uuid()->toString();
            }

            if ($needsGroup) {
                $step->group = Step::getDispatchGroup();
            }
        } elseif (! $step->exists) {
            // No block context at all (block_uuid is defaulted later, in
            // creating()): fresh workflow, round-robin group.
            if (empty($step->workflow_id)) {
                $step->workflow_id = Str::uuid()->toString();
            }

            if (empty($step->group)) {
                $step->group = Step::getDispatchGroup();
            }
        }

        // Queue normalisation — at CREATION time only. After creation the
        // queue column is owned by the dispatch-time queue resolver, which
        // composes physical "{hostname}-{logical}" names (eos-positions,
        // tyche-priority) from a dynamic host pool that queues.valid can
        // never enumerate; validating on every save reset those resolved
        // names and stranded high-priority workflows on dead queues
        // (2026-06-05, first live trading smoke test).
        //
        // Rule: an explicitly-set VALID queue always wins — targeted
        // per-hostname queues (e.g. registration connectivity probes,
        // one per server by design) must survive priority routing
        // (2026-07-20: the unconditional high-priority rewrite clobbered
        // per-server fan-out queues to the shared priority lane,
        // collapsing the one-probe-per-server guarantee). Only an empty
        // or invalid queue is rewritten: priority='high' steps default
        // to the priority lane, everything else to 'default'.
        if (! $step->exists) {
            $validQueues = array_merge(
                [
                    'default',
                    'priority',
                    mb_strtolower(gethostname()),
                    mb_strtolower(str_replace('-', '', gethostname() ?: 'unknown')),
                ],
                config('step-dispatcher.queues.valid', [])
            );

            if (empty($step->queue) || ! in_array($step->queue, $validQueues, strict: true)) {
                $step->queue = $step->priority === 'high' ? 'priority' : 'default';
            }
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

        // Group backfill happens in the consolidated creation-time
        // inheritance block above — at CREATION time only. The group is
        // the dispatcher's isolation lane; reassigning it on a later save
        // (e.g. a state transition of a raw-inserted null-group row) would
        // move the step between lanes mid-flight, so the tick that owns it
        // and the tick that picks it up next would disagree. Rows created
        // outside Eloquent (raw inserts) that carry group=NULL stay in the
        // NULL lane permanently and are served by null-lane ticks.
    }

    public function created(Step $step): void
    {
        // Activate the dispatcher when a new step is created
        StepDispatcher::activate();

        // Seed the step's diagnostic log folder with a creation marker. The
        // log trait early-returns when logging is disabled, so this is a
        // no-op in production unless STEP_DISPATCHER_LOGGING_ENABLED is on.
        //
        // Arguments are truncated to 200 chars to keep the line greppable
        // when a job carries a large payload. Most Kraite jobs take simple
        // {positionId}, {orderId}, {exchangeSymbolId} shapes that fit easily.
        $argumentsPreview = 'null';
        if (! empty($step->arguments)) {
            $encoded = is_array($step->arguments)
                ? (json_encode($step->arguments) ?: '{}')
                : (string) $step->arguments;
            $argumentsPreview = mb_substr($encoded, 0, 200);
        }

        Step::log($step->id, 'states', sprintf(
            'CREATED | class=%s | args=%s | block=%s | child_block=%s | index=%s | group=%s | priority=%s | queue=%s',
            $step->class ?? 'null',
            $argumentsPreview,
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
