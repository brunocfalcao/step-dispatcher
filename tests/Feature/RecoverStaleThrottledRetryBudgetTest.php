<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;

beforeEach(function () {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

/**
 * Retry-budget contract: recovering a stale Running step must consume a
 * retry, regardless of the step's `is_throttled` flag.
 *
 * Rationale: the `is_throttled` flag means "the job deliberately rescheduled
 * itself while waiting on an API rate limit" — a throttled reschedule does
 * not count as a retry attempt. But `steps:recover-stale` firing on a
 * Running step is a different signal entirely: the worker died mid-compute.
 * That IS an attempt, and its retry must tick, otherwise a step that keeps
 * timing out while throttled will recover forever and never fail out.
 *
 * `RunningToPending::handle()` only increments retries when `!is_throttled`.
 * So recover-stale has to clear the flag before transitioning, ensuring the
 * retry counter advances.
 */
it('consumes a retry when recovering a throttled stale step', function () {
    // Arrange: a step that's been Running for far longer than the stale
    // threshold (DEFAULT_TIMEOUT 300s + RUNNING_BUFFER 60s = 360s), with
    // is_throttled=true carried over from a prior throttle cycle.
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
            'state' => Running::class,
            'started_at' => $staleTimestamp,
            'is_throttled' => true,
            'retries' => 0,
        ]);
    });

    // Act
    Artisan::call('steps:recover-stale');

    // Assert
    $fresh = Step::find($step->id);

    expect($fresh->state)->toBeInstanceOf(Pending::class, 'stale Running step should recover to Pending');

    // The core contract: retries MUST advance on recovery, even when
    // is_throttled was true at the time of recovery. Without the fix,
    // retries stays at 0 because RunningToPending short-circuits the
    // increment when is_throttled is still true — infinite-recovery loop.
    expect((int) $fresh->retries)->toBe(
        1,
        'retry budget must tick on worker-death recovery regardless of is_throttled'
    );

    // Sanity: the flag should be cleared after recovery so the next cycle's
    // retry accounting isn't poisoned either.
    expect((bool) $fresh->is_throttled)->toBeFalse('is_throttled must be cleared when recovering');
});
