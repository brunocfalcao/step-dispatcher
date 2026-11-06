<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use StepDispatcher\Tests\Fixtures\ScenarioTestJob;
use StepDispatcher\Tests\Support\StepTester;

// Schematic: Parent with child_block_uuid that gets children later
// Parent stays running and waits for children that are created after parent starts
it('waits for children created after parent starts running', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
    ], ScenarioTestJob::class)[0];

    // Parent becomes running
    StepDispatcher\Support\StepDispatcher::dispatch($parent->group);

    $parent->refresh();
    expect($parent->state->value())->toBe('running');

    // Now create children
    $child = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1],
    ], ScenarioTestJob::class)[0];

    // Update child to same group as parent
    $child->update(['group' => $parent->group]);

    // Dispatch - child should run
    StepDispatcher\Support\StepDispatcher::dispatch($parent->group);

    $parent->refresh();
    $child->refresh();

    expect($parent->state->value())->toBe('running');
    expect($child->state->value())->toBe('completed');

    // Dispatch - parent should complete
    StepDispatcher\Support\StepDispatcher::dispatch($parent->group);

    $parent->refresh();
    expect($parent->state->value())->toBe('completed');
});

// Schematic: Parent remains running indefinitely if child never completes
// Parent with pending child should not complete
it('keeps parent running while child is pending', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
    ], ScenarioTestJob::class)[0];

    $child = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1, 'dispatch_after' => now()->addYears(1)], // Will never dispatch
    ], ScenarioTestJob::class)[0];

    $statusMatrix = [
        1 => [$parent->id => 'running', $child->id => 'pending'],
        2 => [$parent->id => 'running', $child->id => 'pending'], // Parent waits
        3 => [$parent->id => 'running', $child->id => 'pending'], // Still waiting
    ];

    StepTester::withSteps([$parent, $child])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parent_waits_indefinitely_for_pending_child')
        ->test();
});

// Schematic: Parent with all children completed
// Parent should complete when all children are completed
it('completes parent when all children are completed', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
    ], ScenarioTestJob::class)[0];

    $children = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1],
        ['block_uuid' => $childBlock, 'index' => 2],
        ['block_uuid' => $childBlock, 'index' => 3],
    ], ScenarioTestJob::class);

    [$c1, $c2, $c3] = $children;

    $statusMatrix = [
        1 => [$parent->id => 'running', $c1->id => 'pending', $c2->id => 'pending', $c3->id => 'pending'],
        2 => [$parent->id => 'running', $c1->id => 'completed', $c2->id => 'pending', $c3->id => 'pending'],
        3 => [$parent->id => 'running', $c1->id => 'completed', $c2->id => 'completed', $c3->id => 'pending'],
        4 => [$parent->id => 'running', $c1->id => 'completed', $c2->id => 'completed', $c3->id => 'completed'],
        5 => [$parent->id => 'completed', $c1->id => 'completed', $c2->id => 'completed', $c3->id => 'completed'],
    ];

    StepTester::withSteps([$parent, $c1, $c2, $c3])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parent_completes_when_all_children_completed')
        ->test();
});

// Schematic: Parent with some children skipped
// Parent should still complete if all non-skipped children complete
it('completes parent when non-skipped children complete', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
    ], ScenarioTestJob::class)[0];

    $children = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1, 'arguments' => ['skip' => true]],
        ['block_uuid' => $childBlock, 'index' => 2],
    ], ScenarioTestJob::class);

    [$c1, $c2] = $children;

    $statusMatrix = [
        1 => [$parent->id => 'running', $c1->id => 'pending', $c2->id => 'pending'],
        2 => [$parent->id => 'running', $c1->id => 'skipped', $c2->id => 'pending'],
        3 => [$parent->id => 'running', $c1->id => 'skipped', $c2->id => 'completed'],
        4 => [$parent->id => 'completed', $c1->id => 'skipped', $c2->id => 'completed'],
    ];

    StepTester::withSteps([$parent, $c1, $c2])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parent_completes_with_skipped_children')
        ->test();
});

// Schematic: Parent re-dispatched multiple times (idempotency)
// Running parent should remain running on re-dispatch
it('keeps parent in running state on re-dispatch', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
    ], ScenarioTestJob::class)[0];

    $child = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1, 'dispatch_after' => now()->addYears(1)],
    ], ScenarioTestJob::class)[0];

    // Multiple dispatches
    StepDispatcher\Support\StepDispatcher::dispatch($parent->group);
    StepDispatcher\Support\StepDispatcher::dispatch($parent->group);
    StepDispatcher\Support\StepDispatcher::dispatch($parent->group);

    $parent->refresh();
    $child->refresh();

    expect($parent->state->value())->toBe('running'); // Still running
    expect($child->state->value())->toBe('pending'); // Still pending
});

// Schematic: Parent with nested parent child
// Parent → Child (also parent) → Grandchild
it('handles nested parent lifecycle correctly', function (): void {
    $blocks = [
        (string) Str::uuid(), // Grandparent
        (string) Str::uuid(), // Parent (also a child)
        (string) Str::uuid(), // Grandchild
    ];

    $grandparent = StepTester::createSteps([
        ['block_uuid' => $blocks[0], 'index' => 1, 'child_block_uuid' => $blocks[1]],
    ], ScenarioTestJob::class)[0];

    $parent = StepTester::createSteps([
        ['block_uuid' => $blocks[1], 'index' => 1, 'child_block_uuid' => $blocks[2]],
    ], ScenarioTestJob::class)[0];

    $grandchild = StepTester::createSteps([
        ['block_uuid' => $blocks[2], 'index' => 1],
    ], ScenarioTestJob::class)[0];

    $statusMatrix = [
        1 => [$grandparent->id => 'running', $parent->id => 'pending', $grandchild->id => 'pending'],
        2 => [$grandparent->id => 'running', $parent->id => 'running', $grandchild->id => 'pending'],
        3 => [$grandparent->id => 'running', $parent->id => 'running', $grandchild->id => 'completed'],
        4 => [$grandparent->id => 'running', $parent->id => 'completed', $grandchild->id => 'completed'],
        5 => [$grandparent->id => 'completed', $parent->id => 'completed', $grandchild->id => 'completed'],
    ];

    StepTester::withSteps([$grandparent, $parent, $grandchild])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('nested_parent_lifecycle')
        ->test();
});
