<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;
use StepDispatcher\Support\StepDispatcher;

beforeEach(function () {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());

    // Prevent the actual queue from executing the dispatched job (the test
    // job class doesn't resolve), so we can observe step state immediately
    // after the dispatcher tick instead of after the worker has flipped them
    // to Failed.
    Queue::fake();
});

/**
 * Per-tick load-shedding: the dispatcher MUST cap how many Pending steps
 * it hydrates and promotes per tick per group. Without a cap, a single
 * group with thousands of Pending rows monopolises the tick budget,
 * exhausts memory, and starves sibling groups. This is the orthogonal
 * scale concern called out in the 2026-04-25 wedge incident — Fix C.
 *
 * Design: a config knob `step-dispatcher.dispatch.max_per_tick` bounds
 * the `Step::pending()->...->get()` query. Default value lives in the
 * package config; a test override sets it low so the assertion is
 * deterministic on a small fixture.
 */
it('caps the number of Pending steps promoted per tick at max_per_tick', function () {
    config()->set('step-dispatcher.dispatch.max_per_tick', 2);

    // Seed five orphan Pending steps (no parent/child, no index sequencing
    // dependency between them) — every one would dispatch in a single
    // unbounded tick. With the cap at 2, we expect exactly 2 to land in
    // Dispatched and the remaining 3 to stay Pending.
    $steps = collect(range(1, 5))->map(static function (): Step {
        return Step::create([
            'class' => 'App\\Jobs\\TestJob',
            'type' => 'default',
            'queue' => 'default',
            'group' => 'tick-limit-test',
            'index' => null,
            'block_uuid' => (string) Str::uuid(),
            'state' => Pending::class,
        ]);
    });

    StepDispatcher::dispatch('tick-limit-test');

    // "Promoted" = no longer Pending. Whatever lands beyond Pending
    // (Dispatched directly, or executed-then-failed under a sync queue
    // worker) counts as a step the dispatcher decided to act on this tick.
    // The cap test cares about that decision boundary, not the post-
    // execution state.
    $promotedCount = Step::whereIn('id', $steps->pluck('id'))
        ->where('state', '!=', Pending::class)
        ->count();

    $pendingCount = Step::whereIn('id', $steps->pluck('id'))
        ->where('state', Pending::class)
        ->count();

    expect($promotedCount)->toBe(
        2,
        'Dispatcher must respect step-dispatcher.dispatch.max_per_tick — '
        .'an unbounded `->get()` lets one runaway group monopolise the tick budget '
        .'and starve every sibling group (production wedge 2026-04-25).'
    );

    expect($pendingCount)->toBe(
        3,
        'Steps beyond the per-tick cap must remain Pending, picked up on subsequent ticks in waves.'
    );
});
