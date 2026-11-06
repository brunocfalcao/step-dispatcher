<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use StepDispatcher\Tests\Fixtures\ScenarioTestJob;
use StepDispatcher\Tests\Support\StepTester;

// Schematic: [∅]
// A single unindexed step should immediately run and complete.
// Fail the step to check cancellation.
it('fails a single step with no index or children', function (): void {
    $step = StepTester::createSteps([
        [/* no index or block, fail argument added */ 'arguments' => ['stop' => true]],
    ], ScenarioTestJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'stopped'],
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('single_step_no_index_fail')
        ->test();
});

// Schematic: 1 -> 2
// Two steps in sequence should run one after the other.
// Fail step 1 to ensure step 2 gets cancelled.
it('fails two sequential steps (index 1 → 2)', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1, 'arguments' => ['stop' => true]], // Fail step 1
        ['block_uuid' => $block, 'index' => 2],
    ], ScenarioTestJob::class);

    [$step1, $step2] = $steps;

    $statusMatrix = [
        1 => [$step1->id => 'stopped', $step2->id => 'pending'],
        2 => [$step1->id => 'stopped', $step2->id => 'cancelled'],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('sequential_steps_1_to_2_fail')
        ->test();
});

// Schematic: 1,1
// Two steps at the same index should run in parallel. Fail one step.
it('fails one parallel step at the same index', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1, 'arguments' => ['stop' => true]], // Fail step 1
        ['block_uuid' => $block, 'index' => 1],
    ], ScenarioTestJob::class);

    [$step1, $step2] = $steps;

    $statusMatrix = [
        1 => [$step1->id => 'stopped', $step2->id => 'completed'],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parallel_same_index_fail')
        ->test();
});

// Schematic: 1 -> 2,2 -> 3
// Mixed case: sequential step, then two parallel steps, then another sequential step.
// Fail step 2 in block 2 to test the cancellation of subsequent steps.
it('fails a sequence with parallel middle: 1 → (2 + 2) → 3', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1],
        ['block_uuid' => $block, 'index' => 2, 'arguments' => ['stop' => true]], // Fail step 2a
        ['block_uuid' => $block, 'index' => 2],
        ['block_uuid' => $block, 'index' => 3],
    ], ScenarioTestJob::class);

    [$s1, $s2a, $s2b, $s3] = $steps;

    $statusMatrix = [
        1 => [$s1->id => 'completed', $s2a->id => 'pending', $s2b->id => 'pending', $s3->id => 'pending'],
        2 => [$s1->id => 'completed', $s2a->id => 'stopped', $s2b->id => 'completed', $s3->id => 'pending'],
        3 => [$s1->id => 'completed', $s2a->id => 'stopped', $s2b->id => 'completed', $s3->id => 'cancelled'],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parallel_middle_sequence_fail')
        ->test();
});

// Schematic:
// Parent:     1
//             ↓
// ChildBlock: 1 -> 2
// A parent lifecycle step completes only after its child block completes.
// Stop child step 1 to check parent stopped and cancellation of step 2.
it('stops a lifecycle step after its child is stopped', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
    ], ScenarioTestJob::class)[0];

    $children = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1, 'arguments' => ['stop' => true]], // Stop child step 1
        ['block_uuid' => $childBlock, 'index' => 2],
    ], ScenarioTestJob::class);

    [$c1, $c2] = $children;

    $statusMatrix = [
        1 => [$parent->id => 'running', $c1->id => 'pending', $c2->id => 'pending'],
        2 => [$parent->id => 'running', $c1->id => 'stopped', $c2->id => 'pending'],
        3 => [$parent->id => 'running', $c1->id => 'stopped', $c2->id => 'cancelled'],
        4 => [$parent->id => 'stopped', $c1->id => 'stopped', $c2->id => 'cancelled'],
    ];

    StepTester::withSteps([$parent, ...$children])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('lifecycle_parent_after_children_stopped')
        ->test();
});

