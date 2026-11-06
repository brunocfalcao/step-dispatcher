<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\NotRunnable;
use StepDispatcher\Tests\Fixtures\ScenarioTestJob;
use StepDispatcher\Tests\Support\StepTester;

// Schematic: 1 (default, fails) + resolve-exception (no index)
// When a default step fails, resolve-exception with no index should be promoted to Pending
it('promotes resolve-exception without index when default step fails', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1, 'arguments' => ['fail' => true]], // Fails
        ['block_uuid' => $block, 'index' => null, 'type' => 'resolve-exception'], // Should be promoted
    ], ScenarioTestJob::class);

    [$defaultStep, $resolveStep] = $steps;

    // Manually set resolve-exception to NotRunnable (observer would do this, but we need to force it)
    $resolveStep->update(['state' => NotRunnable::class]);

    $statusMatrix = [
        1 => [$defaultStep->id => 'failed', $resolveStep->id => 'not-runnable'],
        2 => [$defaultStep->id => 'failed', $resolveStep->id => 'pending'], // Promoted!
        3 => [$defaultStep->id => 'failed', $resolveStep->id => 'completed'], // Runs and completes
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('resolve_exception_promoted')
        ->test();
});

// Schematic: 1 (default, fails) + resolve-exception (index 1)
// resolve-exception with index 1 should dispatch when previous default step fails
it('runs resolve-exception with index 1 after failure', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1, 'arguments' => ['fail' => true]], // Fails
        ['block_uuid' => $block, 'index' => 1, 'type' => 'resolve-exception'], // Should run after promotion
    ], ScenarioTestJob::class);

    [$defaultStep, $resolveStep] = $steps;

    $resolveStep->update(['state' => NotRunnable::class]);

    $statusMatrix = [
        1 => [$defaultStep->id => 'failed', $resolveStep->id => 'not-runnable'],
        2 => [$defaultStep->id => 'failed', $resolveStep->id => 'pending'],
        3 => [$defaultStep->id => 'failed', $resolveStep->id => 'completed'],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('resolve_exception_index_1')
        ->test();
});

// Schematic: resolve-exception (index 1) -> resolve-exception (index 2)
// Sequential resolve-exception steps should follow index order
it('runs sequential resolve-exception steps in order', function (): void {
    $block = (string) Str::uuid();

    // First create a failing default step to trigger resolve-exception promotion
    $defaultStep = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1, 'arguments' => ['fail' => true]],
    ], ScenarioTestJob::class)[0];

    $resolveSteps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1, 'type' => 'resolve-exception'],
        ['block_uuid' => $block, 'index' => 2, 'type' => 'resolve-exception'],
    ], ScenarioTestJob::class);

    [$r1, $r2] = $resolveSteps;

    $r1->update(['state' => NotRunnable::class]);
    $r2->update(['state' => NotRunnable::class]);

    $statusMatrix = [
        1 => [$defaultStep->id => 'failed', $r1->id => 'not-runnable', $r2->id => 'not-runnable'],
        2 => [$defaultStep->id => 'failed', $r1->id => 'pending', $r2->id => 'pending'], // BOTH promoted (all resolve-exception steps promoted at once)
        3 => [$defaultStep->id => 'failed', $r1->id => 'completed', $r2->id => 'pending'], // r1 completes, r2 waits (r2 has higher index)
        4 => [$defaultStep->id => 'failed', $r1->id => 'completed', $r2->id => 'completed'], // r2 done
    ];

    StepTester::withSteps([$defaultStep, $r1, $r2])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('sequential_resolve_exceptions')
        ->test();
});

// Schematic: 1 (default, completes) + resolve-exception (no index)
// If no failures, resolve-exception should NOT be promoted
it('does not promote resolve-exception when no failures', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1], // Completes successfully
        ['block_uuid' => $block, 'index' => null, 'type' => 'resolve-exception'],
    ], ScenarioTestJob::class);

    [$defaultStep, $resolveStep] = $steps;

    $resolveStep->update(['state' => NotRunnable::class]);

    $statusMatrix = [
        1 => [$defaultStep->id => 'completed', $resolveStep->id => 'not-runnable'],
        2 => [$defaultStep->id => 'completed', $resolveStep->id => 'not-runnable'], // Not promoted
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('resolve_exception_not_promoted_no_failure')
        ->test();
});

