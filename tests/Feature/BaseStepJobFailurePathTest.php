<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob;
use StepDispatcher\Tests\Fixtures\ResolvingOnFailureTestJob;
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

it('preserves terminal and recovered payloads when failed() fires late', function (string $state): void {
    $step = makeFailureStep($state);
    DB::table(Step::tableName())->where('id', $step->id)->update([
        'response' => json_encode(['winner' => 'completed']),
        'error_message' => 'winner payload',
        'error_stack_trace' => 'winner trace',
        'duration' => 42,
    ]);

    $job = new PrefixCarryingTestJob;
    $job->step = $step->fresh();
    $job->failed(new RuntimeException('obsolete queue callback'));

    $fresh = $step->fresh();
    expect($fresh->state)->toBeInstanceOf($state)
        ->and($fresh->response)->toBe(['winner' => 'completed'])
        ->and($fresh->error_message)->toBe('winner payload')
        ->and($fresh->error_stack_trace)->toBe('winner trace')
        ->and((int) $fresh->duration)->toBe(42);
})->with([Cancelled::class, Pending::class, Completed::class]);

it('runs the job resolveException hook when failed() fires on a killed step', function (): void {
    ResolvingOnFailureTestJob::reset();
    $step = makeFailureStep(Running::class, ResolvingOnFailureTestJob::class);

    $job = new ResolvingOnFailureTestJob;
    $job->step = $step;

    $job->failed(new RuntimeException('killed by queue timeout'));

    expect($step->fresh()->state)->toBeInstanceOf(Failed::class)
        ->and(ResolvingOnFailureTestJob::$resolved)->toBeTrue()
        // The hook must see the recorded failure, not a null column —
        // domain records quote it back to the user as the reason.
        ->and(ResolvingOnFailureTestJob::$resolvedErrorMessage)->not->toBeNull();
});

it('does not run the resolveException hook when failed() leaves the step alone', function (): void {
    ResolvingOnFailureTestJob::reset();
    $step = makeFailureStep(Cancelled::class, ResolvingOnFailureTestJob::class);

    $job = new ResolvingOnFailureTestJob;
    $job->step = $step;

    $job->failed(new RuntimeException('zombie failure callback after cancel'));

    expect($step->fresh()->state)->toBeInstanceOf(Cancelled::class)
        ->and(ResolvingOnFailureTestJob::$resolved)->toBeFalse();
});

it('does not let an older running callback fail a newer retry attempt', function (): void {
    $step = makeFailureStep(Running::class);
    DB::table(Step::tableName())->where('id', $step->id)->update([
        'retries' => 1,
        'started_at' => now(),
        'response' => json_encode(['winner' => 'new-attempt']),
        'error_message' => 'new attempt owns the row',
        'error_stack_trace' => 'new attempt trace',
        'duration' => 37,
    ]);

    $job = new PrefixCarryingTestJob;
    $job->step = $step->fresh();
    $job->stepAttemptSnapshotAvailable = true;
    $job->stepAttemptRetries = 0;
    $job->stepAttemptTickId = null;
    $job->stepAttemptStartedAt = null;

    $job->failed(new RuntimeException('obsolete first-attempt callback'));

    $fresh = $step->fresh();
    expect($fresh->state)->toBeInstanceOf(Running::class)
        ->and((int) $fresh->retries)->toBe(1)
        ->and($fresh->response)->toBe(['winner' => 'new-attempt'])
        ->and($fresh->error_message)->toBe('new attempt owns the row')
        ->and($fresh->error_stack_trace)->toBe('new attempt trace')
        ->and((int) $fresh->duration)->toBe(37);
});