// Schematic:
// P1
//  ↓
// C1
//  ↓
// G1a [1], G1b [1] → G2 [2]
// A grandchild block with two parallel steps (index 1) followed by one step (index 2), in 3-level nesting.
// Stop the grandchild step G2 and check that parents cascade to stopped.
it('stops parallel + sequential grandchildren steps inside 3-level nesting', function (): void {
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
        ['block_uuid' => $block3, 'index' => 2, 'arguments' => ['stop' => true]], // Stop G2
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
            $G2->id => 'stopped',
        ],
        5 => [
            $P1->id => 'running',
            $C1->id => 'stopped',
            $G1a->id => 'completed',
            $G1b->id => 'completed',
            $G2->id => 'stopped',
        ],
        6 => [
            $P1->id => 'stopped',
            $C1->id => 'stopped',
            $G1a->id => 'completed',
            $G1b->id => 'completed',
            $G2->id => 'stopped',
        ],
    ];

    StepTester::withSteps([$P1, $C1, $G1a, $G1b, $G2])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('nested_parallel_grandchildren_with_sequence_stopped')
        ->test();
});

// Verify that parent gets error_message when child step is stopped
it('sets error_message on parent when child step is stopped', function (): void {
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
        2 => [$parent->id => 'running', $child->id => 'stopped'],
        3 => [$parent->id => 'stopped', $child->id => 'stopped'],
    ];

    StepTester::withSteps([$parent, $child])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parent_gets_error_message_when_child_stopped')
        ->test();

    // Verify error_message is set on the parent step
    $parent->refresh();

    // Parent should have error_message indicating which child was stopped
    expect($parent->error_message)->not->toBeNull();
    expect($parent->error_message)->toContain('Child step(s) stopped:');
    expect($parent->error_message)->toContain((string) $child->id);
});

// Verify that pending children are cancelled (not failed) when parent is stopped
it('cancels pending children when parent is stopped', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock, 'arguments' => ['stop' => true]],
    ], ScenarioTestJob::class)[0];

    $children = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1],
        ['block_uuid' => $childBlock, 'index' => 2],
    ], ScenarioTestJob::class);

    [$c1, $c2] = $children;

    $statusMatrix = [
        1 => [$parent->id => 'stopped', $c1->id => 'pending', $c2->id => 'pending'],
        2 => [$parent->id => 'stopped', $c1->id => 'cancelled', $c2->id => 'cancelled'],
    ];

    StepTester::withSteps([$parent, ...$children])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('pending_children_cancelled_when_parent_stopped')
        ->test();
});

// Verify recursive cancellation - grandchildren also get cancelled when parent stops
it('cancels grandchildren when parent is stopped', function (): void {
    $block1 = (string) Str::uuid();
    $block2 = (string) Str::uuid();
    $block3 = (string) Str::uuid();

    // P1 stops immediately
    $P1 = StepTester::createSteps([
        ['block_uuid' => $block1, 'index' => 1, 'child_block_uuid' => $block2, 'arguments' => ['stop' => true]],
    ], ScenarioTestJob::class)[0];

    // C1 is a parent with grandchildren
    $C1 = StepTester::createSteps([
        ['block_uuid' => $block2, 'index' => 1, 'child_block_uuid' => $block3],
    ], ScenarioTestJob::class)[0];

    // Grandchildren
    [$G1, $G2] = StepTester::createSteps([
        ['block_uuid' => $block3, 'index' => 1],
        ['block_uuid' => $block3, 'index' => 2],
    ], ScenarioTestJob::class);

    $statusMatrix = [
        1 => [
            $P1->id => 'stopped',
            $C1->id => 'pending',
            $G1->id => 'pending',
            $G2->id => 'pending',
        ],
        2 => [
            $P1->id => 'stopped',
            $C1->id => 'cancelled',
            $G1->id => 'pending',
            $G2->id => 'pending',
        ],
        3 => [
            $P1->id => 'stopped',
            $C1->id => 'cancelled',
            $G1->id => 'cancelled',
            $G2->id => 'cancelled',
        ],
    ];

    StepTester::withSteps([$P1, $C1, $G1, $G2])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('grandchildren_cancelled_when_parent_stopped')
        ->test();
});
