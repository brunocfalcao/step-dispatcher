<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Tests\Fixtures\ScenarioTestJob;
use StepDispatcher\Tests\Support\StepTester;

/*
|--------------------------------------------------------------------------
| Rules no other scenario pins down
|--------------------------------------------------------------------------
|
| Each test here exists because breaking that rule on purpose left every other
| scenario green.
|
*/

// ---------------------------------------------------------------------------
// When a step starts running
// ---------------------------------------------------------------------------

it('clears the throttle flag when a throttled step starts running', function (): void {
    $step = Step::factory()->create(['state' => Pending::class, 'is_throttled' => true]);

    $step->state = new Running($step);
    $step->save();

    expect((bool) $step->refresh()->is_throttled)->toBeFalse();
});

it('stamps the host name when a step starts running', function (): void {
    $step = Step::factory()->create(['state' => Pending::class, 'hostname' => null]);

    $step->state = new Running($step);
    $step->save();

    expect($step->refresh()->hostname)->toBe(gethostname());
});

it('keeps an existing host name when a step starts running', function (): void {
    // A pending step never keeps a host name, so this starts from dispatched.
    $step = Step::factory()->create(['state' => Dispatched::class, 'hostname' => 'worker-7']);

    $step->state = new Running($step);
    $step->save();

    expect($step->refresh()->hostname)->toBe('worker-7');
});

// ---------------------------------------------------------------------------
// Which record a step belongs to
// ---------------------------------------------------------------------------

it('copies the owner from a sibling in the same block', function (): void {
    $block = (string) Str::uuid();

    Step::factory()->create([
        'block_uuid' => $block,
        'index' => 1,
        'relatable_type' => 'App\\Models\\KnowledgeBase',
        'relatable_id' => 41,
    ]);

    $second = Step::factory()->create(['block_uuid' => $block, 'index' => 2]);
    $second->refresh();

    expect($second->relatable_type)->toBe('App\\Models\\KnowledgeBase')
        ->and($second->relatable_id)->toBe(41);
});

it('keeps an explicit owner over the owner of a sibling', function (): void {
    $block = (string) Str::uuid();

    Step::factory()->create([
        'block_uuid' => $block,
        'index' => 1,
        'relatable_type' => 'App\\Models\\KnowledgeBase',
        'relatable_id' => 41,
    ]);

    $second = Step::factory()->create([
        'block_uuid' => $block,
        'index' => 2,
        'relatable_type' => 'App\\Models\\Source',
        'relatable_id' => 99,
    ]);
    $second->refresh();

    expect($second->relatable_type)->toBe('App\\Models\\Source')
        ->and($second->relatable_id)->toBe(99);
});

it('does not copy an owner from another block', function (): void {
    Step::factory()->create([
        'block_uuid' => (string) Str::uuid(),
        'relatable_type' => 'App\\Models\\KnowledgeBase',
        'relatable_id' => 41,
    ]);

    $other = Step::factory()->create(['block_uuid' => (string) Str::uuid()]);
    $other->refresh();

    expect($other->relatable_type)->toBeNull()
        ->and($other->relatable_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// What a failure cancels, and what it leaves alone
// ---------------------------------------------------------------------------

it('leaves a same-position parallel step alone when its sibling fails', function (): void {
    $block = (string) Str::uuid();

    [$failing, $scheduled, $later] = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1, 'arguments' => ['fail' => true]],
        ['block_uuid' => $block, 'index' => 1, 'dispatch_after' => now()->addMinutes(5)],
        ['block_uuid' => $block, 'index' => 2],
    ], ScenarioTestJob::class);

    // First tick runs the failing step, second tick cascades from it.
    StepDispatcher::dispatch($failing->group);
    StepDispatcher::dispatch($failing->group);

    expect($failing->refresh()->state->value())->toBe('failed')
        ->and($scheduled->refresh()->state->value())->toBe('pending')
        ->and($later->refresh()->state->value())->toBe('cancelled');

    // Once its time comes, the parallel step still runs.
    $this->travel(6)->minutes();
    StepDispatcher::dispatch($failing->group);

    expect($scheduled->refresh()->state->value())->toBe('completed')
        ->and($later->refresh()->state->value())->toBe('cancelled');
});