// Schematic: 1 (default, stopped) + resolve-exception
// Stopped counts as failure, so resolve-exception should be promoted
it('promotes resolve-exception when step is stopped', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1, 'arguments' => ['stop' => true]], // Stops
        ['block_uuid' => $block, 'index' => null, 'type' => 'resolve-exception'],
    ], ScenarioTestJob::class);

    [$defaultStep, $resolveStep] = $steps;

    $resolveStep->update(['state' => NotRunnable::class]);

    $statusMatrix = [
        1 => [$defaultStep->id => 'stopped', $resolveStep->id => 'not-runnable'],
        2 => [$defaultStep->id => 'stopped', $resolveStep->id => 'pending'], // Promoted!
        3 => [$defaultStep->id => 'stopped', $resolveStep->id => 'completed'],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('resolve_exception_on_stopped')
        ->test();
});

// Schematic: Parent (lifecycle) -> Child (fails) + resolve-exception in child block
// resolve-exception in child block should be promoted and COMPLETE before parent fails
it('promotes resolve-exception in child block when child fails', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
    ], ScenarioTestJob::class)[0];

    $children = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1, 'arguments' => ['fail' => true]], // Fails
        ['block_uuid' => $childBlock, 'index' => null, 'type' => 'resolve-exception'],
    ], ScenarioTestJob::class);

    [$childFail, $resolveStep] = $children;

    $resolveStep->update(['state' => NotRunnable::class]);

    $statusMatrix = [
        1 => [
            $parent->id => 'running',
            $childFail->id => 'pending',
            $resolveStep->id => 'not-runnable',
        ],
        2 => [
            $childFail->id => 'failed',
        ],
        3 => [
            $resolveStep->id => 'pending', // Promoted!
        ],
        4 => [
            $resolveStep->id => 'completed', // Resolve completes FIRST
        ],
        5 => [
            $parent->id => 'failed', // NOW parent fails (after resolve-exception completed)
        ],
    ];

    StepTester::withSteps([$parent, $childFail, $resolveStep])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('resolve_exception_in_child_block')
        ->test();
});

// Schematic: 1,1 (both default, both fail) + resolve-exception
// Multiple failures should still promote resolve-exception once
it('promotes resolve-exception with multiple failures at same index', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1, 'arguments' => ['fail' => true]], // Fails
        ['block_uuid' => $block, 'index' => 1, 'arguments' => ['fail' => true]], // Also fails
        ['block_uuid' => $block, 'index' => null, 'type' => 'resolve-exception'],
    ], ScenarioTestJob::class);

    [$d1, $d2, $resolveStep] = $steps;

    $resolveStep->update(['state' => NotRunnable::class]);

    $statusMatrix = [
        1 => [$d1->id => 'failed', $d2->id => 'failed', $resolveStep->id => 'not-runnable'],
        2 => [$d1->id => 'failed', $d2->id => 'failed', $resolveStep->id => 'pending'], // Promoted
        3 => [$d1->id => 'failed', $d2->id => 'failed', $resolveStep->id => 'completed'],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('resolve_exception_multiple_failures')
        ->test();
});

// Schematic: 1 (default, cancelled) + resolve-exception
// Cancelled step should NOT trigger resolve-exception promotion
it('does not promote resolve-exception when step is cancelled', function (): void {
    $block = (string) Str::uuid();

    // Create steps but manually set first to cancelled
    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1],
        ['block_uuid' => $block, 'index' => null, 'type' => 'resolve-exception'],
    ], ScenarioTestJob::class);

    [$defaultStep, $resolveStep] = $steps;

    // Manually cancel the default step
    $defaultStep->update(['state' => Cancelled::class]);
    $resolveStep->update(['state' => NotRunnable::class]);

    $statusMatrix = [
        1 => [$defaultStep->id => 'cancelled', $resolveStep->id => 'not-runnable'],
        2 => [$defaultStep->id => 'cancelled', $resolveStep->id => 'not-runnable'], // NOT promoted
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('resolve_exception_not_promoted_on_cancelled')
        ->test();
});

