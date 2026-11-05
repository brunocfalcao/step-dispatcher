<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use StepDispatcher\Tests\Support\StepTester;
use StepDispatcher\Tests\Fixtures\ScenarioTestJob;


// Schematic: [∅] with dispatch_after in future
// A step with dispatch_after in the future should NOT dispatch yet
it('does not dispatch a step with dispatch_after in future', function (): void {
    $step = StepTester::createSteps([
        ['dispatch_after' => now()->addMinutes(5)],
    ], ScenarioTestJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'pending'], // Should remain pending
        2 => [$step->id => 'pending'], // Still pending
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('delayed_step_future')
        ->test();
});

// Schematic: [∅] with dispatch_after in past
// A step with dispatch_after in the past should dispatch immediately
it('dispatches a step with dispatch_after in past', function (): void {
    $step = StepTester::createSteps([
        ['dispatch_after' => now()->subMinutes(5)],
    ], ScenarioTestJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'completed'],
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('delayed_step_past')
        ->test();
});

// Schematic: [∅] with dispatch_after = null
// A step with null dispatch_after should dispatch immediately (default behavior)
it('dispatches a step with null dispatch_after immediately', function (): void {
    $step = StepTester::createSteps([
        ['dispatch_after' => null],
    ], ScenarioTestJob::class)[0];

    $statusMatrix = [
        1 => [$step->id => 'completed'],
    ];

    StepTester::withSteps([$step])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('delayed_step_null')
        ->test();
});

// Schematic: 1 (ready) + 2 (delayed)
// In a sequential workflow, if step 1 completes but step 2 is delayed, step 2 waits
it('respects dispatch_after for sequential steps', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1], // Ready now
        ['block_uuid' => $block, 'index' => 2, 'dispatch_after' => now()->addMinutes(5)], // Delayed
    ], ScenarioTestJob::class);

    [$step1, $step2] = $steps;

    $statusMatrix = [
        1 => [$step1->id => 'completed', $step2->id => 'pending'],
        2 => [$step1->id => 'completed', $step2->id => 'pending'], // Still pending due to delay
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('sequential_delayed_step2')
        ->test();
});

// Schematic: 1,1 (one ready, one delayed)
// Parallel steps at same index: ready one dispatches, delayed one waits
it('dispatches only ready parallel steps at same index', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1], // Ready
        ['block_uuid' => $block, 'index' => 1, 'dispatch_after' => now()->addMinutes(5)], // Delayed
    ], ScenarioTestJob::class);

    [$step1, $step2] = $steps;

    $statusMatrix = [
        1 => [$step1->id => 'completed', $step2->id => 'pending'],
        2 => [$step1->id => 'completed', $step2->id => 'pending'], // Delayed step still pending
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parallel_one_delayed')
        ->test();
});

// Schematic: Parent (delayed) -> Children (ready)
// Delayed parent should not dispatch, children cannot run until parent runs
it('delays parent and children wait', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        [
            'block_uuid' => $parentBlock,
            'index' => 1,
            'child_block_uuid' => $childBlock,
            'dispatch_after' => now()->addMinutes(5),
        ],
    ], ScenarioTestJob::class)[0];

    $children = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1],
        ['block_uuid' => $childBlock, 'index' => 2],
    ], ScenarioTestJob::class);

    [$c1, $c2] = $children;

    $statusMatrix = [
        1 => [$parent->id => 'pending', $c1->id => 'pending', $c2->id => 'pending'],
        2 => [$parent->id => 'pending', $c1->id => 'pending', $c2->id => 'pending'], // All wait
    ];

    StepTester::withSteps([$parent, ...$children])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('delayed_parent_blocks_children')
        ->test();
});

// Schematic: Parent (ready) -> Child (delayed)
// Parent runs and transitions to Running, but delayed child doesn't dispatch yet
it('runs parent but delays child', function (): void {
    $parentBlock = (string) Str::uuid();
    $childBlock = (string) Str::uuid();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
    ], ScenarioTestJob::class)[0];

    $child = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1, 'dispatch_after' => now()->addMinutes(5)],
    ], ScenarioTestJob::class)[0];

    $statusMatrix = [
        1 => [$parent->id => 'running', $child->id => 'pending'],
        2 => [$parent->id => 'running', $child->id => 'pending'], // Child delayed, parent stays running
    ];

    StepTester::withSteps([$parent, $child])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parent_runs_child_delayed')
        ->test();
});

// Schematic: 1,1 (all delayed by different amounts)
// Multiple delayed steps at same index - none should dispatch until time passes
it('handles multiple delayed parallel steps', function (): void {
    $block = (string) Str::uuid();

    $steps = StepTester::createSteps([
        ['block_uuid' => $block, 'index' => 1, 'dispatch_after' => now()->addMinutes(5)],
        ['block_uuid' => $block, 'index' => 1, 'dispatch_after' => now()->addMinutes(10)],
        ['block_uuid' => $block, 'index' => 1, 'dispatch_after' => now()->addMinutes(15)],
    ], ScenarioTestJob::class);

    [$s1, $s2, $s3] = $steps;

    $statusMatrix = [
        1 => [$s1->id => 'pending', $s2->id => 'pending', $s3->id => 'pending'],
        2 => [$s1->id => 'pending', $s2->id => 'pending', $s3->id => 'pending'],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('multiple_delayed_parallel')
        ->test();
});
