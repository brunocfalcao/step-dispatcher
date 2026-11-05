<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use StepDispatcher\Tests\Support\StepTester;
use StepDispatcher\Tests\Fixtures\ScenarioTestJob;


// Schematic: P1 -> C1 (child fails)
// When a child fails, the parent should transition to Failed
it('fails parent when child fails', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
    ], ScenarioTestJob::class)[0];

    $child = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1, 'arguments' => ['fail' => true]],
    ], ScenarioTestJob::class)[0];

    $statusMatrix = [
        1 => [$parent->id => 'running', $child->id => 'pending'],
        2 => [$parent->id => 'running', $child->id => 'failed'], // Child fails
        3 => [$parent->id => 'failed', $child->id => 'failed'], // Parent fails due to child
    ];

    StepTester::withSteps([$parent, $child])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parent_fails_when_child_fails')
        ->test();
});

// Schematic: P1 -> C1 -> G1 (grandchild fails)
// When a grandchild fails, both child and parent should fail
it('fails grandparent and parent when grandchild fails', function (): void {
    $block1 = (string) Str::uuid();
    $block2 = (string) Str::uuid();
    $block3 = (string) Str::uuid();

    $grandparent = StepTester::createSteps([
        ['block_uuid' => $block1, 'index' => 1, 'child_block_uuid' => $block2],
    ], ScenarioTestJob::class)[0];

    $parent = StepTester::createSteps([
        ['block_uuid' => $block2, 'index' => 1, 'child_block_uuid' => $block3],
    ], ScenarioTestJob::class)[0];

    $grandchild = StepTester::createSteps([
        ['block_uuid' => $block3, 'index' => 1, 'arguments' => ['fail' => true]],
    ], ScenarioTestJob::class)[0];

    $statusMatrix = [
        1 => [$grandparent->id => 'running', $parent->id => 'pending', $grandchild->id => 'pending'],
        2 => [$grandparent->id => 'running', $parent->id => 'running', $grandchild->id => 'pending'],
        3 => [$grandparent->id => 'running', $parent->id => 'running', $grandchild->id => 'failed'],
        4 => [$grandparent->id => 'running', $parent->id => 'failed', $grandchild->id => 'failed'], // Parent fails
        5 => [$grandparent->id => 'failed', $parent->id => 'failed', $grandchild->id => 'failed'], // Grandparent fails
    ];

    StepTester::withSteps([$grandparent, $parent, $grandchild])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('grandparent_fails_on_grandchild_failure')
        ->test();
});

// Schematic: P1 -> C1, C2 (one child fails, other is pending)
// When one child fails, parent should fail, and pending sibling should be cancelled
it('cancels pending sibling when one child fails', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
    ], ScenarioTestJob::class)[0];

    $children = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1, 'arguments' => ['fail' => true]], // Fails
        ['block_uuid' => $childBlock, 'index' => 2], // Pending, should be cancelled
    ], ScenarioTestJob::class);

    [$c1, $c2] = $children;

    $statusMatrix = [
        1 => [$parent->id => 'running', $c1->id => 'pending', $c2->id => 'pending'],
        2 => [$parent->id => 'running', $c1->id => 'failed', $c2->id => 'pending'],
        3 => [$parent->id => 'running', $c1->id => 'failed', $c2->id => 'cancelled'], // c2 cancelled immediately when c1 fails
        4 => [$parent->id => 'failed', $c1->id => 'failed', $c2->id => 'cancelled'], // Parent fails after checking child block
    ];

    StepTester::withSteps([$parent, $c1, $c2])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('sibling_cancelled_on_child_failure')
        ->test();
});

