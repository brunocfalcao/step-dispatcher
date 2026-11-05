<?php

declare(strict_types=1);

use StepDispatcher\Models\Step;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Skipped;
use StepDispatcher\States\Stopped;
use StepDispatcher\Tests\Fixtures\ScenarioTestJob;


// Reproduces the exact production bug: a step is dispatched to the queue,
// then cancelled by cascade cancellation before the worker picks it up.
// The worker calls handle() on a Cancelled step, which tries Cancelled → Running
// and then Cancelled → Failed, both unregistered transitions, causing an
// infinite retry loop that wrote 64GB of logs.
it('silently skips execution when step is already Cancelled', function (): void {
    $step = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'queue' => 'sync',
    ]);

    // Simulate: step was dispatched, then cancelled before worker picks it up
    $step->state->transitionTo(Cancelled::class);
    $step->refresh();
    expect($step->state)->toBeInstanceOf(Cancelled::class);

    // Simulate Horizon worker calling handle() on the job
    $job = new ScenarioTestJob;
    $job->step = $step;

    // This should NOT throw — it should bail out silently
    $job->handle();

    // Step should still be Cancelled (not Failed, not Running)
    $step->refresh();
    expect($step->state)->toBeInstanceOf(Cancelled::class);
});

it('silently skips execution when step is already Failed', function (): void {
    $step = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'queue' => 'sync',
    ]);

    // Transition to Failed via valid path: Pending → Running → Failed
    $step->state->transitionTo(StepDispatcher\States\Running::class);
    $step->state->transitionTo(Failed::class);
    $step->refresh();
    expect($step->state)->toBeInstanceOf(Failed::class);

    $job = new ScenarioTestJob;
    $job->step = $step;

    $job->handle();

    $step->refresh();
    expect($step->state)->toBeInstanceOf(Failed::class);
});

it('silently skips execution when step is already Completed', function (): void {
    $step = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'queue' => 'sync',
    ]);

    // Transition to Completed via valid path: Pending → Running → Completed
    $step->state->transitionTo(StepDispatcher\States\Running::class);
    $step->state->transitionTo(Completed::class);
    $step->refresh();
    expect($step->state)->toBeInstanceOf(Completed::class);

    $job = new ScenarioTestJob;
    $job->step = $step;

    $job->handle();

    $step->refresh();
    expect($step->state)->toBeInstanceOf(Completed::class);
});

it('silently skips execution when step is already Stopped', function (): void {
    $step = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'queue' => 'sync',
    ]);

    // Transition to Stopped via valid path: Pending → Running → Stopped
    $step->state->transitionTo(StepDispatcher\States\Running::class);
    $step->state->transitionTo(Stopped::class);
    $step->refresh();
    expect($step->state)->toBeInstanceOf(Stopped::class);

    $job = new ScenarioTestJob;
    $job->step = $step;

    $job->handle();

    $step->refresh();
    expect($step->state)->toBeInstanceOf(Stopped::class);
});

it('silently skips execution when step is already Skipped', function (): void {
    $step = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'queue' => 'sync',
    ]);

    // Transition to Skipped via valid path: Pending → Running → Skipped
    $step->state->transitionTo(StepDispatcher\States\Running::class);
    $step->state->transitionTo(Skipped::class);
    $step->refresh();
    expect($step->state)->toBeInstanceOf(Skipped::class);

    $job = new ScenarioTestJob;
    $job->step = $step;

    $job->handle();

    $step->refresh();
    expect($step->state)->toBeInstanceOf(Skipped::class);
});
