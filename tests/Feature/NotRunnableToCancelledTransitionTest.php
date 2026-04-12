<?php

declare(strict_types=1);

use StepDispatcher\Models\Step;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\NotRunnable;
use StepDispatcher\Support\StepDispatcher;

it('can transition NotRunnable steps to Cancelled when parent is cancelled', function () {
    // Create a parent step that will be cancelled
    $parentStep = Step::create([
        'class' => 'App\\Jobs\\TestParentJob',
        'block_uuid' => 'parent-block-uuid',
        'child_block_uuid' => 'child-block-uuid',
        'index' => 1,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
    ]);

    // Create a child resolve-exception step in NotRunnable state
    $childStep = Step::create([
        'class' => 'App\\Jobs\\TestResolveExceptionJob',
        'block_uuid' => 'child-block-uuid',
        'index' => 1,
        'type' => 'resolve-exception',
        'queue' => 'default',
        'group' => 'test-group',
    ]);

    // Verify child is in NotRunnable state (set by observer)
    expect($childStep->fresh()->state)->toBeInstanceOf(NotRunnable::class);

    // Cancel the parent step
    $parentStep->state->transitionTo(Cancelled::class);
    expect($parentStep->fresh()->state)->toBeInstanceOf(Cancelled::class);

    // Run dispatch - this should cancel the NotRunnable child
    StepDispatcher::dispatch('test-group');

    // The child should now be Cancelled
    $childStep->refresh();
    expect($childStep->state)->toBeInstanceOf(Cancelled::class);
});

it('allows direct transition from NotRunnable to Cancelled', function () {
    $step = Step::create([
        'class' => 'App\\Jobs\\TestJob',
        'block_uuid' => 'test-block-uuid',
        'index' => 1,
        'type' => 'resolve-exception',
        'queue' => 'default',
        'group' => 'test-group',
    ]);

    // Verify it starts in NotRunnable state
    expect($step->fresh()->state)->toBeInstanceOf(NotRunnable::class);

    // This should NOT throw an exception
    $step->state->transitionTo(Cancelled::class);

    expect($step->fresh()->state)->toBeInstanceOf(Cancelled::class);
});
