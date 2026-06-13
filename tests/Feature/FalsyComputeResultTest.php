<?php

declare(strict_types=1);

use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Pending;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Tests\Fixtures\FalsyResultTestJob;

/*
|--------------------------------------------------------------------------
| Falsy Compute Result Storage
|--------------------------------------------------------------------------
|
| step.response is an inter-step data bus: downstream steps and consumer
| services read it (Olloma pipelines, Quanamo embeddings, Kraite direction
| notifications). A compute() returning a legitimate falsy value — 0 rows
| affected, an empty result set, false — must be persisted, not silently
| discarded. Only `null` means "nothing to store".
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

afterEach(function (): void {
    FalsyResultTestJob::$result = null;
});

function runFalsyResultJob(mixed $result): Step
{
    FalsyResultTestJob::$result = $result;

    $step = Step::create([
        'class' => FalsyResultTestJob::class,
        'block_uuid' => 'falsy-result-'.uniqid(),
        'index' => 1,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'state' => Pending::class,
    ]);

    // Sync queue driver: the job executes inline during the tick.
    StepDispatcher::dispatch('test-group');

    return $step->fresh();
}

it('persists an integer zero result', function (): void {
    $step = runFalsyResultJob(0);

    expect($step->state)->toBeInstanceOf(Completed::class)
        ->and($step->getRawOriginal('response'))->not->toBeNull()
        ->and($step->response)->toBe(0);
});

it('persists an empty array result', function (): void {
    $step = runFalsyResultJob([]);

    expect($step->getRawOriginal('response'))->not->toBeNull()
        ->and($step->response)->toBe([]);
});

it('persists a false result', function (): void {
    $step = runFalsyResultJob(false);

    expect($step->getRawOriginal('response'))->not->toBeNull()
        ->and($step->response)->toBe(false);
});

it('leaves response null when compute returns null', function (): void {
    $step = runFalsyResultJob(null);

    expect($step->getRawOriginal('response'))->toBeNull();
});
