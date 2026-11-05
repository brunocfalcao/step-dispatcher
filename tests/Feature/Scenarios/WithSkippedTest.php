<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use StepDispatcher\Tests\Support\StepTester;
use StepDispatcher\Tests\Fixtures\ScenarioTestJob;


// Schematic: [∅]
// A single unindexed step should immediately run and complete.
it('runs a single skipped step with no index or children', function (): void {
    $step = StepTester::createSteps([
        ['arguments' => ['skip' => true]],
    ], ScenarioTestJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'skipped'],
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('single_step_no_index_skipped')
        ->test();
});

// Schematic: 1 -> 2
// Two steps in sequence should run one after the other.
it('runs two sequential steps (index 1 → 2), one skipped', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1, 'arguments' => ['skip' => true]],
        ['block_uuid' => $block, 'index' => 2],
    ], ScenarioTestJob::class);

    [$step1, $step2] = $steps;

    $statusMatrix = [
        1 => [$step1->id => 'skipped', $step2->id => 'pending'],
        2 => [$step2->id => 'completed'],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('sequential_steps_1_to_2_2nd_skipped')
        ->test();
});

// Schematic: 1,1
// Two steps at the same index should run in parallel.
it('runs two parallel steps at the same index, one skipped', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1],
        ['block_uuid' => $block, 'index' => 1, 'arguments' => ['skip' => true]],
    ], ScenarioTestJob::class);

    [$step1, $step2] = $steps;

    $statusMatrix = [
        1 => [$step1->id => 'completed', $step2->id => 'skipped'],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parallel_same_index_one_skipped')
        ->test();
});

// Schematic: 1 -> 2,2 -> 3
// Mixed case: sequential step, then two parallel steps, then another sequential step, 2 skipped
it('runs a sequence with parallel middle: 1 → (2 + 2) → 3', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1],
        ['block_uuid' => $block, 'index' => 2, 'arguments' => ['skip' => true]],
        ['block_uuid' => $block, 'index' => 2],
        ['block_uuid' => $block, 'index' => 3, 'arguments' => ['skip' => true]],
    ], ScenarioTestJob::class);

    [$s1, $s2a, $s2b, $s3] = $steps;

    $statusMatrix = [
        1 => [$s1->id => 'completed', $s2a->id => 'pending', $s2b->id => 'pending', $s3->id => 'pending'],
        2 => [$s2a->id => 'skipped', $s2b->id => 'completed'],
        3 => [$s3->id => 'skipped'],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parallel_middle_sequence')
        ->test();
});

