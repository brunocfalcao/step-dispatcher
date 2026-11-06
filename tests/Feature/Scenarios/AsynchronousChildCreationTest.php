<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use StepDispatcher\Tests\Fixtures\ScenarioTestJob;
use StepDispatcher\Tests\Support\StepTester;

it('creates M1 -> M2 (parent) -> M3 steps with a child block uuid that is not created yet', function (): void {
    $mainBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    // Create steps with M1, M2 (parent), and M3
    $steps = StepTester::createSteps([
        // M1 (index 1)
        ['block_uuid' => $mainBlock, 'index' => 1],

        // M2 (parent, index 2, with child_block_uuid to child block, child block is not created yet)
        ['block_uuid' => $mainBlock, 'index' => 2, 'child_block_uuid' => $childBlock],

        // M3 (index 3, runs after M2)
        ['block_uuid' => $mainBlock, 'index' => 3],
    ], ScenarioTestJob::class);

    // Extract created steps
    [$m1, $m2Parent, $m3] = $steps;

    // Status matrix to ensure the steps are created and executed in sequence
    $statusMatrixBeforeChildCreated = [
        1 => [
            $m1->id => 'completed',
            $m2Parent->id => 'pending',
            $m3->id => 'pending',
        ],
        2 => [
            $m2Parent->id => 'running',
            $m3->id => 'pending',
        ],
        3 => [
            $m2Parent->id => 'running',
            $m3->id => 'pending',
        ],
    ];

    // Run the test BEFORE child block is created
    StepTester::withSteps([$m1, $m2Parent, $m3])
        ->withStatusMatrix($statusMatrixBeforeChildCreated)
        ->withLabel('M1_M2_parent_M3_with_pending_child_block_before')
        ->test();

    // Now create the child block (for M2) after the initial steps
    $childSteps = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1], // Child step
    ], ScenarioTestJob::class);

    // Extract created child step
    [$childStep] = $childSteps;

    // Status matrix to ensure the steps are created and executed after child block is created
    $statusMatrixAfterChildCreated = [
        1 => [
            $m1->id => 'completed',
            $m2Parent->id => 'running',
            $m3->id => 'pending',
            $childStep->id => 'completed',
        ],
        2 => [
            $m2Parent->id => 'completed',
        ],
        3 => [
            $m3->id => 'completed',
        ],
    ];

    // Run the test AFTER child block is created
    StepTester::withSteps([$m1, $m2Parent, $m3, $childStep])
        ->withStatusMatrix($statusMatrixAfterChildCreated)
        ->withLabel('M1_M2_parent_M3_with_pending_child_block_after')
        ->test();
});
