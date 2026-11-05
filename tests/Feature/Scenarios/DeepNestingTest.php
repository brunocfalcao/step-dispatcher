<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use StepDispatcher\Tests\Support\StepTester;
use StepDispatcher\Tests\Fixtures\ScenarioTestJob;


it('runs a single step wrapped inside 3 nested blocks', function (): void {
    $mainBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();
    $grandchildBlock = (string) Str::uuid();
    $greatGrandchildBlock = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $mainBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
        ['block_uuid' => $childBlock, 'index' => 1, 'child_block_uuid' => $grandchildBlock],
        ['block_uuid' => $grandchildBlock, 'index' => 1, 'child_block_uuid' => $greatGrandchildBlock],
        ['block_uuid' => $greatGrandchildBlock, 'index' => 1],
    ], ScenarioTestJob::class);

    [$m1, $c1, $g1, $gg1] = $steps;

    $statusMatrix = [
        1 => [
            $m1->id => 'running',
            $c1->id => 'pending',
            $g1->id => 'pending',
            $gg1->id => 'pending',
        ],
        2 => [
            $c1->id => 'running',
        ],
        3 => [
            $g1->id => 'running',
        ],
        4 => [
            $gg1->id => 'completed',
        ],
        5 => [
            $g1->id => 'completed',
        ],
        6 => [
            $c1->id => 'completed',
        ],
        7 => [
            $m1->id => 'completed',
        ],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('single_step_nested_3_levels')
        ->test();
});

it('runs two parallel steps at the same index in a 3-level nested structure with parents and child blocks', function (): void {
    $mainBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();
    $grandchildBlock = (string) Str::uuid();
    $greatGrandchildBlock = (string) Str::uuid();

    $steps = StepTester::createSteps([
        // Main Block (M1 -> M2 -> M3)
        ['block_uuid' => $mainBlock, 'index' => 1], // $m1
        ['block_uuid' => $mainBlock, 'index' => 2, 'child_block_uuid' => $childBlock], // $m2parent
        ['block_uuid' => $mainBlock, 'index' => 2], // $m2
        ['block_uuid' => $mainBlock, 'index' => 3], // $m3

        // Child Block (M2 -> C1, C2)
        ['block_uuid' => $childBlock, 'index' => 1], // $c1
        ['block_uuid' => $childBlock, 'index' => 2, 'child_block_uuid' => $grandchildBlock], // $c2parent
        ['block_uuid' => $childBlock, 'index' => 2], // $c2
        ['block_uuid' => $childBlock, 'index' => 3], // $c3

        // Grandchild Block (C1 -> G1, G2)
        ['block_uuid' => $grandchildBlock, 'index' => 1], // $gc1
        ['block_uuid' => $grandchildBlock, 'index' => 2], // $gc2
        ['block_uuid' => $grandchildBlock, 'index' => 2, 'child_block_uuid' => $greatGrandchildBlock], // $gc2parent
        ['block_uuid' => $grandchildBlock, 'index' => 3], // $gc3

        // Great-Grandchild Block (G1 -> GG1, GG2)
        ['block_uuid' => $greatGrandchildBlock, 'index' => 1], // $ggc1
        ['block_uuid' => $greatGrandchildBlock, 'index' => 2], // $ggc2
        ['block_uuid' => $greatGrandchildBlock, 'index' => 2], // $ggc2second
        ['block_uuid' => $greatGrandchildBlock, 'index' => 3], // $ggc3
    ], ScenarioTestJob::class);

    [$m1, $m2parent, $m2, $m3,
        $c1, $c2parent, $c2, $c3,
        $gc1, $gc2, $gc2parent, $gc3,
        $ggc1, $ggc2, $ggc2second, $ggc3]
     = $steps;

    $statusMatrix = [
        1 => [
            $m1->id => 'completed',
            $m2parent->id => 'pending',
            $m2->id => 'pending',
            $m3->id => 'pending',

            $c1->id => 'pending',
            $c2parent->id => 'pending',
            $c2->id => 'pending',
            $c3->id => 'pending',

            $gc1->id => 'pending',
            $gc2->id => 'pending',
            $gc2parent->id => 'pending',
            $gc3->id => 'pending',

            $ggc1->id => 'pending',
            $ggc2->id => 'pending',
            $ggc2second->id => 'pending',
            $ggc3->id => 'pending',
        ],
        2 => [
            $m2parent->id => 'running',
            $m2->id => 'completed',
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
            $gc2->id => 'completed',
            $gc2parent->id => 'running',
        ],
        7 => [
            $ggc1->id => 'completed',
        ],
        8 => [
            $ggc2->id => 'completed',
            $ggc2second->id => 'completed',
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
            $c3->id => 'completed',
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

it('runs a parent-and-child step in the 2nd level without other step peers', function (): void {
    $mainBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();
    $grandchildBlock = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $mainBlock, 'index' => 1], // $m1
        ['block_uuid' => $mainBlock, 'index' => 2, 'child_block_uuid' => $childBlock], // $m2parent
        ['block_uuid' => $mainBlock, 'index' => 3], // $m3

        ['block_uuid' => $childBlock, 'index' => 1, 'child_block_uuid' => $grandchildBlock], // $c2childparent

        // Grandchild Block (C1 -> G1, G2)
        ['block_uuid' => $grandchildBlock, 'index' => 1], // $gc1
        ['block_uuid' => $grandchildBlock, 'index' => 2], // $gc2
        ['block_uuid' => $grandchildBlock, 'index' => 2], // $gc3

    ], ScenarioTestJob::class);

    [$m1, $m2parent, $m3,
        $c2childparent,
        $gc1, $gc2, $gc3]
     = $steps;

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
            $c2childparent->id => 'running',
        ],

        4 => [
            $gc1->id => 'completed',
        ],

        5 => [
            $gc2->id => 'completed',
            $gc3->id => 'completed',
        ],

        6 => [
            $c2childparent->id => 'completed',
        ],

        7 => [
            $m2parent->id => 'completed',
        ],

        8 => [
            $m3->id => 'completed',
        ],
    ];

    // Run the test
    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parent_child_2nd_level_without_peers')
        ->test();
});
