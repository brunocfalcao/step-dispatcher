<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;
use StepDispatcher\Support\StepDispatcher;

beforeEach(function () {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());

    // Faked queue so dispatched jobs never resolve a real worker; we
    // observe step state directly after the dispatcher tick instead of
    // after a worker has flipped them to Failed.
    Queue::fake();
});

/**
 * Priority Queue System — must bypass the per-tick fetch cap.
 *
 * The dispatcher caps how many Pending rows it hydrates per tick via
 * `step-dispatcher.dispatch.max_per_tick` so a runaway batch can't
 * monopolise CPU. The cap is FIFO by `id ASC`. A high-priority step
 * inserted AFTER a large non-priority backlog ends up at the tail of
 * the FIFO, beyond the cap window. With the legacy single-pass
 * implementation, the dispatcher fetches `LIMIT max_per_tick` rows
 * first, THEN filters to priority='high' from the in-memory result —
 * a step outside the fetched window is invisible to the filter and
 * never gets promoted, even though latency-sensitive workflows
 * (observer-dispatched corrections, position closes, WAP) explicitly
 * marked themselves priority precisely to avoid this kind of wait.
 *
 * Contract: priority='high' Pending steps must be fetched and
 * promoted on EVERY tick regardless of how many non-priority rows
 * sit ahead of them in the FIFO ordering, and regardless of the
 * `max_per_tick` cap. The cap applies only to the non-priority
 * backlog.
 *
 * Production trigger (2026-05-01): a 1700-row leverage-bracket batch
 * created at 07:15 buried an observer-dispatched
 * PrepareOrderCorrectionJob (priority='high') for ~8 minutes. The
 * step was Pending the whole time, never reaching the dispatcher's
 * 100-row visible window until the leverage backlog drained. The
 * priority routing did its job once the step was visible — but the
 * point of priority is to be visible immediately.
 */
it('promotes a priority-high step even when more non-priority pending rows exist than max_per_tick', function () {
    config()->set('step-dispatcher.dispatch.max_per_tick', 2);

    // Three non-priority Pending steps fill the FIFO front.
    $nonPriority = collect(range(1, 3))->map(static function (): Step {
        return Step::create([
            'class' => 'App\\Jobs\\TestJob',
            'type' => 'default',
            'queue' => 'default',
            'group' => 'priority-bypass-test',
            'index' => null,
            'block_uuid' => (string) Str::uuid(),
            'state' => Pending::class,
        ]);
    });

    // One priority='high' step inserted LAST so it has the largest id —
    // outside the max_per_tick=2 fetch window under FIFO ordering.
    $priorityStep = Step::create([
        'class' => 'App\\Jobs\\TestJob',
        'type' => 'default',
        'queue' => 'priority',
        'group' => 'priority-bypass-test',
        'index' => null,
        'block_uuid' => (string) Str::uuid(),
        'state' => Pending::class,
        'priority' => 'high',
    ]);

    StepDispatcher::dispatch('priority-bypass-test');

    $priorityStillPending = Step::where('id', $priorityStep->id)
        ->where('state', Pending::class)
        ->count();

    $nonPriorityStillPending = Step::whereIn('id', $nonPriority->pluck('id'))
        ->where('state', Pending::class)
        ->count();

    expect($priorityStillPending)->toBe(0,
        'A priority=high step must be promoted regardless of where it sits in the FIFO '
        .'and regardless of max_per_tick. The whole point of priority is latency '
        .'isolation from bulk backlogs.'
    );

    expect($nonPriorityStillPending)->toBe(3,
        'When priority-high work exists, the dispatcher must defer all non-priority '
        .'rows to a later tick — they must stay Pending this tick.'
    );
});

/**
 * Defensive sibling test — when no priority steps exist, the
 * dispatcher MUST continue to honour max_per_tick on the non-priority
 * FIFO. The priority bypass must not change the behaviour of pure
 * non-priority workloads. This pins the existing contract that the
 * sibling DispatcherTickLimitTest already asserts, but verifies the
 * priority-pass implementation does not regress it.
 */
it('continues to respect max_per_tick when no priority-high steps exist', function () {
    config()->set('step-dispatcher.dispatch.max_per_tick', 2);

    $steps = collect(range(1, 5))->map(static function (): Step {
        return Step::create([
            'class' => 'App\\Jobs\\TestJob',
            'type' => 'default',
            'queue' => 'default',
            'group' => 'priority-bypass-noregress',
            'index' => null,
            'block_uuid' => (string) Str::uuid(),
            'state' => Pending::class,
        ]);
    });

    StepDispatcher::dispatch('priority-bypass-noregress');

    $promoted = Step::whereIn('id', $steps->pluck('id'))
        ->where('state', '!=', Pending::class)
        ->count();

    expect($promoted)->toBe(2,
        'Without priority-high steps, max_per_tick still bounds non-priority '
        .'promotion — the priority bypass must not unbind the cap.'
    );
});