it('runs two parallel steps at the same index in a 3-level nested structure with parents and child blocks, some skipped', function (): void {
    $mainBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();
    $grandchildBlock = (string) Str::uuid();
    $greatGrandchildBlock = (string) Str::uuid();

    $steps = StepTester::createSteps([
        // Main Block (M1 -> M2 -> M3)
        ['block_uuid' => $mainBlock, 'index' => 1], // $m1
        ['block_uuid' => $mainBlock, 'index' => 2, 'child_block_uuid' => $childBlock], // $m2parent
        ['block_uuid' => $mainBlock, 'index' => 2, 'arguments' => ['skip' => true]], // $m2skipped
        ['block_uuid' => $mainBlock, 'index' => 3], // $m3

        // Child Block (M2 -> C1, C2)
        ['block_uuid' => $childBlock, 'index' => 1], // $c1
        ['block_uuid' => $childBlock, 'index' => 2, 'child_block_uuid' => $grandchildBlock], // $c2parent
        ['block_uuid' => $childBlock, 'index' => 2], // $c2
        ['block_uuid' => $childBlock, 'index' => 3, 'arguments' => ['skip' => true]], // $c3skipped

        // Grandchild Block (C1 -> G1, G2)
        ['block_uuid' => $grandchildBlock, 'index' => 1], // $gc1
        ['block_uuid' => $grandchildBlock, 'index' => 2, 'arguments' => ['skip' => true]], // $gc2skipped
        ['block_uuid' => $grandchildBlock, 'index' => 2, 'child_block_uuid' => $greatGrandchildBlock], // $gc2parent
        ['block_uuid' => $grandchildBlock, 'index' => 3], // $gc3

        // Great-Grandchild Block (G1 -> GG1, GG2)
        ['block_uuid' => $greatGrandchildBlock, 'index' => 1, 'arguments' => ['skip' => true]], // $ggc1skipped
        ['block_uuid' => $greatGrandchildBlock, 'index' => 2], // $ggc2
        ['block_uuid' => $greatGrandchildBlock, 'index' => 2, 'arguments' => ['skip' => true]], // $ggc2secondskipped
        ['block_uuid' => $greatGrandchildBlock, 'index' => 3], // $ggc3
    ], ScenarioTestJob::class);

    [$m1, $m2parent, $m2skipped, $m3,
        $c1, $c2parent, $c2, $c3skipped,
        $gc1, $gc2skipped, $gc2parent, $gc3,
        $ggc1skipped, $ggc2, $ggc2secondskipped, $ggc3]
     = $steps;

    $statusMatrix = [
        1 => [
            $m1->id => 'completed',
            $m2parent->id => 'pending',
            $m2skipped->id => 'pending',
            $m3->id => 'pending',

            $c1->id => 'pending',
            $c2parent->id => 'pending',
            $c2->id => 'pending',
            $c3skipped->id => 'pending',

            $gc1->id => 'pending',
            $gc2skipped->id => 'pending',
            $gc2parent->id => 'pending',
            $gc3->id => 'pending',

            $ggc1skipped->id => 'pending',
            $ggc2->id => 'pending',
            $ggc2secondskipped->id => 'pending',
            $ggc3->id => 'pending',
        ],
        2 => [
            $m2parent->id => 'running',
            $m2skipped->id => 'skipped',
        ],
        3 => [
            $c1->id => 'completed',
        ],
        4 => [
            $c2parent->id => 'running',
            $c2->id => 'completed',
        ],
        5 => [
            $gc1->id => 'completed',
        ],
        6 => [
            $gc2skipped->id => 'skipped',
            $gc2parent->id => 'running',
        ],
        7 => [
            $ggc1skipped->id => 'skipped',
        ],
        8 => [
            $ggc2->id => 'completed',
            $ggc2secondskipped->id => 'skipped',
        ],
        9 => [
            $ggc3->id => 'completed',
        ],
        10 => [
            $gc2parent->id => 'completed',
        ],
        11 => [
            $gc3->id => 'completed',
        ],
        12 => [
            $c2parent->id => 'completed',
        ],
        13 => [
            $c3skipped->id => 'skipped',
        ],
        14 => [
            $m2parent->id => 'completed',
        ],
        15 => [
            $m3->id => 'completed',
        ],
    ];

    // Run the test
    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parallel_same_index_nested_3_levels_with_parents')
        ->test();
});

it('runs a child-and-parent step that will be skipped', function (): void {
    $mainBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();
    $grandchildBlock = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $mainBlock, 'index' => 1], // $m1
        ['block_uuid' => $mainBlock, 'index' => 2, 'child_block_uuid' => $childBlock], // $m2parent
        ['block_uuid' => $mainBlock, 'index' => 3], // $m3

        ['block_uuid' => $childBlock, 'index' => 1, 'child_block_uuid' => $grandchildBlock, 'arguments' => ['skip' => true]], // $c2childparent

        ['block_uuid' => $grandchildBlock, 'index' => 1], // $gc1
        ['block_uuid' => $grandchildBlock, 'index' => 2], // $gc2
        ['block_uuid' => $grandchildBlock, 'index' => 3], // $gc3

    ], ScenarioTestJob::class);

    [$m1, $m2parent, $m3, $c2childparent, $gc1, $gc2, $gc3] = $steps;

    $statusMatrix = [
        1 => [
            $m1->id => 'completed',
            $m2parent->id => 'pending',
            $m3->id => 'pending',

            $c2childparent->id => 'pending',

            $gc1->id => 'pending',
            $gc2->id => 'pending',
            $gc3->id => 'pending',
        ],

        2 => [
            $m2parent->id => 'running',
        ],

        3 => [
            $c2childparent->id => 'skipped',
        ],

        4 => [
            $gc1->id => 'skipped',
            $gc2->id => 'skipped',
            $gc3->id => 'skipped',
        ],
    ];

    // Run the test
    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parallel_same_index_nested_3_levels_with_parents')
        ->test();
});
