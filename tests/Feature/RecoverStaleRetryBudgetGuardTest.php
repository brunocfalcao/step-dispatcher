<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\Tests\Fixtures\ZeroRetryTestJob;

/*
|--------------------------------------------------------------------------
| Recover-Stale Retry Budget Guard
|--------------------------------------------------------------------------
|
| resolveJobMaxRetries mirrors resolveJobTimeout's guard: a non-positive
| reflected `$retries` (0 or negative) falls back to the default budget.
| Without the guard, a job declaring `$retries = 0` makes
| `step.retries (0) >= maxRetries (0)` true on the very first stale
| detection — the step is failed without a single recovery attempt, even
| though the worker simply died mid-compute.
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

function makeStaleRunningStep(int $retries): Step
{
    $step = Step::create([
        'class' => ZeroRetryTestJob::class,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'index' => 1,
        'block_uuid' => (string) Str::uuid(),
    ]);

    Step::withoutEvents(function () use ($step, $retries): void {
        Step::where('id', $step->id)->update([
            'state' => Running::class,
            'started_at' => now()->subMinutes(10),
            'retries' => $retries,
        ]);
    });

    return $step;
}

it('grants a first recovery to a stale step of a zero-retry job', function (): void {
    $step = makeStaleRunningStep(retries: 0);

    Artisan::call('steps:recover-stale');

    $fresh = Step::find($step->id);
    expect($fresh->state)->toBeInstanceOf(Pending::class);
});

it('still fails a stale step once the fallback budget is exhausted', function (): void {
    $step = makeStaleRunningStep(retries: 5);

    Artisan::call('steps:recover-stale');

    $fresh = Step::find($step->id);
    expect($fresh->state)->toBeInstanceOf(Failed::class)
        ->and($fresh->error_message)->toContain('retries exhausted');
});

it('recovers a clock-skewed step (future started_at) once real elapsed time passes the threshold', function (): void {
    // A worker host with a clock ahead of the watchdog host stamps
    // started_at in the future. Carbon 3's signed diffInSeconds then
    // yields elapsed-minus-skew — recovery is delayed by the skew amount
    // but must still happen, never wedge forever.
    $step = Step::create([
        'class' => ZeroRetryTestJob::class,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'index' => 1,
        'block_uuid' => (string) Str::uuid(),
    ]);

    Step::withoutEvents(function () use ($step): void {
        Step::where('id', $step->id)->update([
            'state' => Running::class,
            'started_at' => now()->addSeconds(30), // 30s clock skew
            'retries' => 0,
        ]);
    });

    // Not yet past threshold+skew: must be left alone.
    $this->travel(2)->minutes();
    Artisan::call('steps:recover-stale');
    expect(Step::find($step->id)->state)->toBeInstanceOf(Running::class);

    // Real elapsed time now exceeds threshold (360s) + skew (30s).
    $this->travel(6)->minutes();
    Artisan::call('steps:recover-stale');
    expect(Step::find($step->id)->state)->toBeInstanceOf(Pending::class);
});
