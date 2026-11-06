<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use StepDispatcher\Tests\Fixtures\ScenarioTestJob;
use StepDispatcher\Tests\Support\StepTester;

// Schematic: Skipped parent → skip all children recursively
// When parent is skipped, all children in child block should be skipped
it('skips all children when parent is skipped', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock, 'arguments' => ['skip' => true]],
    ], ScenarioTestJob::class)[0];

    $children = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1],
        ['block_uuid' => $childBlock, 'index' => 2],
        ['block_uuid' => $childBlock, 'index' => 3],
    ], ScenarioTestJob::class);

    [$c1, $c2, $c3] = $children;

    $statusMatrix = [
        1 => [$parent->id => 'skipped', $c1->id => 'pending', $c2->id => 'pending', $c3->id => 'pending'],
        2 => [$parent->id => 'skipped', $c1->id => 'skipped', $c2->id => 'skipped', $c3->id => 'skipped'], // All skipped
    ];

    StepTester::withSteps([$parent, $c1, $c2, $c3])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('skip_parent_skips_all_children')
        ->test();
});

// Schematic: Skipped grandparent → skip all descendants
// When grandparent is skipped, children and grandchildren should all be skipped
it('skips all descendants when grandparent is skipped', function (): void {
    $block1 = (string) Str::uuid();
    $block2 = (string) Str::uuid();
    $block3 = (string) Str::uuid();

    $grandparent = StepTester::createSteps([
        ['block_uuid' => $block1, 'index' => 1, 'child_block_uuid' => $block2, 'arguments' => ['skip' => true]],
    ], ScenarioTestJob::class)[0];

    $parent = StepTester::createSteps([
        ['block_uuid' => $block2, 'index' => 1, 'child_block_uuid' => $block3],
    ], ScenarioTestJob::class)[0];

    $grandchildren = StepTester::createSteps([
        ['block_uuid' => $block3, 'index' => 1],
        ['block_uuid' => $block3, 'index' => 2],
    ], ScenarioTestJob::class);

    [$gc1, $gc2] = $grandchildren;

    $statusMatrix = [
        1 => [
            $grandparent->id => 'skipped',
            $parent->id => 'pending',
            $gc1->id => 'pending',
            $gc2->id => 'pending',
        ],
        2 => [
            $grandparent->id => 'skipped',
            $parent->id => 'skipped', // Parent AND grandchildren skipped in same tick via RECURSIVE CTE
            $gc1->id => 'skipped',
            $gc2->id => 'skipped',
        ],
    ];

    StepTester::withSteps([$grandparent, $parent, $gc1, $gc2])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('skip_grandparent_skips_all_descendants')
        ->test();
});

// Schematic: Skipped child → parent and siblings NOT skipped
// Skipping a child should not affect parent or siblings
it('does not skip parent or siblings when child is skipped', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
    ], ScenarioTestJob::class)[0];

    $children = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1, 'arguments' => ['skip' => true]], // Will be skipped
        ['block_uuid' => $childBlock, 'index' => 2], // Should run
    ], ScenarioTestJob::class);

    [$c1, $c2] = $children;

    $statusMatrix = [
        1 => [$parent->id => 'running', $c1->id => 'pending', $c2->id => 'pending'],
        2 => [$parent->id => 'running', $c1->id => 'skipped', $c2->id => 'pending'], // c1 skipped
        3 => [$parent->id => 'running', $c1->id => 'skipped', $c2->id => 'completed'], // c2 runs
        4 => [$parent->id => 'completed', $c1->id => 'skipped', $c2->id => 'completed'], // Parent completes
    ];

    StepTester::withSteps([$parent, $c1, $c2])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('skip_child_does_not_skip_parent_or_siblings')
        ->test();
});

