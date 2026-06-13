<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Pending;
use StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob;
use StepDispatcher\Tests\Fixtures\ZeroRetryTestJob;

/*
|--------------------------------------------------------------------------
| BaseStepJob Failure-Path Edges
|--------------------------------------------------------------------------
|
| failed() is Laravel's last-resort hook — Horizon can invoke it for a job
| that never ran handle() (timeout while queued) or whose step was moved
| by someone else (cancelled, recovered) between dispatch and death.
|
|   a. Duration: startMicrotime is still 0.0 when handle() never ran;
|      computing (now - 0.0) writes a ~55-year duration to the step.
|   b. State: transitioning Cancelled/Stopped → Failed is unregistered
|      (throws), and Pending → Failed would clobber a concurrent
|      recover-stale requeue. Only Running/Dispatched may fail.
|   c. Priority escalation: shouldChangeToHighPriority with $retries = 0
|      yields threshold 0.0, escalating to 'high' before any retry.
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

function makeFailureStep(string $state, string $class = PrefixCarryingTestJob::class): Step
{
    $step = Step::create([
        'class' => $class,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'index' => 1,
        'block_uuid' => (string) Str::uuid(),
    ]);

    DB::table(Step::tableName())->where('id', $step->id)->update(['state' => $state]);

    return $step->fresh();
}

it('does not write a garbage duration when failed() fires before handle()', function (): void {
    $step = makeFailureStep(Dispatched::class);

    $job = new PrefixCarryingTestJob;
    $job->step = $step;

    // startMicrotime never set — handle() never ran (Horizon timeout while queued).
    $job->failed(new RuntimeException('killed while queued'));

    $fresh = $step->fresh();
    expect($fresh->state)->toBeInstanceOf(Failed::class)
        // duration keeps its column default — anything in the
        // trillions would be the (now - 0.0) epoch artifact.
        ->and((int) $fresh->duration)->toBeLessThan(1000);
});

it('does not throw or transition when failed() fires on a cancelled step', function (): void {
    $step = makeFailureStep(Cancelled::class);

    $job = new PrefixCarryingTestJob;
    $job->step = $step;

    $job->failed(new RuntimeException('killed after external cancel'));

    expect($step->fresh()->state)->toBeInstanceOf(Cancelled::class);
});

it('does not clobber a recovered Pending step when failed() fires late', function (): void {
    $step = makeFailureStep(Pending::class);

    $job = new PrefixCarryingTestJob;
    $job->step = $step;

    $job->failed(new RuntimeException('zombie failure callback'));

    expect($step->fresh()->state)->toBeInstanceOf(Pending::class);
});

it('does not escalate a zero-retry job to high priority before any retry', function (): void {
    $step = makeFailureStep(Pending::class, ZeroRetryTestJob::class);

    $job = new ZeroRetryTestJob;
    $job->step = $step;

    $method = new ReflectionMethod($job, 'shouldChangeToHighPriority');

    expect($method->invoke($job))->toBeFalse();
});