it('runs the failure hook for a current same-generation callback after claim', function (): void {
    $frozen = Carbon::create(2030, 1, 2, 3, 4, 5, 'UTC')->setMicrosecond(123456);
    Carbon::setTestNow($frozen);

    try {
        ResolvingOnFailureTestJob::reset();
        $step = makeFailureStep(Dispatched::class, ResolvingOnFailureTestJob::class);

        $job = new ResolvingOnFailureTestJob;
        $job->step = $step;

        // This is the real worker claim path. handle() records the same
        // snapshot immediately after prepareJobExecution() returns.
        $prepare = new ReflectionMethod($job, 'prepareJobExecution');
        expect($prepare->invoke($job))->toBeTrue();
        $persisted = $step->fresh();

        expect($frozen->microsecond)->toBe(123456)
            ->and($job->step->state)->toBeInstanceOf(Running::class)
            ->and($job->step->started_at?->format('Y-m-d H:i:s.u'))
            ->toBe($persisted->started_at?->format('Y-m-d H:i:s.u'));

        $job->stepAttemptSnapshotAvailable = true;
        $job->stepAttemptRetries = (int) $job->step->retries;
        $job->stepAttemptTickId = $job->step->tick_id === null ? null : (int) $job->step->tick_id;
        $job->stepAttemptStartedAt = $job->step->started_at?->format('Y-m-d H:i:s.u');

        $job->failed(new RuntimeException('current attempt failed'));

        expect($step->fresh()->state)->toBeInstanceOf(Failed::class)
            ->and(ResolvingOnFailureTestJob::$resolved)->toBeTrue()
            ->and(ResolvingOnFailureTestJob::$resolvedErrorMessage)->toContain('current attempt failed');
    } finally {
        Carbon::setTestNow();
    }
});

it('does not escalate a zero-retry job to high priority before any retry', function (): void {
    $step = makeFailureStep(Pending::class, ZeroRetryTestJob::class);

    $job = new ZeroRetryTestJob;
    $job->step = $step;

    $method = new ReflectionMethod($job, 'shouldChangeToHighPriority');

    expect($method->invoke($job))->toBeFalse();
});

it('records the real error over a stale-detector retry note', function (): void {
    $step = makeFailureStep(Running::class);
    DB::table(Step::tableName())->where('id', $step->id)->update([
        'error_message' => 'Recovered by stale detector: Dispatched payload stalled, retrying (0/3)',
    ]);

    $job = new PrefixCarryingTestJob;
    $job->step = $step->fresh();

    $job->reportAndFail(new RuntimeException('value too long for type character varying(255)'));

    $fresh = $step->fresh();
    expect($fresh->state)->toBeInstanceOf(Failed::class)
        ->and($fresh->error_message)->toContain('value too long')
        ->and($fresh->error_stack_trace)->not->toBeEmpty();
});

it('keeps a message the job wrote itself before failing', function (): void {
    $step = makeFailureStep(Running::class);
    DB::table(Step::tableName())->where('id', $step->id)->update(['error_message' => 'Order 42 was rejected by the exchange']);

    $job = new PrefixCarryingTestJob;
    $job->step = $step->fresh();

    $job->reportAndFail(new RuntimeException('generic wrapper'));

    expect($step->fresh()->error_message)->toBe('Order 42 was rejected by the exchange');
});

it('does not let a job from an older dispatch generation claim a re-queued step', function (string $state): void {
    $step = makeFailureStep($state);
    // Recovery re-queued the step after the first job waited too long: the
    // row now carries the next retry and a newer dispatch tick.
    DB::table(Step::tableName())->where('id', $step->id)->update(['retries' => 1, 'tick_id' => 8]);

    $job = new PrefixCarryingTestJob;
    $job->step = $step->fresh();
    $job->stepAttemptSnapshotAvailable = true;
    $job->stepAttemptRetries = 0;
    $job->stepAttemptTickId = 7;

    $prepare = new ReflectionMethod($job, 'prepareJobExecution');

    expect($prepare->invoke($job))->toBeFalse();

    $fresh = $step->fresh();
    expect($fresh->state)->toBeInstanceOf($state)
        ->and($fresh->started_at)->toBeNull()
        ->and((int) $fresh->retries)->toBe(1)
        ->and((int) $fresh->tick_id)->toBe(8);
})->with([Dispatched::class, Pending::class]);

it('lets a job from the current dispatch generation claim its step', function (): void {
    $step = makeFailureStep(Dispatched::class);
    DB::table(Step::tableName())->where('id', $step->id)->update(['retries' => 1, 'tick_id' => 8]);

    $job = new PrefixCarryingTestJob;
    $job->step = $step->fresh();
    $job->stepAttemptSnapshotAvailable = true;
    $job->stepAttemptRetries = 1;
    $job->stepAttemptTickId = 8;

    $prepare = new ReflectionMethod($job, 'prepareJobExecution');

    expect($prepare->invoke($job))->toBeTrue()
        ->and($step->fresh()->state)->toBeInstanceOf(Running::class);
});