// Schematic: P1 -> C1,C1 (parallel children, one fails)
// Both parallel children at same index, one fails - parent should fail
it('fails parent when one of parallel children fails', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
    ], ScenarioTestJob::class)[0];

    $children = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1, 'arguments' => ['fail' => true]], // Fails
        ['block_uuid' => $childBlock, 'index' => 1], // Completes
    ], ScenarioTestJob::class);

    [$c1, $c2] = $children;

    $statusMatrix = [
        1 => [$parent->id => 'running', $c1->id => 'pending', $c2->id => 'pending'],
        2 => [$parent->id => 'running', $c1->id => 'failed', $c2->id => 'completed'], // One fails, one completes
        3 => [$parent->id => 'failed', $c1->id => 'failed', $c2->id => 'completed'], // Parent fails
    ];

    StepTester::withSteps([$parent, $c1, $c2])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parent_fails_parallel_child_fails')
        ->test();
});

// Schematic: P1 -> C1 (child stops)
// Stopped child should cause parent to stop (not fail)
it('stops parent when child stops', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
    ], ScenarioTestJob::class)[0];

    $child = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1, 'arguments' => ['stop' => true]],
    ], ScenarioTestJob::class)[0];

    $statusMatrix = [
        1 => [$parent->id => 'running', $child->id => 'pending'],
        2 => [$parent->id => 'running', $child->id => 'stopped'], // Child stops
        3 => [$parent->id => 'stopped', $child->id => 'stopped'], // Parent stops (propagates stopped, not failed)
    ];

    StepTester::withSteps([$parent, $child])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parent_stops_when_child_stops')
        ->test();
});

// Schematic: 1 (fails) -> 2, 3, 4 (all should be cancelled)
// Downstream cancellation should cancel all subsequent steps
it('cancels all downstream steps when one step fails', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1, 'arguments' => ['fail' => true]], // Fails
        ['block_uuid' => $block, 'index' => 2],
        ['block_uuid' => $block, 'index' => 3],
        ['block_uuid' => $block, 'index' => 4],
    ], ScenarioTestJob::class);

    [$s1, $s2, $s3, $s4] = $steps;

    $statusMatrix = [
        1 => [$s1->id => 'failed', $s2->id => 'pending', $s3->id => 'pending', $s4->id => 'pending'],
        2 => [$s1->id => 'failed', $s2->id => 'cancelled', $s3->id => 'cancelled', $s4->id => 'cancelled'], // All cancelled
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('downstream_cancellation_all')
        ->test();
});

// Schematic: 1 -> 2 (fails) -> 3 (should be cancelled, not 1)
// Only downstream steps should be cancelled, not upstream
it('cancels only downstream steps not upstream', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1], // Completes
        ['block_uuid' => $block, 'index' => 2, 'arguments' => ['fail' => true]], // Fails
        ['block_uuid' => $block, 'index' => 3], // Should be cancelled
    ], ScenarioTestJob::class);

    [$s1, $s2, $s3] = $steps;

    $statusMatrix = [
        1 => [$s1->id => 'completed', $s2->id => 'pending', $s3->id => 'pending'],
        2 => [$s1->id => 'completed', $s2->id => 'failed', $s3->id => 'pending'],
        3 => [$s1->id => 'completed', $s2->id => 'failed', $s3->id => 'cancelled'], // Only s3 cancelled
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('cancellation_only_downstream')
        ->test();
});

// Schematic: P1 (fails) -> C1, C2 (both should be cancelled due to parent failure cascade)
// When parent fails directly, all non-terminal children should be cancelled (not failed)
it('cancels all children when parent fails', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock, 'arguments' => ['fail' => true]],
    ], ScenarioTestJob::class)[0];

    $children = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1],
        ['block_uuid' => $childBlock, 'index' => 2],
    ], ScenarioTestJob::class);

    [$c1, $c2] = $children;

    $statusMatrix = [
        1 => [$parent->id => 'failed', $c1->id => 'pending', $c2->id => 'pending'], // Parent fails immediately
        2 => [$parent->id => 'failed', $c1->id => 'cancelled', $c2->id => 'cancelled'], // Children cancelled
    ];

    StepTester::withSteps([$parent, $c1, $c2])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('children_cancelled_when_parent_fails')
        ->test();
});

