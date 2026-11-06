<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\Models\StepsDispatcher;
use StepDispatcher\Models\StepsDispatcherTicks;
use StepDispatcher\States\Completed;
use StepDispatcher\Tests\Fixtures\ScenarioTestJob;
use StepDispatcher\Tests\Support\StepTester;

// Test: Empty dispatch (no pending steps)
// Dispatcher should handle gracefully when there are no steps to dispatch
it('handles empty dispatch with no pending steps', function (): void {
    // No steps created at all
    StepDispatcher\Support\StepDispatcher::dispatch();

    // The tick ran, closed itself and left the lock free
    $lock = StepsDispatcher::query()->whereNull('group')->sole();
    $tick = StepsDispatcherTicks::query()->whereNull('group')->sole();

    expect($lock->can_dispatch)->toBeTrue()
        ->and($lock->current_tick_id)->toBeNull()
        ->and($tick->completed_at)->not->toBeNull()
        ->and(Step::query()->count())->toBe(0);
});

// Test: Dispatcher with only completed steps
// Should not re-dispatch completed steps
it('does not re-dispatch completed steps', function (): void {
    $step = StepTester::createSteps([[]], ScenarioTestJob::class)[0];

    // Dispatch once to complete
    StepDispatcher\Support\StepDispatcher::dispatch($step->group);

    $step->refresh();
    expect($step->state->value())->toBe('completed');

    // Mark it as completed (in case it's not)
    $step->update(['state' => Completed::class]);

    // Dispatch again
    StepDispatcher\Support\StepDispatcher::dispatch($step->group);

    // Should still be completed, not dispatched again
    $step->refresh();
    expect($step->state->value())->toBe('completed');
});

// Test: Step with invalid class name
// Should transition to Failed
it('fails step with invalid class name', function (): void {
    $step = Step::factory()->create([
        'class' => 'NonExistent\\Class\\That\\Does\\Not\\Exist',
        'queue' => 'sync',
    ]);

    StepDispatcher\Support\StepDispatcher::dispatch($step->group);

    $step->refresh();
    expect($step->state->value())->toBe('failed');
});

// Test: Step with empty class name
// Should transition to Failed
it('fails step with empty class name', function (): void {
    $step = Step::factory()->create([
        'class' => '',
        'queue' => 'sync',
    ]);

    StepDispatcher\Support\StepDispatcher::dispatch($step->group);

    $step->refresh();
    expect($step->state->value())->toBe('failed');
});

// Test: Step with null class name
// Should transition to Failed
it('fails step with null class name', function (): void {
    $step = Step::factory()->create([
        'class' => null,
        'queue' => 'sync',
    ]);

    StepDispatcher\Support\StepDispatcher::dispatch($step->group);

    $step->refresh();
    expect($step->state->value())->toBe('failed');
});

// Test: Step with index gap (1, 3, 5 - missing 2, 4)
// Index 3 should wait forever since index 2 doesn't exist
it('waits forever when previous index does not exist', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1],
        ['block_uuid' => $block, 'index' => 3], // Gap! Index 2 missing
    ], ScenarioTestJob::class);

    [$s1, $s3] = $steps;

    $statusMatrix = [
        1 => [$s1->id => 'completed', $s3->id => 'pending'],
        2 => [$s1->id => 'completed', $s3->id => 'pending'], // Still pending (waiting for index 2)
        3 => [$s1->id => 'completed', $s3->id => 'pending'], // Still pending
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('index_gap_waits_forever')
        ->test();
});

// Test: Step with index 0
// Observer should normalize to index 1
it('normalizes index 0 to index 1', function (): void {
    $step = Step::factory()->create([
        'index' => 0,
    ]);

    $step->refresh();
    expect($step->index)->toBe(1);
});

// Test: Parent with no child_block_uuid (orphan parent)
// Should behave like regular step without children
it('treats parent with null child_block_uuid as regular step', function (): void {
    $step = StepTester::createSteps([
        ['child_block_uuid' => null], // No children
    ], ScenarioTestJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'completed'], // Should complete normally
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('orphan_parent_completes')
        ->test();
});

// Test: Parent with empty string child_block_uuid
// Should be treated as null
it('treats parent with empty string child_block_uuid as null', function (): void {
    $step = Step::factory()->create([
        'child_block_uuid' => '',
    ]);

    expect($step->isParent())->toBe(false); // empty string = not a parent
});

// Test: Step exists but no parent points to it
// Step without a parent should dispatch normally (treated as regular step)
it('dispatches steps without parent normally', function (): void {
    $childBlock = (string) Str::uuid();

    $child = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1],
    ], ScenarioTestJob::class)[0];

    // No parent step with child_block_uuid = $childBlock

    $statusMatrix = [
        1 => [$child->id => 'completed'], // Dispatches and completes normally
    ];

    StepTester::withSteps([$child])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('orphan_child_dispatches_normally')
        ->test();
});