// Schematic: Parent block (1-6 + resolve-exception) -> Step 5 (parent) -> Child block (child steps + resolve-exception)
// When child fails, child's resolve-exception runs FIRST, then parent fails, then parent's resolve-exception runs.
// This ensures error handlers in child blocks complete before cascading failure up.
it('promotes resolve-exception in both child and parent blocks when child fails', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    // Parent block: 6 steps with step 5 being a parent
    $parentSteps = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1],
        ['block_uuid' => $parentBlock, 'index' => 2],
        ['block_uuid' => $parentBlock, 'index' => 3],
        ['block_uuid' => $parentBlock, 'index' => 4],
        ['block_uuid' => $parentBlock, 'index' => 5, 'child_block_uuid' => $childBlock], // Parent step
        ['block_uuid' => $parentBlock, 'index' => 6],
        ['block_uuid' => $parentBlock, 'index' => null, 'type' => 'resolve-exception'], // Parent block resolve-exception
    ], ScenarioTestJob::class);

    // Child block: multiple steps, 2nd one fails + resolve-exception
    $childSteps = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1],
        ['block_uuid' => $childBlock, 'index' => 2, 'arguments' => ['fail' => true]], // Fails
        ['block_uuid' => $childBlock, 'index' => 3],
        ['block_uuid' => $childBlock, 'index' => null, 'type' => 'resolve-exception'], // Child block resolve-exception
    ], ScenarioTestJob::class);

    [$s1, $s2, $s3, $s4, $s5, $s6, $parentResolve] = $parentSteps;
    [$c1, $c2, $c3, $childResolve] = $childSteps;

    // Set resolve-exceptions to not-runnable
    $parentResolve->update(['state' => NotRunnable::class]);
    $childResolve->update(['state' => NotRunnable::class]);

    $statusMatrix = [
        1 => [
            $s1->id => 'completed',
            $s2->id => 'pending',
            $s3->id => 'pending',
            $s4->id => 'pending',
            $s5->id => 'pending',
            $s6->id => 'pending',
            $parentResolve->id => 'not-runnable',
            $c1->id => 'pending',
            $c2->id => 'pending',
            $c3->id => 'pending',
            $childResolve->id => 'not-runnable',
        ],
        2 => [
            $s2->id => 'completed',
        ],
        3 => [
            $s3->id => 'completed',
        ],
        4 => [
            $s4->id => 'completed',
        ],
        5 => [
            $s5->id => 'running', // Parent becomes running
        ],
        6 => [
            $c1->id => 'completed', // c1 completes
        ],
        7 => [
            $c2->id => 'failed', // c2 fails
        ],
        8 => [
            $c3->id => 'cancelled', // c3 cancelled (downstream of failure)
        ],
        9 => [
            $childResolve->id => 'pending', // Child resolve-exception promoted!
        ],
        10 => [
            $childResolve->id => 'completed', // Child resolve-exception completes FIRST (before parent fails)
        ],
        11 => [
            $s5->id => 'failed', // NOW parent fails (after child resolve-exception completed)
        ],
        12 => [
            $s6->id => 'cancelled', // s6 cancelled (downstream of parent failure)
        ],
        13 => [
            $parentResolve->id => 'pending', // Parent resolve-exception promoted!
        ],
        14 => [
            $parentResolve->id => 'completed', // Parent resolve-exception completes
        ],
    ];

    StepTester::withSteps([$s1, $s2, $s3, $s4, $s5, $s6, $parentResolve, $c1, $c2, $c3, $childResolve])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('resolve_exception_in_both_parent_and_child_blocks')
        ->test();
});

