<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use StepDispatcher\Models\StepsDispatcher;
use StepDispatcher\Models\StepsDispatcherTicks;
use StepDispatcher\Tests\Fixtures\ScenarioTestJob;
use StepDispatcher\Tests\Support\StepTester;

// Schematic: Early return Step 0 - No skipped parent steps found
// If no parents are skipped, skipAllChildStepsOnParentAndChildSingleStep should return early
it('step 0 returns early when no skipped parents exist', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1],
        ['block_uuid' => $block, 'index' => 2],
    ], ScenarioTestJob::class);

    [$s1, $s2] = $steps;

    // No skipped parents, so step 0 should return early and proceed normally
    $statusMatrix = [
        1 => [$s1->id => 'completed', $s2->id => 'pending'],
        2 => [$s1->id => 'completed', $s2->id => 'completed'],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('step_0_early_return_no_skipped_parents')
        ->test();
});

// Schematic: Early return Step 1 - No cancelled steps found
// If no steps are cancelled, cascadeCancelledSteps should return early
it('step 1 returns early when no cancelled steps exist', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1],
        ['block_uuid' => $block, 'index' => 2],
    ], ScenarioTestJob::class);

    [$s1, $s2] = $steps;

    // No cancelled steps, so step 1 should return early
    $statusMatrix = [
        1 => [$s1->id => 'completed', $s2->id => 'pending'],
        2 => [$s1->id => 'completed', $s2->id => 'completed'],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('step_1_early_return_no_cancelled_steps')
        ->test();
});

// Schematic: Early return Step 2 - No resolve-exception steps to promote
// If no resolve-exception steps are in not-runnable state, promoteResolveExceptionSteps returns early
it('step 2 returns early when no resolve-exception steps need promotion', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1],
    ], ScenarioTestJob::class);

    [$s1] = $steps;

    // No resolve-exception steps, so step 2 returns early
    $statusMatrix = [
        1 => [$s1->id => 'completed'],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('step_2_early_return_no_resolve_exception')
        ->test();
});

// Schematic: Early return Step 3 - No parent steps failed
// If no parent steps are in failed state, transitionParentsToFailed returns early
it('step 3 returns early when no parent steps failed', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
    ], ScenarioTestJob::class)[0];

    $child = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1],
    ], ScenarioTestJob::class)[0];

    // Parent completes successfully, step 3 returns early
    $statusMatrix = [
        1 => [$parent->id => 'running', $child->id => 'pending'],
        2 => [$parent->id => 'running', $child->id => 'completed'],
        3 => [$parent->id => 'completed', $child->id => 'completed'],
    ];

    StepTester::withSteps([$parent, $child])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('step_3_early_return_no_parent_failed')
        ->test();
});

// Schematic: Early return Step 4 - No failed steps with children
// If no failed steps have child_block_uuid, cascadeFailureToChildren returns early
it('step 4 returns early when no failed steps have children', function (): void {
    $block = (string) Str::uuid();

    $step = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1, 'arguments' => ['fail' => true]],
    ], ScenarioTestJob::class)[0];

    // Step fails but has no children, step 4 returns early
    $statusMatrix = [
        1 => [$step->id => 'failed'],
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('step_4_early_return_failed_step_no_children')
        ->test();
});

// Schematic: Early return Step 5 - No running parents to complete
// If no running parents exist, transitionParentsToComplete returns early
it('step 5 returns early when no running parents exist', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1],
    ], ScenarioTestJob::class);

    [$s1] = $steps;

    // No parent steps, step 5 returns early
    $statusMatrix = [
        1 => [$s1->id => 'completed'],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('step_5_early_return_no_running_parents')
        ->test();
});

// Schematic: Early return Step 6 - No pending steps to dispatch
// If all steps are in terminal states, PendingToDispatched returns early
it('step 6 returns early when no pending steps exist', function (): void {
    $block = (string) Str::uuid();

    $step = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1],
    ], ScenarioTestJob::class)[0];

    // First dispatch completes the step
    StepDispatcher\Support\StepDispatcher::dispatch($step->group);

    $step->refresh();
    expect($step->state->value())->toBe('completed');

    // Second dispatch - step 6 should return early (no pending steps)
    StepDispatcher\Support\StepDispatcher::dispatch($step->group);

    $step->refresh();
    expect($step->state->value())->toBe('completed'); // Still completed
});