// Test: Multiple parents pointing to same child_block_uuid
// This is technically invalid, but system should not crash
it('handles multiple parents with same child_block_uuid', function (): void {
    $parentBlock1 = (string) Str::uuid();
    $parentBlock2 = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent1 = StepTester::createSteps([
        ['block_uuid' => $parentBlock1, 'index' => 1, 'child_block_uuid' => $childBlock],
    ], ScenarioTestJob::class)[0];

    $parent2 = StepTester::createSteps([
        ['block_uuid' => $parentBlock2, 'index' => 1, 'child_block_uuid' => $childBlock],
    ], ScenarioTestJob::class)[0];

    $child = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1],
    ], ScenarioTestJob::class)[0];

    // Ensure both parents are in the same group for this test
    $parent2->update(['group' => $parent1->group]);
    $child->update(['group' => $parent1->group]);

    // Both parents transition to running (same group, both are pending lifecycle steps)
    StepDispatcher\Support\StepDispatcher::dispatch($parent1->group);

    $parent1->refresh();
    $parent2->refresh();
    $child->refresh();

    expect($parent1->state->value())->toBe('running');
    expect($parent2->state->value())->toBe('running'); // Also running (same group dispatch)
    expect($child->state->value())->toBe('pending');

    // Child completes
    StepDispatcher\Support\StepDispatcher::dispatch($child->group);

    $parent1->refresh();
    $parent2->refresh();
    $child->refresh();

    expect($parent1->state->value())->toBe('running');
    expect($parent2->state->value())->toBe('running');
    expect($child->state->value())->toBe('completed');

    // Both parents should complete (same group, single dispatch)
    StepDispatcher\Support\StepDispatcher::dispatch($parent1->group);

    $parent1->refresh();
    $parent2->refresh();
    $child->refresh();

    expect($parent1->state->value())->toBe('completed');
    expect($parent2->state->value())->toBe('completed');
    expect($child->state->value())->toBe('completed');
});

// Test: Completed step with child_block_uuid (parent already completed)
// Should not check children again
it('does not recheck children for completed parent', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
    ], ScenarioTestJob::class)[0];

    $child = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1],
    ], ScenarioTestJob::class)[0];

    // Complete the workflow
    StepDispatcher\Support\StepDispatcher::dispatch($parent->group);
    StepDispatcher\Support\StepDispatcher::dispatch($parent->group);
    StepDispatcher\Support\StepDispatcher::dispatch($parent->group);

    $parent->refresh();
    $child->refresh();

    expect($parent->state->value())->toBe('completed');
    expect($child->state->value())->toBe('completed');

    // Dispatch again - should not affect anything
    StepDispatcher\Support\StepDispatcher::dispatch($parent->group);

    $parent->refresh();
    $child->refresh();

    expect($parent->state->value())->toBe('completed');
    expect($child->state->value())->toBe('completed');
});

// Test: Deep nesting (5 levels)
// P1 -> C1 -> G1 -> GG1 -> GGG1
it('handles deep nesting (5 levels)', function (): void {
    $blocks = [
        (string) Str::uuid(),
        (string) Str::uuid(),
        (string) Str::uuid(),
        (string) Str::uuid(),
        (string) Str::uuid(),
    ];

    $steps = [];

    // Create chain of 5 nested parent-child relationships
    for ($i = 0; $i < 4; $i++) {
        $steps[] = StepTester::createSteps([
            ['block_uuid' => $blocks[$i], 'index' => 1, 'child_block_uuid' => $blocks[$i + 1]],
        ], ScenarioTestJob::class)[0];
    }

    // Deepest child (no children of its own)
    $steps[] = StepTester::createSteps([
        ['block_uuid' => $blocks[4], 'index' => 1],
    ], ScenarioTestJob::class)[0];

    [$p1, $c1, $g1, $gg1, $ggg1] = $steps;

    $statusMatrix = [
        1 => [
            $p1->id => 'running',
            $c1->id => 'pending',
            $g1->id => 'pending',
            $gg1->id => 'pending',
            $ggg1->id => 'pending',
        ],
        2 => [
            $p1->id => 'running',
            $c1->id => 'running',
            $g1->id => 'pending',
            $gg1->id => 'pending',
            $ggg1->id => 'pending',
        ],
        3 => [
            $p1->id => 'running',
            $c1->id => 'running',
            $g1->id => 'running',
            $gg1->id => 'pending',
            $ggg1->id => 'pending',
        ],
        4 => [
            $p1->id => 'running',
            $c1->id => 'running',
            $g1->id => 'running',
            $gg1->id => 'running',
            $ggg1->id => 'pending',
        ],
        5 => [
            $p1->id => 'running',
            $c1->id => 'running',
            $g1->id => 'running',
            $gg1->id => 'running',
            $ggg1->id => 'completed', // Deepest completes first
        ],
        6 => [
            $p1->id => 'running',
            $c1->id => 'running',
            $g1->id => 'running',
            $gg1->id => 'completed',
            $ggg1->id => 'completed',
        ],
        7 => [
            $p1->id => 'running',
            $c1->id => 'running',
            $g1->id => 'completed',
            $gg1->id => 'completed',
            $ggg1->id => 'completed',
        ],
        8 => [
            $p1->id => 'running',
            $c1->id => 'completed',
            $g1->id => 'completed',
            $gg1->id => 'completed',
            $ggg1->id => 'completed',
        ],
        9 => [
            $p1->id => 'completed', // Top-level completes last
            $c1->id => 'completed',
            $g1->id => 'completed',
            $gg1->id => 'completed',
            $ggg1->id => 'completed',
        ],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('deep_nesting_5_levels')
        ->test();
});

// Test: Step with null block_uuid
// Should fail or be rejected
it('handles step with null block_uuid', function (): void {
    $step = Step::factory()->create([
        'block_uuid' => null,
    ]);

    // Observer should have assigned a block_uuid
    $step->refresh();
    expect(Str::isUuid($step->block_uuid))->toBeTrue();
});