// Schematic: Parent -> Child (fails) + 3 parallel resolve-exceptions (same index, one skipped)
// When one resolve-exception is skipped, parent should wait only for non-terminal ones before failing
it('handles skipped resolve-exception among parallel resolve-exceptions in child block', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
    ], ScenarioTestJob::class)[0];

    // Child block: failing step + 3 parallel resolve-exceptions at same index
    $children = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1, 'arguments' => ['fail' => true]], // Fails
        ['block_uuid' => $childBlock, 'index' => 1, 'type' => 'resolve-exception'], // r1: completes
        ['block_uuid' => $childBlock, 'index' => 1, 'type' => 'resolve-exception', 'arguments' => ['skip' => true]], // r2: skipped
        ['block_uuid' => $childBlock, 'index' => 1, 'type' => 'resolve-exception'], // r3: completes
    ], ScenarioTestJob::class);

    [$childFail, $r1, $r2, $r3] = $children;

    $r1->update(['state' => NotRunnable::class]);
    $r2->update(['state' => NotRunnable::class]);
    $r3->update(['state' => NotRunnable::class]);

    $statusMatrix = [
        1 => [
            $parent->id => 'running',
            $childFail->id => 'pending',
            $r1->id => 'not-runnable',
            $r2->id => 'not-runnable',
            $r3->id => 'not-runnable',
        ],
        2 => [
            $childFail->id => 'failed',
        ],
        3 => [
            // All 3 resolve-exceptions promoted to pending
            $r1->id => 'pending',
            $r2->id => 'pending',
            $r3->id => 'pending',
        ],
        4 => [
            // r1 completes, r2 skipped, r3 completes (all parallel at same index)
            $r1->id => 'completed',
            $r2->id => 'skipped', // Skipped is terminal - doesn't block parent
            $r3->id => 'completed',
        ],
        5 => [
            // NOW parent fails (all resolve-exceptions are terminal)
            $parent->id => 'failed',
        ],
    ];

    StepTester::withSteps([$parent, $childFail, $r1, $r2, $r3])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parallel_resolve_exceptions_one_skipped')
        ->test();
});

// Schematic: Parent -> Child (fails) + 3 sequential resolve-exceptions (indexes 1,2,3, middle one skipped)
// When middle resolve-exception is skipped, remaining ones still execute in order
it('handles skipped resolve-exception among sequential resolve-exceptions in child block', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
    ], ScenarioTestJob::class)[0];

    // Child block: failing step + 3 sequential resolve-exceptions at different indexes
    $children = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1, 'arguments' => ['fail' => true]], // Fails
        ['block_uuid' => $childBlock, 'index' => 1, 'type' => 'resolve-exception'], // r1: completes first
        ['block_uuid' => $childBlock, 'index' => 2, 'type' => 'resolve-exception', 'arguments' => ['skip' => true]], // r2: skipped
        ['block_uuid' => $childBlock, 'index' => 3, 'type' => 'resolve-exception'], // r3: completes last
    ], ScenarioTestJob::class);

    [$childFail, $r1, $r2, $r3] = $children;

    $r1->update(['state' => NotRunnable::class]);
    $r2->update(['state' => NotRunnable::class]);
    $r3->update(['state' => NotRunnable::class]);

    $statusMatrix = [
        1 => [
            $parent->id => 'running',
            $childFail->id => 'pending',
            $r1->id => 'not-runnable',
            $r2->id => 'not-runnable',
            $r3->id => 'not-runnable',
        ],
        2 => [
            $childFail->id => 'failed',
        ],
        3 => [
            // All resolve-exceptions promoted to pending
            $r1->id => 'pending',
            $r2->id => 'pending',
            $r3->id => 'pending',
        ],
        4 => [
            // r1 (index 1) completes first
            $r1->id => 'completed',
            $r2->id => 'pending', // Still pending, waiting for dispatch
            $r3->id => 'pending', // Waiting for r2 (higher index)
        ],
        5 => [
            // r2 (index 2) skipped - terminal state allows r3 to proceed
            $r2->id => 'skipped',
            $r3->id => 'pending',
        ],
        6 => [
            // r3 (index 3) completes
            $r3->id => 'completed',
        ],
        7 => [
            // NOW parent fails (all resolve-exceptions are terminal)
            $parent->id => 'failed',
        ],
    ];

    StepTester::withSteps([$parent, $childFail, $r1, $r2, $r3])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('sequential_resolve_exceptions_middle_skipped')
        ->test();
});

