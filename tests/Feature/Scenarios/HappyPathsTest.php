<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use StepDispatcher\Tests\Support\StepTester;
use StepDispatcher\Tests\Fixtures\ScenarioTestJob;


// Schematic: [∅]
// A single unindexed step should immediately run and complete.
it('runs a single step with no index or children', function (): void {
    $step = StepTester::createSteps([
        [/* no index or block */],
    ], ScenarioTestJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'completed'],
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('single_step_no_index')
        ->test();
});

// Schematic: 1 -> 2
// Two steps in sequence should run one after the other.
it('runs two sequential steps (index 1 → 2)', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1],
        ['block_uuid' => $block, 'index' => 2],
    ], ScenarioTestJob::class);

    [$step1, $step2] = $steps;

    $statusMatrix = [
        1 => [$step1->id => 'completed', $step2->id => 'pending'],
        2 => [$step1->id => 'completed', $step2->id => 'completed'],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('sequential_steps_1_to_2')
        ->test();
});

// Schematic: 1,1
// Two steps at the same index should run in parallel.
it('runs two parallel steps at the same index', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1],
        ['block_uuid' => $block, 'index' => 1],
    ], ScenarioTestJob::class);

    [$step1, $step2] = $steps;

    $statusMatrix = [
        1 => [$step1->id => 'completed', $step2->id => 'completed'],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parallel_same_index')
        ->test();
});

// Schematic: 1,1 -> 2
// Two steps at the same index should run in parallel.
it('runs two parallel steps at the same index and then a next index step', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1],
        ['block_uuid' => $block, 'index' => 1],
        ['block_uuid' => $block, 'index' => 2],
    ], ScenarioTestJob::class);

    [$step1, $step2, $step3] = $steps;

    $statusMatrix = [
        1 => [$step1->id => 'completed', $step2->id => 'completed', $step3->id => 'pending'],
        2 => [$step3->id => 'completed'],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->test();
});

// Schematic: 1 -> 2,2 -> 3
// Mixed case: sequential step, then two parallel steps, then another sequential step.
it('runs a sequence with parallel middle: 1 → (2 + 2) → 3', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1],
        ['block_uuid' => $block, 'index' => 2],
        ['block_uuid' => $block, 'index' => 2],
        ['block_uuid' => $block, 'index' => 3],
    ], ScenarioTestJob::class);

    [$s1, $s2a, $s2b, $s3] = $steps;

    $statusMatrix = [
        1 => [$s1->id => 'completed', $s2a->id => 'pending', $s2b->id => 'pending', $s3->id => 'pending'],
        2 => [$s1->id => 'completed', $s2a->id => 'completed', $s2b->id => 'completed', $s3->id => 'pending'],
        3 => [$s1->id => 'completed', $s2a->id => 'completed', $s2b->id => 'completed', $s3->id => 'completed'],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parallel_middle_sequence')
        ->test();
});

// Schematic:
// Parent:     1
//             ↓
// ChildBlock: 1 -> 2
// A parent lifecycle step completes only after its child block completes.
it('completes a lifecycle step after its children complete', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
    ], ScenarioTestJob::class)[0];

    $children = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1],
        ['block_uuid' => $childBlock, 'index' => 2],
    ], ScenarioTestJob::class);

    [$c1, $c2] = $children;

    $statusMatrix = [
        1 => [$parent->id => 'running', $c1->id => 'pending', $c2->id => 'pending'],
        2 => [$parent->id => 'running', $c1->id => 'completed', $c2->id => 'pending'],
        3 => [$parent->id => 'running', $c1->id => 'completed', $c2->id => 'completed'],
        4 => [$parent->id => 'completed', $c1->id => 'completed', $c2->id => 'completed'],
    ];

    StepTester::withSteps([$parent, ...$children])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('lifecycle_parent_after_children')
        ->test();
});

// Schematic:
// P1
//  ↓
// C1
//  ↓
// G1a [2], G1b [2] → G2 [3]
// A grandchild block with two parallel steps (index 2) followed by one step (index 3), in 3-level nesting.
it('executes parallel + sequential grandchildren steps inside 3-level nesting', function (): void {
    $block1 = (string) Str::uuid();
    $block2 = (string) Str::uuid();
    $block3 = (string) Str::uuid();

    $P1 = StepTester::createSteps([
        ['block_uuid' => $block1, 'index' => 1, 'child_block_uuid' => $block2],
    ], ScenarioTestJob::class)[0];

    $C1 = StepTester::createSteps([
        ['block_uuid' => $block2, 'index' => 1, 'child_block_uuid' => $block3],
    ], ScenarioTestJob::class)[0];

    [$G1a, $G1b, $G2] = StepTester::createSteps([
        ['block_uuid' => $block3, 'index' => 1],
        ['block_uuid' => $block3, 'index' => 1],
        ['block_uuid' => $block3, 'index' => 2],
    ], ScenarioTestJob::class);

    $statusMatrix = [
        1 => [
            $P1->id => 'running',
            $C1->id => 'pending',
            $G1a->id => 'pending',
            $G1b->id => 'pending',
            $G2->id => 'pending',
        ],

        2 => [
            $P1->id => 'running',
            $C1->id => 'running',
            $G1a->id => 'pending',
            $G1b->id => 'pending',
            $G2->id => 'pending',
        ],
        3 => [
            $P1->id => 'running',
            $C1->id => 'running',
            $G1a->id => 'completed',
            $G1b->id => 'completed',
            $G2->id => 'pending',
        ],
        4 => [
            $P1->id => 'running',
            $C1->id => 'running',
            $G1a->id => 'completed',
            $G1b->id => 'completed',
            $G2->id => 'completed',
        ],

        5 => [
            $P1->id => 'running',
            $C1->id => 'completed',
            $G1a->id => 'completed',
            $G1b->id => 'completed',
            $G2->id => 'completed',
        ],

        6 => [
            $P1->id => 'completed',
            $C1->id => 'completed',
            $G1a->id => 'completed',
            $G1b->id => 'completed',
            $G2->id => 'completed',
        ],
    ];

    StepTester::withSteps([$P1, $C1, $G1a, $G1b, $G2])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('nested_parallel_grandchildren_with_sequence')
        ->test();
});
