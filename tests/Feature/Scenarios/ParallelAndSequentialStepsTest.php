<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use StepDispatcher\Tests\Fixtures\ScenarioTestJob;
use StepDispatcher\Tests\Support\StepTester;

/*
|--------------------------------------------------------------------------
| Parallel and Sequential Steps Tests
|--------------------------------------------------------------------------
|
| These tests verify the step dispatcher correctly handles:
|
| - Parallel steps: Same index → can all be dispatched together
| - Sequential steps: Different indexes → must wait for previous to complete
|
*/

it('dispatches parallel steps at same index in single tick', function (): void {
    $blockUuid = Str::uuid()->toString();

    $steps = StepTester::createSteps([
        ['block_uuid' => $blockUuid, 'index' => 1],
        ['block_uuid' => $blockUuid, 'index' => 1],
        ['block_uuid' => $blockUuid, 'index' => 1],
    ], ScenarioTestJob::class);

    [$s1, $s2, $s3] = $steps;

    $statusMatrix = [
        1 => [
            $s1->id => 'completed',
            $s2->id => 'completed',
            $s3->id => 'completed',
        ],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parallel_steps_same_index')
        ->test();
});

it('dispatches sequential steps in order across multiple ticks', function (): void {
    $blockUuid = Str::uuid()->toString();

    $steps = StepTester::createSteps([
        ['block_uuid' => $blockUuid, 'index' => 1],
        ['block_uuid' => $blockUuid, 'index' => 2],
    ], ScenarioTestJob::class);

    [$s1, $s2] = $steps;

    $statusMatrix = [
        1 => [
            $s1->id => 'completed',
            $s2->id => 'pending',
        ],
        2 => [
            $s2->id => 'completed',
        ],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('sequential_steps_order')
        ->test();
});

it('dispatches 5 sequential steps in order', function (): void {
    $blockUuid = Str::uuid()->toString();

    $steps = StepTester::createSteps([
        ['block_uuid' => $blockUuid, 'index' => 1],
        ['block_uuid' => $blockUuid, 'index' => 2],
        ['block_uuid' => $blockUuid, 'index' => 3],
        ['block_uuid' => $blockUuid, 'index' => 4],
        ['block_uuid' => $blockUuid, 'index' => 5],
    ], ScenarioTestJob::class);

    [$s1, $s2, $s3, $s4, $s5] = $steps;

    $statusMatrix = [
        1 => [
            $s1->id => 'completed',
            $s2->id => 'pending',
            $s3->id => 'pending',
            $s4->id => 'pending',
            $s5->id => 'pending',
        ],
        2 => [
            $s2->id => 'completed',
            $s3->id => 'pending',
        ],
        3 => [
            $s3->id => 'completed',
            $s4->id => 'pending',
        ],
        4 => [
            $s4->id => 'completed',
            $s5->id => 'pending',
        ],
        5 => [
            $s5->id => 'completed',
        ],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('5_sequential_steps')
        ->test();
});

it('dispatches mixed parallel and sequential pattern', function (): void {
    $blockUuid = Str::uuid()->toString();

    $steps = StepTester::createSteps([
        // Index 1: 2 parallel steps
        ['block_uuid' => $blockUuid, 'index' => 1],
        ['block_uuid' => $blockUuid, 'index' => 1],
        // Index 2: 1 step
        ['block_uuid' => $blockUuid, 'index' => 2],
        // Index 3: 3 parallel steps
        ['block_uuid' => $blockUuid, 'index' => 3],
        ['block_uuid' => $blockUuid, 'index' => 3],
        ['block_uuid' => $blockUuid, 'index' => 3],
    ], ScenarioTestJob::class);

    [$s1a, $s1b, $s2, $s3a, $s3b, $s3c] = $steps;

    $statusMatrix = [
        1 => [
            $s1a->id => 'completed',
            $s1b->id => 'completed',
            $s2->id => 'pending',
            $s3a->id => 'pending',
            $s3b->id => 'pending',
            $s3c->id => 'pending',
        ],
        2 => [
            $s2->id => 'completed',
            $s3a->id => 'pending',
        ],
        3 => [
            $s3a->id => 'completed',
            $s3b->id => 'completed',
            $s3c->id => 'completed',
        ],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('mixed_parallel_sequential')
        ->test();
});

it('waits for all parallel steps at index 1 before dispatching index 2', function (): void {
    $blockUuid = Str::uuid()->toString();

    $steps = StepTester::createSteps([
        // Index 1: 3 parallel steps
        ['block_uuid' => $blockUuid, 'index' => 1],
        ['block_uuid' => $blockUuid, 'index' => 1],
        ['block_uuid' => $blockUuid, 'index' => 1],
        // Index 2: 1 step
        ['block_uuid' => $blockUuid, 'index' => 2],
    ], ScenarioTestJob::class);

    [$s1a, $s1b, $s1c, $s2] = $steps;

    $statusMatrix = [
        1 => [
            $s1a->id => 'completed',
            $s1b->id => 'completed',
            $s1c->id => 'completed',
            $s2->id => 'pending',
        ],
        2 => [
            $s2->id => 'completed',
        ],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parallel_wait_before_sequential')
        ->test();
});

it('handles 10 parallel steps at same index', function (): void {
    $blockUuid = Str::uuid()->toString();

    $stepDefinitions = [];
    for ($i = 0; $i < 10; $i++) {
        $stepDefinitions[] = ['block_uuid' => $blockUuid, 'index' => 1];
    }

    $steps = StepTester::createSteps($stepDefinitions, ScenarioTestJob::class);

    $expectedTick1 = [];
    foreach ($steps as $step) {
        $expectedTick1[$step->id] = 'completed';
    }

    $statusMatrix = [
        1 => $expectedTick1,
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('10_parallel_steps')
        ->test();
});

it('handles alternating parallel and sequential pattern', function (): void {
    $blockUuid = Str::uuid()->toString();

    $steps = StepTester::createSteps([
        // Index 1: 2 parallel
        ['block_uuid' => $blockUuid, 'index' => 1],
        ['block_uuid' => $blockUuid, 'index' => 1],
        // Index 2: 2 parallel
        ['block_uuid' => $blockUuid, 'index' => 2],
        ['block_uuid' => $blockUuid, 'index' => 2],
        // Index 3: 2 parallel
        ['block_uuid' => $blockUuid, 'index' => 3],
        ['block_uuid' => $blockUuid, 'index' => 3],
    ], ScenarioTestJob::class);

    [$s1a, $s1b, $s2a, $s2b, $s3a, $s3b] = $steps;

    $statusMatrix = [
        1 => [
            $s1a->id => 'completed',
            $s1b->id => 'completed',
            $s2a->id => 'pending',
            $s2b->id => 'pending',
            $s3a->id => 'pending',
            $s3b->id => 'pending',
        ],
        2 => [
            $s2a->id => 'completed',
            $s2b->id => 'completed',
            $s3a->id => 'pending',
        ],
        3 => [
            $s3a->id => 'completed',
            $s3b->id => 'completed',
        ],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('alternating_parallel_sequential')
        ->test();
});

it('handles skipped step allowing next index to dispatch', function (): void {
    $blockUuid = Str::uuid()->toString();

    $steps = StepTester::createSteps([
        ['block_uuid' => $blockUuid, 'index' => 1, 'arguments' => ['skip' => true]],
        ['block_uuid' => $blockUuid, 'index' => 2],
    ], ScenarioTestJob::class);

    [$s1, $s2] = $steps;

    $statusMatrix = [
        1 => [
            $s1->id => 'skipped',
            $s2->id => 'pending',
        ],
        2 => [
            $s2->id => 'completed',
        ],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('skipped_allows_next')
        ->test();
});
