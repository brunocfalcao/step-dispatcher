<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Pending;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Tests\Fixtures\ConstructorArgTestJob;

/*
|--------------------------------------------------------------------------
| Job Argument Resolution (DispatchesJobs)
|--------------------------------------------------------------------------
|
| dispatchSingleStep() reflects the job's constructor and maps step.arguments
| by parameter NAME, applying defaults where present. A missing required
| argument and a missing class are both dispatch-time failures that must land
| the step in Failed with a diagnostic message — never a silent no-op.
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
    StepDispatcher::setQueueResolver(null);
});

function dispatchedStep(array $attributes): Step
{
    $step = Step::create(array_merge([
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'index' => 1,
        'block_uuid' => (string) Str::uuid(),
        'state' => Pending::class,
    ], $attributes));

    // The real dispatcher claims the row (Pending → Dispatched) before
    // dispatchSingleStep runs; mirror that so the failure catch — which only
    // acts on a still-Dispatched row — behaves as in production.
    DB::table(Step::tableName())->where('id', $step->id)->update(['state' => Dispatched::class]);

    return $step->fresh();
}

it('maps a named constructor argument through to compute', function (): void {
    $step = dispatchedStep([
        'class' => ConstructorArgTestJob::class,
        'arguments' => ['value' => 42],
    ]);

    (new StepDispatcher)->dispatchSingleStep($step);

    $fresh = $step->fresh();
    expect($fresh->state)->toBeInstanceOf(Completed::class)
        ->and($fresh->response)->toBe(['value' => 42]);
});

it('fails the step with a diagnostic when a required argument is missing', function (): void {
    $step = dispatchedStep([
        'class' => ConstructorArgTestJob::class,
        'arguments' => [],
    ]);

    (new StepDispatcher)->dispatchSingleStep($step);

    $fresh = $step->fresh();
    expect($fresh->state)->toBeInstanceOf(Failed::class)
        ->and($fresh->error_message)->toContain('Missing required arguments');
});

it('fails the step when no class is defined', function (): void {
    $step = dispatchedStep([
        'class' => null,
    ]);

    (new StepDispatcher)->dispatchSingleStep($step);

    expect($step->fresh()->state)->toBeInstanceOf(Failed::class);
});
