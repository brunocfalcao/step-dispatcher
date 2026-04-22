<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Running;

beforeEach(function () {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

/**
 * Race-safety contract for requeueDispatchedSteps.
 *
 * The Dispatched-recovery path fetches candidate steps, then iterates to
 * transition each back to Pending. Between the `get()` that hydrates a
 * step's Dispatched snapshot and the `transitionTo(Pending)` call on that
 * snapshot, a legitimate worker can pop the Redis payload and advance the
 * step to Running. If the command transitions on its stale in-memory
 * snapshot (instead of re-reading DB truth), it clobbers the active run —
 * resets started_at / duration, burns a retry, forces a double-execution.
 *
 * The fix: refresh each step from the DB immediately before the transition
 * and skip if the state is no longer Dispatched.
 *
 * We reproduce the race deterministically in a single process by hooking
 * Eloquent's `retrieved` event — but only on the SECOND retrieval, which
 * is the `requeueDispatchedSteps` query's hydration pass. The first
 * retrieval (the `oldestStep` fetch for the event payload) fires before
 * the requeue loop and would spuriously skip the step out of the requeue
 * query altogether, hiding the race instead of exposing it.
 */
it('does not clobber a step that races to Running between fetch and transition', function () {
    $step = Step::create([
        'class' => 'App\\Jobs\\TestJob',
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'index' => 1,
        'block_uuid' => (string) Str::uuid(),
    ]);

    $staleTimestamp = now()->subMinutes(10);

    Step::withoutEvents(function () use ($step, $staleTimestamp) {
        Step::where('id', $step->id)->update([
            'state' => Dispatched::class,
            'queue' => 'priority',
            'priority' => 'high',
            'updated_at' => $staleTimestamp,
            'created_at' => $staleTimestamp,
        ]);
    });

    // Race injector: fire on the SECOND hydration of this step id. The
    // first hydration is the `oldestStep` payload lookup — if we race
    // there, the requeue query's subsequent fetch finds nothing and the
    // command simply logs CRITICAL without exercising the loop we're
    // testing. The second hydration IS the requeue loop's fetch, and
    // mutating DB immediately after it models the production race.
    $hydrations = 0;
    $raceFired = false;

    Step::retrieved(function (Step $retrieved) use ($step, &$hydrations, &$raceFired): void {
        if ($retrieved->id !== $step->id) {
            return;
        }

        $hydrations++;

        if ($hydrations === 2 && ! $raceFired) {
            $raceFired = true;

            DB::table('steps')->where('id', $step->id)->update([
                'state' => Running::class,
                'started_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    Artisan::call('steps:recover-stale', ['--recover-dispatched' => true]);

    // Contract: the active Running run must survive. Without the fix the
    // stale Dispatched snapshot would drive a transition to Pending.
    expect($raceFired)->toBeTrue('race injector should have fired on the requeue fetch');

    $dbState = DB::table('steps')->where('id', $step->id)->value('state');
    expect($dbState)->toBe(
        Running::class,
        'concurrent Running state must not be clobbered back to Pending by recover-stale'
    );
});
