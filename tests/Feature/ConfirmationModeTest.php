<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Pending;
use StepDispatcher\Tests\Fixtures\ConfirmCompletionTestJob;

/*
|--------------------------------------------------------------------------
| Confirming-Completion Execution Mode
|--------------------------------------------------------------------------
|
| A job that needs to verify its own side effect on a SEPARATE worker pass
| uses confirming-completion mode:
|
|   1. First pass runs compute(); if confirmOrRetry() says "not yet", the
|      step flips to execution_mode=confirming-completion and reschedules.
|   2. A later pass sees that mode and routes straight to confirmOrRetry()
|      WITHOUT re-running compute() — completing or retrying on its verdict.
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
    ConfirmCompletionTestJob::reset();
});

afterEach(function (): void {
    ConfirmCompletionTestJob::reset();
});

function confirmStep(?string $executionMode = null): Step
{
    $step = Step::create([
        'class' => ConfirmCompletionTestJob::class,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'index' => 1,
        'block_uuid' => (string) Str::uuid(),
        'state' => Pending::class,
    ]);

    if ($executionMode !== null) {
        DB::table(Step::tableName())->where('id', $step->id)->update(['execution_mode' => $executionMode]);
    }

    return $step->fresh();
}

function runConfirm(Step $step): Step
{
    $job = new ConfirmCompletionTestJob;
    $job->step = $step;
    $job->handle();

    return $step->fresh();
}

it('flips a normal run into confirming-completion mode when verification is not yet satisfied', function (): void {
    ConfirmCompletionTestJob::$confirm = false;

    $fresh = runConfirm(confirmStep());

    expect($fresh->state)->toBeInstanceOf(Pending::class)
        ->and($fresh->execution_mode)->toBe('confirming-completion')
        ->and(ConfirmCompletionTestJob::$computeRuns)->toBe(1);
});

it('completes a confirming-completion step without re-running compute when confirmed', function (): void {
    ConfirmCompletionTestJob::$confirm = true;

    $fresh = runConfirm(confirmStep('confirming-completion'));

    expect($fresh->state)->toBeInstanceOf(Completed::class)
        ->and(ConfirmCompletionTestJob::$computeRuns)->toBe(0);
});

it('keeps a confirming-completion step pending when not yet confirmed, without re-running compute', function (): void {
    ConfirmCompletionTestJob::$confirm = false;

    $fresh = runConfirm(confirmStep('confirming-completion'));

    expect($fresh->state)->toBeInstanceOf(Pending::class)
        ->and($fresh->execution_mode)->toBe('confirming-completion')
        ->and(ConfirmCompletionTestJob::$computeRuns)->toBe(0);
});