// Schematic: Multiple early returns in same dispatch cycle
// Complex scenario where multiple steps return early
it('handles multiple early returns in same dispatch cycle', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1],
    ], ScenarioTestJob::class);

    [$s1] = $steps;

    // No skipped parents (step 0 early return)
    // No cancelled steps (step 1 early return)
    // No resolve-exception steps (step 2 early return)
    // No parent steps (step 3 early return)
    // No failed steps with children (step 4 early return)
    // No running parents (step 5 early return)
    // Step 6 dispatches the pending step

    $statusMatrix = [
        1 => [$s1->id => 'completed'],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('multiple_early_returns_in_cycle')
        ->test();
});

// Schematic: dispatch_after prevents step 6 transition
// Steps with future dispatch_after should not transition in step 6
it('step 6 skips steps with future dispatch_after', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1, 'dispatch_after' => now()->addYears(1)],
    ], ScenarioTestJob::class);

    [$s1] = $steps;

    // Step has future dispatch_after, should remain pending
    StepDispatcher\Support\StepDispatcher::dispatch($s1->group);

    $s1->refresh();
    expect($s1->state->value())->toBe('pending');

    // Multiple dispatches should still keep it pending
    StepDispatcher\Support\StepDispatcher::dispatch($s1->group);
    StepDispatcher\Support\StepDispatcher::dispatch($s1->group);

    $s1->refresh();
    expect($s1->state->value())->toBe('pending');
});

// Schematic: Step 6 respects index sequencing (early return for blocked steps)
// Steps blocked by previous index should not transition
it('step 6 respects index sequencing and skips blocked steps', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1, 'dispatch_after' => now()->addYears(1)], // Will never dispatch
        ['block_uuid' => $block, 'index' => 2], // Blocked by index 1
    ], ScenarioTestJob::class);

    [$s1, $s2] = $steps;

    // s1 has future dispatch_after, s2 should remain pending (blocked)
    StepDispatcher\Support\StepDispatcher::dispatch($s1->group);

    $s1->refresh();
    $s2->refresh();

    expect($s1->state->value())->toBe('pending');
    expect($s2->state->value())->toBe('pending'); // Blocked by s1
});

// Schematic: Step 6 dispatches when dispatch_after is reached
// Steps with past/current dispatch_after should transition
it('step 6 dispatches steps when dispatch_after is reached', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1, 'dispatch_after' => now()->subHour()], // Past time
    ], ScenarioTestJob::class);

    [$s1] = $steps;

    // dispatch_after is in the past, should dispatch normally
    StepDispatcher\Support\StepDispatcher::dispatch($s1->group);

    $s1->refresh();
    expect($s1->state->value())->toBe('completed');
});

// Schematic: Empty group returns early (no steps to process)
// Dispatching a group with no steps should return immediately
it('closes the tick and frees the lock when dispatching empty group', function (): void {
    $nonExistentGroup = 'empty-group-'.Str::uuid();

    StepDispatcher\Support\StepDispatcher::dispatch($nonExistentGroup);

    $lock = StepsDispatcher::query()->where('group', $nonExistentGroup)->sole();
    $tick = StepsDispatcherTicks::query()->where('group', $nonExistentGroup)->sole();

    expect($lock->can_dispatch)->toBeTrue()
        ->and($lock->current_tick_id)->toBeNull()
        ->and($tick->completed_at)->not->toBeNull();
});

// Schematic: All steps in terminal states returns early
// If all steps are completed/failed/stopped/cancelled/skipped, dispatcher returns early
it('returns early when all steps are in terminal states', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1],
        ['block_uuid' => $block, 'index' => 2],
    ], ScenarioTestJob::class);

    [$s1, $s2] = $steps;

    // Complete all steps
    StepDispatcher\Support\StepDispatcher::dispatch($s1->group);
    StepDispatcher\Support\StepDispatcher::dispatch($s1->group);

    $s1->refresh();
    $s2->refresh();

    expect($s1->state->value())->toBe('completed');
    expect($s2->state->value())->toBe('completed');

    // Dispatch again - should return early (all steps terminal)
    StepDispatcher\Support\StepDispatcher::dispatch($s1->group);

    $s1->refresh();
    $s2->refresh();

    expect($s1->state->value())->toBe('completed');
    expect($s2->state->value())->toBe('completed');
});
