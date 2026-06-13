<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\Tests\Fixtures\GuardedTestJob;

/*
|--------------------------------------------------------------------------
| Lifecycle Reschedule Helpers
|--------------------------------------------------------------------------
|
| The retry/throttle helpers differ in ONE crucial way — whether they burn
| a retry. retryJob() is a real attempt and must advance the counter;
| rescheduleWithoutRetry() is a throttle bounce (rate-limited, not failed)
| and must NOT. The distinction is carried by the is_throttled flag into
| RunningToPending, and getting it wrong either loops a step forever or
| exhausts its budget on backpressure. Also pinned: the millisecond backoff
| override, the confirming-completion reschedule, and the max-retries cap.
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
    GuardedTestJob::reset();
});

afterEach(function (): void {
    GuardedTestJob::reset();
});

function runningStep(int $retries = 0): Step
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

    DB::table(Step::tableName())->where('id', $step->id)->update([
        'state' => Running::class,
        'retries' => $retries,
    ]);

    return $step->fresh();
}

function jobFor(Step $step): GuardedTestJob
{
    $job = new GuardedTestJob;
    $job->step = $step;

    return $job;
}

it('retryJob advances the retry counter and schedules a future dispatch', function (): void {
    $step = runningStep();

    jobFor($step)->retryJob();

    $fresh = $step->fresh();
    expect($fresh->state)->toBeInstanceOf(Pending::class)
        ->and((int) $fresh->retries)->toBe(1)
        ->and($fresh->dispatch_after)->not->toBeNull()
        ->and((bool) $fresh->is_throttled)->toBeFalse();
});

it('rescheduleWithoutRetry throttles without burning a retry', function (): void {
    $step = runningStep();

    jobFor($step)->rescheduleWithoutRetry();

    $fresh = $step->fresh();
    expect($fresh->state)->toBeInstanceOf(Pending::class)
        ->and((int) $fresh->retries)->toBe(0)
        ->and((bool) $fresh->is_throttled)->toBeTrue()
        ->and((bool) $fresh->was_throttled)->toBeTrue()
        ->and($fresh->dispatch_after)->not->toBeNull();
});

it('honours the millisecond backoff override instead of the seconds default', function (): void {
    $step = runningStep();

    $job = jobFor($step);
    $job->jobBackoffSeconds = 10;
    $job->jobBackoffMs = 500; // sub-second — must win over the 10s default

    $job->retryJob();

    $dispatchAfter = $step->fresh()->dispatch_after;

    // The 10s path would land ~10s out; the ms path lands well under 2s.
    expect($dispatchAfter->lt(now()->addSeconds(2)))->toBeTrue()
        ->and($dispatchAfter->gt(now()->subSecond()))->toBeTrue();
});

it('retryForConfirmation flips the step into confirming-completion mode', function (): void {
    $step = runningStep();

    jobFor($step)->retryForConfirmation();

    $fresh = $step->fresh();
    expect($fresh->state)->toBeInstanceOf(Pending::class)
        ->and($fresh->execution_mode)->toBe('confirming-completion');
});

it('throws and fails the step once the retry budget is exhausted', function (): void {
    // job.retries = 5; force step.retries to the cap so the post-guard
    // checkMaxRetries() throws MaxRetriesReachedException → shortcut → Failed.
    $step = Step::create([
        'class' => GuardedTestJob::class,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'index' => 1,
        'block_uuid' => (string) Str::uuid(),
        'state' => Pending::class,
    ]);
    DB::table(Step::tableName())->where('id', $step->id)->update(['retries' => 5]);
    $step = $step->fresh();

    $job = new GuardedTestJob;
    $job->step = $step;
    $job->handle();

    $fresh = $step->fresh();
    expect($fresh->state)->toBeInstanceOf(Failed::class)
        ->and($fresh->error_message)->toContain('Max retries')
        ->and(GuardedTestJob::$computeRuns)->toBe(0);
});
