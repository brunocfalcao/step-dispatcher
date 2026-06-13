<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\States\Skipped;
use StepDispatcher\States\Stopped;
use StepDispatcher\Tests\Fixtures\GuardedTestJob;

/*
|--------------------------------------------------------------------------
| BaseStepJob Lifecycle Guards
|--------------------------------------------------------------------------
|
| handle() runs a fixed guard chain before compute():
|
|   prepareJobExecution → terminal-state bail, duplicate-Running bail
|   shouldExitEarly      → startOrStop, startOrFail, startOrSkip, startOrRetry
|
| Each guard owns a distinct outcome (Stopped / Failed / Skipped / Pending
| / proceed) and must short-circuit BEFORE the work runs. These cases pin
| every exit and assert compute() did not fire when a guard intervened.
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
    GuardedTestJob::reset();
});

afterEach(function (): void {
    GuardedTestJob::reset();
});

function guardedStep(?string $forcedState = null): Step
{
    $step = Step::create([
        'class' => GuardedTestJob::class,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'index' => 1,
        'block_uuid' => (string) Str::uuid(),
        'state' => Pending::class,
    ]);

    if ($forcedState !== null) {
        DB::table(Step::tableName())->where('id', $step->id)->update(['state' => $forcedState]);
    }

    return $step->fresh();
}

function runGuarded(Step $step): Step
{
    $job = new GuardedTestJob;
    $job->step = $step;
    $job->handle();

    return $step->fresh();
}

it('runs compute and completes when every guard passes', function (): void {
    $fresh = runGuarded(guardedStep());

    expect($fresh->state)->toBeInstanceOf(Completed::class)
        ->and(GuardedTestJob::$computeRuns)->toBe(1);
});

it('stops the step when startOrStop returns false, before compute', function (): void {
    GuardedTestJob::$stop = true;

    $fresh = runGuarded(guardedStep());

    expect($fresh->state)->toBeInstanceOf(Stopped::class)
        ->and(GuardedTestJob::$computeRuns)->toBe(0);
});

it('skips the step when startOrSkip returns false, before compute', function (): void {
    GuardedTestJob::$skip = true;

    $fresh = runGuarded(guardedStep());

    expect($fresh->state)->toBeInstanceOf(Skipped::class)
        ->and(GuardedTestJob::$computeRuns)->toBe(0);
});

it('reschedules to Pending when startOrRetry returns false, before compute', function (): void {
    GuardedTestJob::$retry = true;

    $fresh = runGuarded(guardedStep());

    expect($fresh->state)->toBeInstanceOf(Pending::class)
        ->and((int) $fresh->retries)->toBe(1)
        ->and(GuardedTestJob::$computeRuns)->toBe(0);
});

it('fails the step when startOrFail returns false', function (): void {
    GuardedTestJob::$fail = true;

    $fresh = runGuarded(guardedStep());

    expect($fresh->state)->toBeInstanceOf(Failed::class)
        ->and(GuardedTestJob::$computeRuns)->toBe(0);
});

it('bails out silently when the step is already in a terminal state', function (): void {
    $fresh = runGuarded(guardedStep(Completed::class));

    expect($fresh->state)->toBeInstanceOf(Completed::class)
        ->and(GuardedTestJob::$computeRuns)->toBe(0);
});

it('bails out without double-executing when the step is already Running', function (): void {
    $fresh = runGuarded(guardedStep(Running::class));

    expect($fresh->state)->toBeInstanceOf(Running::class)
        ->and(GuardedTestJob::$computeRuns)->toBe(0);
});
