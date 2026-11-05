<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use StepDispatcher\Tests\Support\StepTester;
use StepDispatcher\Tests\Fixtures\ScenarioTestJob;


// Schematic: Multiple steps with index=null in same block
// All should dispatch in parallel (no dependency)
it('dispatches all index null steps in parallel', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => null],
        ['block_uuid' => $block, 'index' => null],
        ['block_uuid' => $block, 'index' => null],
    ], ScenarioTestJob::class);

    [$s1, $s2, $s3] = $steps;

    $statusMatrix = [
        1 => [$s1->id => 'completed', $s2->id => 'completed', $s3->id => 'completed'], // All dispatch together
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('multiple_index_null_dispatch_parallel')
        ->test();
});

// Schematic: Mixed index=null and index=1 in same block
// index=null should dispatch immediately, index=1 waits for conclusion
it('dispatches index null before index 1', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => null],
        ['block_uuid' => $block, 'index' => 1],
    ], ScenarioTestJob::class);

    [$sNull, $s1] = $steps;

    $statusMatrix = [
        1 => [$sNull->id => 'completed', $s1->id => 'completed'], // Both dispatch together (index=null doesn't block index=1)
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('index_null_before_index_1')
        ->test();
});

// Schematic: index=null child step with running parent
// index=null child can dispatch when parent is running
it('dispatches index null child when parent is running', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
    ], ScenarioTestJob::class)[0];

    $child = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => null], // Null index
    ], ScenarioTestJob::class)[0];

    $statusMatrix = [
        1 => [$parent->id => 'running', $child->id => 'pending'],
        2 => [$parent->id => 'running', $child->id => 'completed'], // index=null child dispatches
        3 => [$parent->id => 'completed', $child->id => 'completed'],
    ];

    StepTester::withSteps([$parent, $child])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('index_null_child_with_running_parent')
        ->test();
});

// Schematic: index=null with resolve-exception type
// resolve-exception with null index should follow resolve-exception rules
it('handles index null resolve-exception step', function (): void {
    $block = (string) Str::uuid();

    $defaultStep = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1, 'arguments' => ['fail' => true]],
    ], ScenarioTestJob::class)[0];

    $resolveStep = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => null, 'type' => 'resolve-exception'],
    ], ScenarioTestJob::class)[0];

    // Manually set to not-runnable
    $resolveStep->update(['state' => StepDispatcher\States\NotRunnable::class]);

    $statusMatrix = [
        1 => [$defaultStep->id => 'failed', $resolveStep->id => 'not-runnable'],
        2 => [$defaultStep->id => 'failed', $resolveStep->id => 'pending'], // Promoted
        3 => [$defaultStep->id => 'failed', $resolveStep->id => 'completed'], // Runs
    ];

    StepTester::withSteps([$defaultStep, $resolveStep])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('index_null_resolve_exception')
        ->test();
});

// Schematic: index=null in sequence with indexed steps
// Null should dispatch first, then indexed steps follow
it('dispatches index null first in mixed sequence', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1],
        ['block_uuid' => $block, 'index' => null],
        ['block_uuid' => $block, 'index' => 2],
    ], ScenarioTestJob::class);

    [$s1, $sNull, $s2] = $steps;

    $statusMatrix = [
        1 => [$s1->id => 'completed', $sNull->id => 'completed', $s2->id => 'pending'], // 1 and null together
        2 => [$s1->id => 'completed', $sNull->id => 'completed', $s2->id => 'completed'], // Then 2
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('index_null_in_mixed_sequence')
        ->test();
});

// Schematic: index=null step fails
// index=null failure doesn't block other steps from executing
it('does not block other steps when index null fails', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => null, 'arguments' => ['fail' => true]],
        ['block_uuid' => $block, 'index' => 1],
        ['block_uuid' => $block, 'index' => 2],
    ], ScenarioTestJob::class);

    [$sNull, $s1, $s2] = $steps;

    $statusMatrix = [
        1 => [$sNull->id => 'failed', $s1->id => 'completed', $s2->id => 'pending'], // sNull fails, s1 completes (parallel)
        2 => [$sNull->id => 'failed', $s1->id => 'completed', $s2->id => 'cancelled'], // s2 cancelled due to sNull failure
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('index_null_failure_does_not_block_others')
        ->test();
});

// Schematic: index=null orphan step
// Should dispatch immediately (no dependencies)
it('dispatches index null orphan step immediately', function (): void {
    $step = StepTester::createSteps([
        ['index' => null], // Orphan with null index
    ], ScenarioTestJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'completed'],
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('index_null_orphan_immediate_dispatch')
        ->test();
});

// Schematic: Multiple index=null at different positions in block
// All should dispatch together regardless of creation order
it('dispatches all index null steps together regardless of position', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1],
        ['block_uuid' => $block, 'index' => null],
        ['block_uuid' => $block, 'index' => 2],
        ['block_uuid' => $block, 'index' => null],
        ['block_uuid' => $block, 'index' => 3],
        ['block_uuid' => $block, 'index' => null],
    ], ScenarioTestJob::class);

    [$s1, $n1, $s2, $n2, $s3, $n3] = $steps;

    $statusMatrix = [
        1 => [
            $s1->id => 'completed',
            $n1->id => 'completed',
            $s2->id => 'pending',
            $n2->id => 'completed',
            $s3->id => 'pending',
            $n3->id => 'completed',
        ], // Index 1 and all nulls dispatch
        2 => [
            $s1->id => 'completed',
            $n1->id => 'completed',
            $s2->id => 'completed',
            $n2->id => 'completed',
            $s3->id => 'pending',
            $n3->id => 'completed',
        ], // Index 2
        3 => [
            $s1->id => 'completed',
            $n1->id => 'completed',
            $s2->id => 'completed',
            $n2->id => 'completed',
            $s3->id => 'completed',
            $n3->id => 'completed',
        ], // Index 3
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('multiple_index_null_various_positions')
        ->test();
});
