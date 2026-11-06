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
        [/* no index or block, fail argument added */ 'arguments' => ['fail' => true]],
    ], ScenarioTestJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'failed'],
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
        ['block_uuid' => $block, 'index' => 1, 'arguments' => ['fail' => true]], // Fail step 1
        ['block_uuid' => $block, 'index' => 2],
    ], ScenarioTestJob::class);

    [$step1, $step2] = $steps;

    $statusMatrix = [
        1 => [$step1->id => 'failed', $step2->id => 'pending'],
        2 => [$step1->id => 'failed', $step2->id => 'cancelled'],
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
        ['block_uuid' => $block, 'index' => 1, 'arguments' => ['fail' => true]], // Fail step 1
        ['block_uuid' => $block, 'index' => 1],
    ], ScenarioTestJob::class);

    [$step1, $step2] = $steps;

    $statusMatrix = [
        1 => [$step1->id => 'failed', $step2->id => 'completed'],
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
        ['block_uuid' => $block, 'index' => 2, 'arguments' => ['fail' => true]], // Fail step 2a
        ['block_uuid' => $block, 'index' => 2],
        ['block_uuid' => $block, 'index' => 3],
    ], ScenarioTestJob::class);

    [$s1, $s2a, $s2b, $s3] = $steps;

    $statusMatrix = [
        1 => [$s1->id => 'completed', $s2a->id => 'pending', $s2b->id => 'pending', $s3->id => 'pending'],
        2 => [$s1->id => 'completed', $s2a->id => 'failed', $s2b->id => 'completed', $s3->id => 'pending'],
        3 => [$s1->id => 'completed', $s2a->id => 'failed', $s2b->id => 'completed', $s3->id => 'cancelled'],
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
// Fail child step 1 to check parent failure and cancellation of step 2.
it('fails a lifecycle step after its children complete', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
    ], ScenarioTestJob::class)[0];

    $children = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1, 'arguments' => ['fail' => true]], // Fail child step 1
        ['block_uuid' => $childBlock, 'index' => 2],
    ], ScenarioTestJob::class);

    [$c1, $c2] = $children;

    $statusMatrix = [
        1 => [$parent->id => 'running', $c1->id => 'pending', $c2->id => 'pending'],
        2 => [$parent->id => 'running', $c1->id => 'failed', $c2->id => 'pending'],
        3 => [$parent->id => 'running', $c1->id => 'failed', $c2->id => 'cancelled'],
        4 => [$parent->id => 'failed', $c1->id => 'failed', $c2->id => 'cancelled'],
    ];

    StepTester::withSteps([$parent, ...$children])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('lifecycle_parent_after_children_fail')
        ->test();
});

// Schematic:
// P1
//  ↓
// C1
//  ↓
// G1a [2], G1b [2] → G2 [3]
// A grandchild block with two parallel steps (index 2) followed by one step (index 3), in 3-level nesting.
// Fail the grandchild step G1a and check cancellation of G1b and G2.
it('fails parallel + sequential grandchildren steps inside 3-level nesting', function (): void {
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
        ['block_uuid' => $block3, 'index' => 2, 'arguments' => ['fail' => true]], // Fail G1a
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
            $G2->id => 'failed',
        ],
        5 => [
            $P1->id => 'running',
            $C1->id => 'failed',
            $G1a->id => 'completed',
            $G1b->id => 'completed',
            $G2->id => 'failed',
        ],
        6 => [
            $P1->id => 'failed',
            $C1->id => 'failed',
            $G1a->id => 'completed',
            $G1b->id => 'completed',
            $G2->id => 'failed',
        ],
    ];

    StepTester::withSteps([$P1, $C1, $G1a, $G1b, $G2])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('nested_parallel_grandchildren_with_sequence_fail')
        ->test();
});

// Schematic:
// Parent:     1
//             ↓
// ChildBlock: 1
// When a child step fails, the parent step should receive an error_message
// indicating which child step failed.
it('sets error_message on parent when child step fails', function (): void {
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
        2 => [$parent->id => 'running', $child->id => 'failed'],
        3 => [$parent->id => 'failed', $child->id => 'failed'],
    ];

    StepTester::withSteps([$parent, $child])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parent_gets_error_message_when_child_fails')
        ->test();

    // Verify error_message is set on the parent step
    $parent->refresh();

    // Parent should have error_message indicating which child failed
    expect($parent->error_message)->not->toBeNull();
    expect($parent->error_message)->toContain('Child step(s) failed:');
    expect($parent->error_message)->toContain((string) $child->id);
});

// Schematic:
// Parent:     1
//             ↓
// ChildBlock: 1
// When a child step is stopped (via shouldStartOrStop), the parent step should
// receive an error_message indicating which child step was stopped.
// NOTE: Parent becomes 'stopped' (not 'failed') when child is stopped.
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
    $child->refresh();

    // Parent should have error_message indicating which child was stopped
    expect($parent->error_message)->not->toBeNull();
    expect($parent->error_message)->toContain('Child step(s) stopped:');
    expect($parent->error_message)->toContain((string) $child->id);
});