// Schematic: 4-level deep nesting with resolve-exceptions at multiple levels
// Level 4 (deepest): completes successfully
// Level 3: has sequential resolve-exceptions that all skip one by one
// Level 2: parent step in middle fails (cascade from level 3)
// Level 1 (root): resolve-exception runs, other steps cancelled
it('handles 4-level deep nesting with resolve-exceptions skipping at level 3', function (): void {
    $level1Block = (string) Str::uuid();
    $level2Block = (string) Str::uuid();
    $level3Block = (string) Str::uuid();
    $level4Block = (string) Str::uuid();

    // Level 1 (Root block)
    $level1Steps = StepTester::createSteps([
        ['block_uuid' => $level1Block, 'index' => 1], // s1: completes
        ['block_uuid' => $level1Block, 'index' => 2, 'child_block_uuid' => $level2Block], // s2: parent → level 2
        ['block_uuid' => $level1Block, 'index' => 3], // s3: will be cancelled
        ['block_uuid' => $level1Block, 'index' => null, 'type' => 'resolve-exception'], // r1: will run
    ], ScenarioTestJob::class);

    [$s1, $s2, $s3, $r1] = $level1Steps;

    // Level 2
    $level2Steps = StepTester::createSteps([
        ['block_uuid' => $level2Block, 'index' => 1], // s4: completes
        ['block_uuid' => $level2Block, 'index' => 2, 'child_block_uuid' => $level3Block], // s5: parent → level 3 (middle, fails)
        ['block_uuid' => $level2Block, 'index' => 3], // s6: will be cancelled
    ], ScenarioTestJob::class);

    [$s4, $s5, $s6] = $level2Steps;

    // Level 3
    $level3Steps = StepTester::createSteps([
        ['block_uuid' => $level3Block, 'index' => 1, 'child_block_uuid' => $level4Block], // s7: parent → level 4
        ['block_uuid' => $level3Block, 'index' => 2, 'arguments' => ['fail' => true]], // s8: FAILS
        ['block_uuid' => $level3Block, 'index' => 3], // s9: will be cancelled
        ['block_uuid' => $level3Block, 'index' => 1, 'type' => 'resolve-exception', 'arguments' => ['skip' => true]], // r2: skips
        ['block_uuid' => $level3Block, 'index' => 2, 'type' => 'resolve-exception', 'arguments' => ['skip' => true]], // r3: skips
        ['block_uuid' => $level3Block, 'index' => 3, 'type' => 'resolve-exception', 'arguments' => ['skip' => true]], // r4: skips
    ], ScenarioTestJob::class);

    [$s7, $s8, $s9, $r2, $r3, $r4] = $level3Steps;

    // Level 4 (Deepest)
    $level4Steps = StepTester::createSteps([
        ['block_uuid' => $level4Block, 'index' => 1], // s10: completes
        ['block_uuid' => $level4Block, 'index' => 1], // s11: completes (parallel)
    ], ScenarioTestJob::class);

    [$s10, $s11] = $level4Steps;

    // Set resolve-exceptions to NotRunnable
    $r1->update(['state' => NotRunnable::class]);
    $r2->update(['state' => NotRunnable::class]);
    $r3->update(['state' => NotRunnable::class]);
    $r4->update(['state' => NotRunnable::class]);

    $statusMatrix = [
        // === Level 1 starts ===
        1 => [
            $s1->id => 'completed',
            $s2->id => 'pending',
            $s3->id => 'pending',
            $r1->id => 'not-runnable',
            // Level 2-4 steps are pending
            $s4->id => 'pending',
            $s5->id => 'pending',
            $s6->id => 'pending',
            $s7->id => 'pending',
            $s8->id => 'pending',
            $s9->id => 'pending',
            $r2->id => 'not-runnable',
            $r3->id => 'not-runnable',
            $r4->id => 'not-runnable',
            $s10->id => 'pending',
            $s11->id => 'pending',
        ],
        // === Level 2 starts (s2 running) ===
        2 => [
            $s2->id => 'running',
        ],
        3 => [
            $s4->id => 'completed',
        ],
        // === Level 3 starts (s5 running) ===
        4 => [
            $s5->id => 'running',
        ],
        // === Level 4 starts (s7 running) ===
        5 => [
            $s7->id => 'running',
        ],
        // === Level 4 completes ===
        6 => [
            $s10->id => 'completed',
            $s11->id => 'completed',
        ],
        7 => [
            $s7->id => 'completed', // Level 4 succeeded
        ],
        // === Level 3 step fails ===
        8 => [
            $s8->id => 'failed',
        ],
        9 => [
            $s9->id => 'cancelled', // Downstream cancelled
        ],
        // === Level 3 resolve-exceptions promoted ===
        10 => [
            $r2->id => 'pending',
            $r3->id => 'pending',
            $r4->id => 'pending',
        ],
        // === Level 3 resolve-exceptions skip one by one (sequential) ===
        11 => [
            $r2->id => 'skipped', // index 1 first
            $r3->id => 'pending',
            $r4->id => 'pending',
        ],
        12 => [
            $r3->id => 'skipped', // index 2 second
            $r4->id => 'pending',
        ],
        13 => [
            $r4->id => 'skipped', // index 3 third
        ],
        // === Level 2 parent fails (level 3 had failures) ===
        14 => [
            $s5->id => 'failed',
        ],
        15 => [
            $s6->id => 'cancelled', // Downstream cancelled
        ],
        // === Level 1 parent fails (level 2 had failures) ===
        16 => [
            $s2->id => 'failed',
        ],
        17 => [
            $s3->id => 'cancelled', // Downstream cancelled
        ],
        // === Level 1 resolve-exception runs ===
        18 => [
            $r1->id => 'pending',
        ],
        19 => [
            $r1->id => 'completed',
        ],
    ];

    StepTester::withSteps([
        $s1, $s2, $s3, $r1,           // Level 1
        $s4, $s5, $s6,                 // Level 2
        $s7, $s8, $s9, $r2, $r3, $r4,  // Level 3
        $s10, $s11,                    // Level 4
    ])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('4_level_deep_with_resolve_exceptions')
        ->test();
});

