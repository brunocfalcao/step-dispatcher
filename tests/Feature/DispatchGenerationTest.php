<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Pending;
use StepDispatcher\Tests\Fixtures\ObsoleteAttemptTestJob;
use StepDispatcher\Transitions\PendingToDispatched;

/*
|--------------------------------------------------------------------------
| Dispatch generation
|--------------------------------------------------------------------------
|
| A queued payload carries the dispatch it belongs to. A payload from an older
| dispatch must not start, and must not fail the step, even when that older
| dispatch happened with no current tick (the tick outlived its cached marker,
| or the cache was cleared while it ran).
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
    Cache::flush();
    ObsoleteAttemptTestJob::reset();
});

function generationStep(string $state, ?int $tickId): Step
{
    $step = Step::create([
        'class' => ObsoleteAttemptTestJob::class,
        'block_uuid' => (string) Str::uuid(),
        'index' => 1,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'generation-test',
        'state' => Pending::class,
    ]);

    DB::table(Step::tableName())->where('id', $step->id)->update(['state' => $state, 'tick_id' => $tickId]);

    return $step->fresh();
}

function queuedPayload(Step $step, ?int $tickId): ObsoleteAttemptTestJob
{
    $job = new ObsoleteAttemptTestJob;
    $job->step = $step;
    $job->stepAttemptSnapshotAvailable = true;
    $job->stepAttemptRetries = 0;
    $job->stepAttemptTickId = $tickId;
    $job->stepAttemptStartedAt = null;

    return $job;
}

it('does not start a payload with no tick once the step was dispatched again under a tick', function (): void {
    $step = generationStep(Dispatched::class, 42);

    queuedPayload($step, null)->handle();

    expect(ObsoleteAttemptTestJob::$hooks)->toBe([])
        ->and($step->fresh()->state)->toBeInstanceOf(Dispatched::class);
});

it('does not fail the step from a payload with no tick once the step was dispatched again under a tick', function (): void {
    $step = generationStep(Dispatched::class, 42);

    queuedPayload($step, null)->failed(new RuntimeException('old payload timed out'));

    $fresh = $step->fresh();

    expect($fresh->state)->toBeInstanceOf(Dispatched::class)
        ->and($fresh->error_message)->toBeNull();
});

it('still runs a payload with no tick while the step has none either', function (): void {
    $step = generationStep(Dispatched::class, null);

    queuedPayload($step, null)->handle();

    expect(ObsoleteAttemptTestJob::$hooks)->toContain('compute')
        ->and($step->fresh()->state)->toBeInstanceOf(Completed::class);
});

it('stamps the current tick when a dispatch has one', function (): void {
    $step = generationStep(Pending::class, 7);
    Cache::put('current_tick_id:generation-test', 9, 60);

    (new PendingToDispatched($step))->apply();

    expect((int) $step->fresh()->tick_id)->toBe(9);
});

it('leaves no earlier tick on the step when a dispatch has no current tick', function (): void {
    $step = generationStep(Pending::class, 7);

    (new PendingToDispatched($step))->apply();

    $fresh = $step->fresh();

    expect($fresh->state)->toBeInstanceOf(Dispatched::class)
        ->and($fresh->tick_id)->toBeNull();

    queuedPayload($fresh, 7)->handle();

    expect(ObsoleteAttemptTestJob::$hooks)->toBe([])
        ->and($step->fresh()->state)->toBeInstanceOf(Dispatched::class);
});
