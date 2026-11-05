<?php

declare(strict_types=1);

use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;

test('sets started_at when step transitions from Pending to Running', function (): void {
    // Create a step in Pending state with no started_at
    $step = Step::factory()->create([
        'state' => Pending::class,
        'started_at' => null,
    ]);

    expect($step->started_at)->toBeNull();

    // Transition to Running state
    $step->state = new Running($step);
    $step->save();

    // Refresh from database
    $step->refresh();

    // started_at should now be set
    expect($step->started_at->toDateTimeString())->toBe(now()->toDateTimeString());
    expect($step->state)->toBeInstanceOf(Running::class);
});

test('sets started_at when step transitions from Dispatched to Running', function (): void {
    // Create a step in Dispatched state with no started_at
    $step = Step::factory()->create([
        'state' => Dispatched::class,
        'started_at' => null,
    ]);

    expect($step->started_at)->toBeNull();

    // Transition to Running state
    $step->state = new Running($step);
    $step->save();

    // Refresh from database
    $step->refresh();

    // started_at should now be set
    expect($step->started_at->toDateTimeString())->toBe(now()->toDateTimeString());
    expect($step->state)->toBeInstanceOf(Running::class);
});

test('does not overwrite started_at if already set when transitioning to Running', function (): void {
    $existingStartedAt = now()->subMinutes(5);

    // Create a step in Pending state with started_at already set
    $step = Step::factory()->create([
        'state' => Pending::class,
        'started_at' => $existingStartedAt,
    ]);

    expect($step->started_at->timestamp)->toBe($existingStartedAt->timestamp);

    // Transition to Running state
    $step->state = new Running($step);
    $step->save();

    // Refresh from database
    $step->refresh();

    // started_at should remain unchanged (not overwritten)
    expect($step->started_at->timestamp)->toBe($existingStartedAt->timestamp);
});

test('does not change started_at when step is already Running and saves again', function (): void {
    $existingStartedAt = now()->subMinutes(5);

    // Create a step already in Running state with started_at set
    $step = Step::factory()->create([
        'state' => Running::class,
        'started_at' => $existingStartedAt,
    ]);

    expect($step->started_at->timestamp)->toBe($existingStartedAt->timestamp);

    // Save the step again (still Running)
    $step->retries = 5;
    $step->save();

    // Refresh from database
    $step->refresh();

    // started_at should remain unchanged
    expect($step->started_at->timestamp)->toBe($existingStartedAt->timestamp);
});

test('sets started_at via state machine transition PendingToRunning', function (): void {
    // Create a step in Pending state
    $step = Step::factory()->create([
        'state' => Pending::class,
        'started_at' => null,
    ]);

    expect($step->started_at)->toBeNull();

    // Use the state machine transition
    $step->state->transitionTo(Running::class);

    // Refresh from database
    $step->refresh();

    // started_at should now be set
    expect($step->started_at->toDateTimeString())->toBe(now()->toDateTimeString());
    expect($step->state)->toBeInstanceOf(Running::class);
});

test('clears is_throttled when step transitions to Completed', function (): void {
    // Create a step in Running state with is_throttled = true
    $step = Step::factory()->create([
        'state' => Running::class,
        'is_throttled' => true,
        'started_at' => now()->subMinutes(1),
    ]);

    expect($step->is_throttled)->toBeTrue();

    // Transition to Completed state
    $step->state = new Completed($step);
    $step->completed_at = now();
    $step->save();

    // Refresh from database
    $step->refresh();

    // is_throttled should now be false
    expect($step->is_throttled)->toBeFalse();
    expect($step->state)->toBeInstanceOf(Completed::class);
});

test('clears is_throttled via state machine transition RunningToCompleted', function (): void {
    // Create a step in Running state with is_throttled = true
    $step = Step::factory()->create([
        'state' => Running::class,
        'is_throttled' => true,
        'started_at' => now()->subMinutes(1),
    ]);

    expect($step->is_throttled)->toBeTrue();

    // Use the state machine transition
    $step->state->transitionTo(Completed::class);

    // Refresh from database
    $step->refresh();

    // is_throttled should now be false
    expect($step->is_throttled)->toBeFalse();
    expect($step->state)->toBeInstanceOf(Completed::class);
});

test('clears is_throttled even if it was set after completion attempt', function (): void {
    // Create a step in Running state
    $step = Step::factory()->create([
        'state' => Running::class,
        'is_throttled' => true,
        'was_throttled' => true,
        'started_at' => now()->subMinutes(1),
    ]);

    // Manually set to Completed with is_throttled still true (simulating the bug)
    $step->state = new Completed($step);
    $step->completed_at = now();
    $step->is_throttled = true; // Force it to be true before save
    $step->save();

    // Refresh from database
    $step->refresh();

    // Observer should have cleared is_throttled
    expect($step->is_throttled)->toBeFalse();
    expect($step->state)->toBeInstanceOf(Completed::class);
});