// Schematic: Multiple skipped parents at same index IN SAME BLOCK
// Multiple parallel parents skipped should skip all their respective children
it('skips children for all skipped parallel parents', function (): void {
    $parentBlock = (string) Str::uuid(); // SAME block for both parents
    $child1Block = (string) Str::uuid();
    $child2Block = (string) Str::uuid();

    // Create all steps in one call to ensure same group
    $steps = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $child1Block, 'arguments' => ['skip' => true]],
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $child2Block, 'arguments' => ['skip' => true]],
        ['block_uuid' => $child1Block, 'index' => 1],
        ['block_uuid' => $child2Block, 'index' => 1],
    ], ScenarioTestJob::class);

    [$p1, $p2, $children1, $children2] = $steps;

    $statusMatrix = [
        1 => [
            $p1->id => 'skipped', // Both parents in same block execute together
            $p2->id => 'skipped',
            $children1->id => 'pending',
            $children2->id => 'pending',
        ],
        2 => [
            $p1->id => 'skipped',
            $p2->id => 'skipped',
            $children1->id => 'skipped', // Both child blocks skipped
            $children2->id => 'skipped',
        ],
    ];

    StepTester::withSteps([$p1, $p2, $children1, $children2])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('skip_multiple_parallel_parents_skips_all_children')
        ->test();
});

// Schematic: Skipped parent with parallel children
// All parallel children should be skipped
it('skips all parallel children when parent is skipped', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock, 'arguments' => ['skip' => true]],
    ], ScenarioTestJob::class)[0];

    $children = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1], // Parallel
        ['block_uuid' => $childBlock, 'index' => 1], // Parallel
        ['block_uuid' => $childBlock, 'index' => 1], // Parallel
    ], ScenarioTestJob::class);

    [$c1, $c2, $c3] = $children;

    $statusMatrix = [
        1 => [$parent->id => 'skipped', $c1->id => 'pending', $c2->id => 'pending', $c3->id => 'pending'],
        2 => [$parent->id => 'skipped', $c1->id => 'skipped', $c2->id => 'skipped', $c3->id => 'skipped'],
    ];

    StepTester::withSteps([$parent, $c1, $c2, $c3])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('skip_parent_skips_all_parallel_children')
        ->test();
});

// Schematic: Skip does not cascade to next index
// Skipping a step at index 1 should not prevent index 2 from executing
it('skips children only for skipped parents in mixed scenario', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1, 'arguments' => ['skip' => true]],
        ['block_uuid' => $block, 'index' => 2],
    ], ScenarioTestJob::class);

    [$s1, $s2] = $steps;

    $statusMatrix = [
        1 => [$s1->id => 'skipped', $s2->id => 'pending'],
        2 => [$s1->id => 'skipped', $s2->id => 'completed'], // s2 runs after s1 skips
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('skip_does_not_cascade_to_next_index')
        ->test();
});

// Schematic: Skipped parent with nested children (3 levels)
// All 3 levels should be skipped
it('skips nested children across multiple levels', function (): void {
    $blocks = [
        (string) Str::uuid(), // Grandparent
        (string) Str::uuid(), // Parent
        (string) Str::uuid(), // Child
    ];

    $grandparent = StepTester::createSteps([
        ['block_uuid' => $blocks[0], 'index' => 1, 'child_block_uuid' => $blocks[1], 'arguments' => ['skip' => true]],
    ], ScenarioTestJob::class)[0];

    // Ensure all steps in same group
    $sharedGroup = $grandparent->group;

    $parent = StepTester::createSteps([
        ['block_uuid' => $blocks[1], 'index' => 1, 'child_block_uuid' => $blocks[2], 'group' => $sharedGroup],
    ], ScenarioTestJob::class)[0];

    $child = StepTester::createSteps([
        ['block_uuid' => $blocks[2], 'index' => 1, 'group' => $sharedGroup],
    ], ScenarioTestJob::class)[0];

    $statusMatrix = [
        1 => [$grandparent->id => 'skipped', $parent->id => 'pending', $child->id => 'pending'],
        2 => [$grandparent->id => 'skipped', $parent->id => 'skipped', $child->id => 'skipped'], // ALL descendants skipped in one tick via RECURSIVE CTE
    ];

    StepTester::withSteps([$grandparent, $parent, $child])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('skip_nested_3_levels')
        ->test();
});

// Schematic: Skip does NOT cascade to subsequent indexes
// Skipping index 1 should not skip index 2
it('does not skip subsequent indexes when one is skipped', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1, 'arguments' => ['skip' => true]],
        ['block_uuid' => $block, 'index' => 2], // Should run
    ], ScenarioTestJob::class);

    [$s1, $s2] = $steps;

    $statusMatrix = [
        1 => [$s1->id => 'skipped', $s2->id => 'pending'],
        2 => [$s1->id => 'skipped', $s2->id => 'completed'], // s2 runs normally
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('skip_does_not_cascade_to_next_index')
        ->test();
});
