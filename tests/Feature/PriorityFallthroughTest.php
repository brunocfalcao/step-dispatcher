<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;
use StepDispatcher\Support\StepDispatcher;

beforeEach(function () {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
    Queue::fake();
});

/**
 * Priority fall-through — a poison-pill priority='high' step (Pending
 * but never dispatchable, e.g. an orphan whose previous index does not
 * exist) must NOT starve the non-priority backlog.
 *
 * The 2026-05-01 priority lever introduced two-pass selection: pass 1
 * fetches every priority='high' Pending step uncapped, and only when
 * that pass is empty does pass 2 fetch the non-priority FIFO. The
 * intent was latency isolation for observer-dispatched corrections.
 *
 * Regression (2026-05-07, group eta): an undispatchable priority step
 * sat in pass 1 forever — present, so pass 2 was skipped — but never
 * promoted because its predecessor index was missing. Pass 1 returned
 * non-empty but produced zero dispatches per tick. Result: 660+
 * normal Pending rows wedged for 11+ minutes.
 *
 * Contract: when pass 1 fetches priority work but NONE of it is
 * dispatchable this tick, the dispatcher MUST fall through to the
 * non-priority pass. Pass 2 must run whenever pass 1 produces zero
 * dispatchable work — not only when pass 1 fetches zero rows.
 */
it('falls through to non-priority pass when every priority-high step is undispatchable', function () {
    config()->set('step-dispatcher.dispatch.max_per_tick', 100);

    // Priority='high' poison pill — orphan at index=2 with no index=1
    // sibling. previousIndexConcludedBatch returns false; the step is
    // structurally undispatchable until something creates index=1 in
    // the same block (which will never happen for this kind of broken
    // block).
    $poisonBlock = (string) Str::uuid();
    $poison = Step::create([
        'class' => 'App\\Jobs\\TestJob',
        'type' => 'default',
        'queue' => 'priority',
        'group' => 'priority-fallthrough-test',
        'index' => 2,
        'block_uuid' => $poisonBlock,
        'state' => Pending::class,
        'priority' => 'high',
    ]);

    // Three independent non-priority orphans, each fully dispatchable.
    $nonPriority = collect(range(1, 3))->map(static function (): Step {
        return Step::create([
            'class' => 'App\\Jobs\\TestJob',
            'type' => 'default',
            'queue' => 'default',
            'group' => 'priority-fallthrough-test',
            'index' => null,
            'block_uuid' => (string) Str::uuid(),
            'state' => Pending::class,
        ]);
    });

    StepDispatcher::dispatch('priority-fallthrough-test');

    $poisonStillPending = Step::where('id', $poison->id)
        ->where('state', Pending::class)
        ->count();

    $nonPriorityStillPending = Step::whereIn('id', $nonPriority->pluck('id'))
        ->where('state', Pending::class)
        ->count();

    expect($poisonStillPending)->toBe(1,
        'A truly undispatchable priority-high step must remain Pending — '
        .'we cannot promote a step whose predecessor is missing.'
    );

    expect($nonPriorityStillPending)->toBe(0,
        'When pass 1 yields zero dispatchable priority work, the dispatcher '
        .'must fall through and dispatch the non-priority backlog. Otherwise '
        .'one stuck priority row wedges the entire group.'
    );
});
