<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Pending;
use StepDispatcher\Tests\Fixtures\DoubleCheckTestJob;

/*
|--------------------------------------------------------------------------
| Double-Check Verification Budget
|--------------------------------------------------------------------------
|
| doubleCheck() is the post-compute confirmation gate for exchange-facing
| work. Its contract:
|
|   - passes first try   → step completes, double_check pinned to 99
|   - fails              → double_check increments, step retries
|   - budget exhausted   → the step FAILS (fail-closed) rather than silently
|                          completing against unverified state
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
    DoubleCheckTestJob::reset();
});

afterEach(function (): void {
    DoubleCheckTestJob::reset();
});

function doubleCheckStep(int $doubleCheck = 0): Step
{
    $step = Step::create([
        'class' => DoubleCheckTestJob::class,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'index' => 1,
        'block_uuid' => (string) Str::uuid(),
        'state' => Pending::class,
    ]);

    if ($doubleCheck !== 0) {
        DB::table(Step::tableName())->where('id', $step->id)->update(['double_check' => $doubleCheck]);
    }

    return $step->fresh();
}

function runDoubleCheck(Step $step): Step
{
    $job = new DoubleCheckTestJob;
    $job->step = $step;
    $job->handle();

    return $step->fresh();
}

it('completes and pins double_check to 99 when verification passes', function (): void {
    DoubleCheckTestJob::$pass = true;

    $fresh = runDoubleCheck(doubleCheckStep());

    expect($fresh->state)->toBeInstanceOf(Completed::class)
        ->and((int) $fresh->double_check)->toBe(99)
        ->and(DoubleCheckTestJob::$computeRuns)->toBe(1);
});

it('increments double_check and retries when verification fails', function (): void {
    DoubleCheckTestJob::$pass = false;

    $fresh = runDoubleCheck(doubleCheckStep());

    expect($fresh->state)->toBeInstanceOf(Pending::class)
        ->and((int) $fresh->double_check)->toBe(1);
});

it('fails the step once the double-check budget is exhausted', function (): void {
    DoubleCheckTestJob::$pass = false;

    // double_check already at 2 — the budget-exhaustion branch fires before
    // compute(), failing the step rather than completing it unverified.
    $fresh = runDoubleCheck(doubleCheckStep(2));

    expect($fresh->state)->toBeInstanceOf(Failed::class)
        ->and($fresh->error_message)->toContain('verification budget exhausted')
        ->and(DoubleCheckTestJob::$computeRuns)->toBe(0);
});