// Schematic: P1 -> C1 (running) -> G1 (fails)
// When grandchild fails while child is still running, propagation should happen correctly
it('propagates failure through running intermediaries', function (): void {
    $block1 = (string) Str::uuid();
    $block2 = (string) Str::uuid();
    $block3 = (string) Str::uuid();

    $grandparent = StepTester::createSteps([
        ['block_uuid' => $block1, 'index' => 1, 'child_block_uuid' => $block2],
    ], ScenarioTestJob::class)[0];

    $parent = StepTester::createSteps([
        ['block_uuid' => $block2, 'index' => 1, 'child_block_uuid' => $block3],
    ], ScenarioTestJob::class)[0];

    $grandchildren = StepTester::createSteps([
        ['block_uuid' => $block3, 'index' => 1, 'arguments' => ['fail' => true]],
        ['block_uuid' => $block3, 'index' => 2], // Should not run
    ], ScenarioTestJob::class);

    [$gc1, $gc2] = $grandchildren;

    $statusMatrix = [
        1 => [
            $grandparent->id => 'running',
            $parent->id => 'pending',
            $gc1->id => 'pending',
            $gc2->id => 'pending',
        ],
        2 => [
            $grandparent->id => 'running',
            $parent->id => 'running', // Parent becomes running
            $gc1->id => 'pending',
            $gc2->id => 'pending',
        ],
        3 => [
            $grandparent->id => 'running',
            $parent->id => 'running',
            $gc1->id => 'failed', // Grandchild fails
            $gc2->id => 'pending',
        ],
        4 => [
            $grandparent->id => 'running',
            $parent->id => 'running', // Parent still running, hasn't detected failure yet
            $gc1->id => 'failed',
            $gc2->id => 'cancelled', // gc2 cancelled immediately when gc1 fails
        ],
        5 => [
            $grandparent->id => 'running',
            $parent->id => 'failed', // Parent detects child failure
            $gc1->id => 'failed',
            $gc2->id => 'cancelled',
        ],
        6 => [
            $grandparent->id => 'failed', // Grandparent detects parent failure
            $parent->id => 'failed',
            $gc1->id => 'failed',
            $gc2->id => 'cancelled',
        ],
    ];

    StepTester::withSteps([$grandparent, $parent, $gc1, $gc2])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('failure_propagates_through_running_chain')
        ->test();
});

// Schematic: 1,1,1 (parallel, middle one fails) -> 2 (should be cancelled)
// When one of parallel steps fails, downstream should still be cancelled
it('cancels downstream when parallel step fails', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1], // Completes
        ['block_uuid' => $block, 'index' => 1, 'arguments' => ['fail' => true]], // Fails
        ['block_uuid' => $block, 'index' => 1], // Completes
        ['block_uuid' => $block, 'index' => 2], // Should be cancelled
    ], ScenarioTestJob::class);

    [$s1a, $s1b, $s1c, $s2] = $steps;

    $statusMatrix = [
        1 => [
            $s1a->id => 'completed',
            $s1b->id => 'failed',
            $s1c->id => 'completed',
            $s2->id => 'pending',
        ],
        2 => [
            $s1a->id => 'completed',
            $s1b->id => 'failed',
            $s1c->id => 'completed',
            $s2->id => 'cancelled', // Cancelled due to failure
        ],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('downstream_cancelled_parallel_failure')
        ->test();
});