// Schematic: Parent -> Children (all complete) + resolve-exception (stays NotRunnable)
// Dormant resolve-exception should NOT block parent completion on success path
it('completes parent when all children succeed and resolve-exception stays NotRunnable', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
    ], ScenarioTestJob::class)[0];

    $children = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1],
        ['block_uuid' => $childBlock, 'index' => 2],
        ['block_uuid' => $childBlock, 'index' => 3],
        ['block_uuid' => $childBlock, 'index' => null, 'type' => 'resolve-exception'],
    ], ScenarioTestJob::class);

    [$c1, $c2, $c3, $resolveStep] = $children;

    $resolveStep->update(['state' => NotRunnable::class]);

    $statusMatrix = [
        1 => [
            $parent->id => 'running',
            $c1->id => 'pending',
            $c2->id => 'pending',
            $c3->id => 'pending',
            $resolveStep->id => 'not-runnable',
        ],
        2 => [
            $c1->id => 'completed',
        ],
        3 => [
            $c2->id => 'completed',
        ],
        4 => [
            $c3->id => 'completed',
            $resolveStep->id => 'not-runnable', // Still dormant - no failure
        ],
        5 => [
            $parent->id => 'completed', // Parent completes despite dormant resolve-exception
        ],
    ];

    StepTester::withSteps([$parent, $c1, $c2, $c3, $resolveStep])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parent_completes_with_dormant_resolve_exception')
        ->test();
});

// Schematic: Parent -> Children (all complete) + multiple resolve-exceptions (all stay NotRunnable)
// Multiple dormant resolve-exceptions should NOT block parent completion
it('completes parent with multiple dormant resolve-exceptions on success path', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
    ], ScenarioTestJob::class)[0];

    $children = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1],
        ['block_uuid' => $childBlock, 'index' => 2],
        ['block_uuid' => $childBlock, 'index' => 1, 'type' => 'resolve-exception'],
        ['block_uuid' => $childBlock, 'index' => 2, 'type' => 'resolve-exception'],
        ['block_uuid' => $childBlock, 'index' => 3, 'type' => 'resolve-exception'],
    ], ScenarioTestJob::class);

    [$c1, $c2, $r1, $r2, $r3] = $children;

    $r1->update(['state' => NotRunnable::class]);
    $r2->update(['state' => NotRunnable::class]);
    $r3->update(['state' => NotRunnable::class]);

    $statusMatrix = [
        1 => [
            $parent->id => 'running',
            $c1->id => 'pending',
            $c2->id => 'pending',
            $r1->id => 'not-runnable',
            $r2->id => 'not-runnable',
            $r3->id => 'not-runnable',
        ],
        2 => [
            $c1->id => 'completed',
        ],
        3 => [
            $c2->id => 'completed',
        ],
        4 => [
            $parent->id => 'completed', // Parent completes despite 3 dormant resolve-exceptions
        ],
    ];

    StepTester::withSteps([$parent, $c1, $c2, $r1, $r2, $r3])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parent_completes_with_multiple_dormant_resolve_exceptions')
        ->test();
});
