<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use StepDispatcher\Exceptions\MaxRetriesReachedException;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Skipped;
use StepDispatcher\Tests\Fixtures\ThrowingTestJob;

/*
|--------------------------------------------------------------------------
| HandlesStepExceptions Decision Tree
|--------------------------------------------------------------------------
|
| handleException() is the single funnel every thrown exception passes
| through. Its branch order is a contract:
|
|   1. MaxRetriesReached shortcut → resolve-or-fail
|   2. permanent DB error        → fail immediately (no retry)
|   3. retryable                 → retry with backoff
|   4. ignorable                 → complete (swallow)
|   5. otherwise                 → resolve hook, else fail
|
| Each branch is exercised here against a real step running through the
| full handle() lifecycle, so a reordering or a dropped branch fails loud.
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
    ThrowingTestJob::reset();
});

afterEach(function (): void {
    ThrowingTestJob::reset();
});

function throwingStep(): Step
{
    return Step::create([
        'class' => ThrowingTestJob::class,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'index' => 1,
        'block_uuid' => (string) Str::uuid(),
        'state' => Pending::class,
    ]);
}

function runThrowingJob(Step $step): Step
{
    $job = new ThrowingTestJob;
    $job->step = $step;
    $job->handle();

    return $step->fresh();
}

it('fails the step and records the error when no hook handles the exception', function (): void {
    ThrowingTestJob::$throw = new RuntimeException('boom');

    $fresh = runThrowingJob(throwingStep());

    expect($fresh->state)->toBeInstanceOf(Failed::class)
        ->and($fresh->error_message)->not->toBeNull()
        ->and($fresh->error_stack_trace)->not->toBeNull();
});

it('retries the step when retryException returns true', function (): void {
    ThrowingTestJob::$throw = new RuntimeException('transient');
    ThrowingTestJob::$retry = true;

    $fresh = runThrowingJob(throwingStep());

    expect($fresh->state)->toBeInstanceOf(Pending::class)
        ->and((int) $fresh->retries)->toBe(1)
        ->and($fresh->dispatch_after)->not->toBeNull();
});

it('completes the step when ignoreException returns true', function (): void {
    ThrowingTestJob::$throw = new RuntimeException('harmless');
    ThrowingTestJob::$ignore = true;

    $fresh = runThrowingJob(throwingStep());

    expect($fresh->state)->toBeInstanceOf(Completed::class);
});

it('lets a resolve hook divert the step away from Failed', function (): void {
    ThrowingTestJob::$throw = new RuntimeException('resolved elsewhere');
    ThrowingTestJob::$resolveTo = Skipped::class;

    $fresh = runThrowingJob(throwingStep());

    expect($fresh->state)->toBeInstanceOf(Skipped::class);
});

it('routes a MaxRetriesReachedException through the shortcut to Failed', function (): void {
    ThrowingTestJob::$throw = new MaxRetriesReachedException('Max retries reached');

    $fresh = runThrowingJob(throwingStep());

    expect($fresh->state)->toBeInstanceOf(Failed::class)
        ->and($fresh->error_message)->toContain('Max retries');
});

it('fails immediately on a permanent database error without retrying', function (): void {
    // 'no such table' is in the sqlite handler's permanentMessages — even
    // though retryException is toggled on, the permanent branch wins first.
    ThrowingTestJob::$throw = new QueryException(
        'testing',
        'select * from missing',
        [],
        new RuntimeException('no such table: missing')
    );
    ThrowingTestJob::$retry = true;

    $fresh = runThrowingJob(throwingStep());

    expect($fresh->state)->toBeInstanceOf(Failed::class);
});

it('retries on a transient database error classified by the driver handler', function (): void {
    // 'database is locked' is in the sqlite handler's retryableMessages.
    ThrowingTestJob::$throw = new QueryException(
        'testing',
        'update steps set x = 1',
        [],
        new RuntimeException('database is locked')
    );

    $fresh = runThrowingJob(throwingStep());

    expect($fresh->state)->toBeInstanceOf(Pending::class)
        ->and((int) $fresh->retries)->toBe(1);
});