// Schematic: P1 -> C1 (fails), C2 (parent with grandchildren) [parallel at same index]
// When one parallel child fails, parent should wait for ALL siblings to reach terminal state
// Bug: Parent was failing immediately when one parallel child failed, even if others were still running
//
// This test simulates the real scenario:
// - C1 fails immediately
// - C2 is a parent step with grandchildren, so it stays in Running while grandchildren execute
// - Parent should NOT fail until C2 also reaches terminal state
it('waits for all parallel children before failing parent', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();
    $grandchildBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
    ], ScenarioTestJob::class)[0];

    // Two parallel children at same index:
    // - c1 fails immediately
    // - c2 is a parent step (has grandchildren), so it will stay Running
    $children = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1, 'arguments' => ['fail' => true]], // Fails immediately
        ['block_uuid' => $childBlock, 'index' => 1, 'child_block_uuid' => $grandchildBlock], // Has children, stays Running
    ], ScenarioTestJob::class);

    [$c1, $c2] = $children;

    // Grandchild that will complete normally
    $grandchild = StepTester::createSteps([
        ['block_uuid' => $grandchildBlock, 'index' => 1],
    ], ScenarioTestJob::class)[0];

    // Expected flow:
    // Tick 1: Parent becomes Running, dispatches c1 and c2 (both at idx 1)
    // Tick 2: c1 fails, c2 becomes Running (waiting for grandchild)
    //         Parent should STILL be Running because c2 is not terminal yet!
    // Tick 3: Grandchild completes
    // Tick 4: c2 completes (all its children done)
    // Tick 5: NOW parent can fail (both c1 and c2 are terminal)
    $statusMatrix = [
        1 => [$parent->id => 'running', $c1->id => 'pending', $c2->id => 'pending', $grandchild->id => 'pending'],
        2 => [$parent->id => 'running', $c1->id => 'failed', $c2->id => 'running', $grandchild->id => 'pending'],
        3 => [$parent->id => 'running', $c1->id => 'failed', $c2->id => 'running', $grandchild->id => 'completed'],
        4 => [$parent->id => 'running', $c1->id => 'failed', $c2->id => 'completed', $grandchild->id => 'completed'],
        5 => [$parent->id => 'failed', $c1->id => 'failed', $c2->id => 'completed', $grandchild->id => 'completed'],
    ];

    StepTester::withSteps([$parent, $c1, $c2, $grandchild])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parallel_children_wait_for_all_terminal')
        ->test();
});

// Schematic: 1 -> 2 -> 3 (fails) -> 4 -> 5 (parent with children) -> 6
// When step 3 fails, downstream parent (step 5) and its children should all be cancelled
it('cancels downstream parent and its children when upstream step fails', function (): void {
    $mainBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $mainBlock, 'index' => 1],
        ['block_uuid' => $mainBlock, 'index' => 2],
        ['block_uuid' => $mainBlock, 'index' => 3, 'arguments' => ['fail' => true]], // Fails
        ['block_uuid' => $mainBlock, 'index' => 4],
        ['block_uuid' => $mainBlock, 'index' => 5, 'child_block_uuid' => $childBlock], // Parent step
        ['block_uuid' => $mainBlock, 'index' => 6],
    ], ScenarioTestJob::class);

    $children = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1],
        ['block_uuid' => $childBlock, 'index' => 2],
    ], ScenarioTestJob::class);

    [$s1, $s2, $s3, $s4, $s5, $s6] = $steps;
    [$c1, $c2] = $children;

    $statusMatrix = [
        1 => [
            $s1->id => 'completed',
            $s2->id => 'pending',
            $s3->id => 'pending',
            $s4->id => 'pending',
            $s5->id => 'pending',
            $s6->id => 'pending',
            $c1->id => 'pending',
            $c2->id => 'pending',
        ],
        2 => [
            $s1->id => 'completed',
            $s2->id => 'completed',
            $s3->id => 'pending',
            $s4->id => 'pending',
            $s5->id => 'pending',
            $s6->id => 'pending',
            $c1->id => 'pending',
            $c2->id => 'pending',
        ],
        3 => [
            $s1->id => 'completed',
            $s2->id => 'completed',
            $s3->id => 'failed', // Step 3 fails
            $s4->id => 'pending',
            $s5->id => 'pending',
            $s6->id => 'pending',
            $c1->id => 'pending',
            $c2->id => 'pending',
        ],
        4 => [
            $s1->id => 'completed',
            $s2->id => 'completed',
            $s3->id => 'failed',
            $s4->id => 'cancelled', // Downstream steps cancelled
            $s5->id => 'cancelled', // Parent cancelled
            $s6->id => 'cancelled',
            $c1->id => 'cancelled', // Children also cancelled
            $c2->id => 'cancelled',
        ],
    ];

    StepTester::withSteps([$s1, $s2, $s3, $s4, $s5, $s6, $c1, $c2])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('downstream_parent_and_children_cancelled')
        ->test();
});