it('never cancels a step that is already running when an earlier step failed', function (): void {
    $block = (string) Str::uuid();

    [$failed, $running, $waiting] = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1, 'state' => Failed::class],
        ['block_uuid' => $block, 'index' => 2, 'state' => Running::class],
        ['block_uuid' => $block, 'index' => 3],
    ], ScenarioTestJob::class);

    StepDispatcher::dispatch($failed->group);

    expect($running->refresh()->state->value())->toBe('running')
        ->and($waiting->refresh()->state->value())->toBe('cancelled');
});

it('cancels the later steps when an earlier step was cancelled', function (): void {
    $block = (string) Str::uuid();

    [$cancelled, $next] = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1, 'state' => Cancelled::class],
        ['block_uuid' => $block, 'index' => 2],
    ], ScenarioTestJob::class);

    StepDispatcher::dispatch($cancelled->group);

    expect($next->refresh()->state->value())->toBe('cancelled');
});

// ---------------------------------------------------------------------------
// The retry budget
// ---------------------------------------------------------------------------

it('fails a step that used up its retry budget and records why', function (): void {
    $step = StepTester::createSteps([
        ['retries' => 10],
    ], ScenarioTestJob::class)[0];

    StepDispatcher::dispatch($step->group);

    $step->refresh();

    expect($step->state->value())->toBe('failed')
        ->and($step->error_message)->toContain("Max retries (10) reached for Step ID {$step->id}.");
});

it('runs a step one retry short of its budget', function (): void {
    $step = StepTester::createSteps([
        ['retries' => 9],
    ], ScenarioTestJob::class)[0];

    StepDispatcher::dispatch($step->group);

    expect($step->refresh()->state->value())->toBe('completed');
});

// Records current behaviour: the budget is only checked once the start check
// passes, so a step that keeps answering "not yet" can retry past its budget.
it('keeps retrying past the budget while the start check says not yet', function (): void {
    $step = StepTester::createSteps([
        ['retries' => 10, 'arguments' => ['should_start_or_retry' => false]],
    ], ScenarioTestJob::class)[0];

    StepDispatcher::dispatch($step->group);

    $step->refresh();

    expect($step->state->value())->toBe('pending')
        ->and($step->retries)->toBe(11);
});

it('does not count a throttled retry against the retry budget', function (): void {
    $step = Step::factory()->create(['state' => Running::class, 'retries' => 2, 'is_throttled' => true]);

    $step->state->transitionTo(Pending::class);

    expect($step->refresh()->retries)->toBe(2);
});

it('counts a normal retry against the retry budget', function (): void {
    $step = Step::factory()->create(['state' => Running::class, 'retries' => 2, 'is_throttled' => false]);

    $step->state->transitionTo(Pending::class);

    expect($step->refresh()->retries)->toBe(3);
});

// ---------------------------------------------------------------------------
// What a finished step records
// ---------------------------------------------------------------------------

it('stamps the completion time when a step completes', function (): void {
    $step = StepTester::createSteps([[]], ScenarioTestJob::class)[0];

    StepDispatcher::dispatch($step->group);

    $step->refresh();

    expect($step->state->value())->toBe('completed')
        ->and($step->completed_at->toDateTimeString())->toBe(now()->toDateTimeString());
});

it('stores what a job returns on its step', function (): void {
    $step = StepTester::createSteps([
        ['arguments' => ['custom_result' => ['orders' => 3]]],
    ], ScenarioTestJob::class)[0];

    StepDispatcher::dispatch($step->group);

    expect($step->refresh()->response)->toBe(['orders' => 3]);
});

it('stores a falsy job result instead of dropping it', function (): void {
    $step = StepTester::createSteps([
        ['arguments' => ['custom_result' => 0]],
    ], ScenarioTestJob::class)[0];

    StepDispatcher::dispatch($step->group);

    expect($step->refresh()->response)->toBe(0);
});
