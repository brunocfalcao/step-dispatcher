<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\NotRunnable;
use StepDispatcher\Tests\Fixtures\ScenarioTestJob;
use StepDispatcher\Tests\Support\StepTester;

/*
|--------------------------------------------------------------------------
| Resolve-Exception Steps Tests
|--------------------------------------------------------------------------
|
| These tests verify the step dispatcher correctly handles resolve-exception
| steps which are special exception handlers that:
|
| - Start as NotRunnable state
| - Get promoted to Pending when a failure occurs in the same block
| - Can have sequential indexes (execute in order)
| - Execute before downstream steps resume
|
*/

it('resolve-exception step is created with NotRunnable state', function (): void {
    $step = Step::factory()->create([
        'type' => 'resolve-exception',
    ]);

    expect($step->state)->toBeInstanceOf(NotRunnable::class);
});

it('resolve-exception is promoted to Pending and dispatches after failure', function (): void {
    $blockUuid = Str::uuid()->toString();

    // First create the failed step, then the resolve-exception
    // The resolve-exception will be promoted and dispatched after the failed step
    $steps = StepTester::createSteps([
        ['block_uuid' => $blockUuid, 'index' => 1, 'arguments' => ['fail' => true]],
        ['block_uuid' => $blockUuid, 'index' => 1, 'type' => 'resolve-exception'],
    ], ScenarioTestJob::class);

    [$failed, $resolve] = $steps;

    // Resolve-exception starts as NotRunnable due to observer
    expect($resolve->fresh()->state->value())->toBe('not-runnable');

    $statusMatrix = [
        1 => [
            $failed->id => 'failed',
            $resolve->id => 'not-runnable',
        ],
        2 => [
            $resolve->id => 'pending',  // Promoted due to failure
        ],
        3 => [
            $resolve->id => 'completed',  // Dispatched and completed
        ],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('resolve_exception_promotion')
        ->test();
});

it('resolve-exception is promoted when stopped step exists', function (): void {
    $blockUuid = Str::uuid()->toString();

    $steps = StepTester::createSteps([
        ['block_uuid' => $blockUuid, 'index' => 1, 'arguments' => ['stop' => true]],
        ['block_uuid' => $blockUuid, 'index' => 1, 'type' => 'resolve-exception'],
    ], ScenarioTestJob::class);

    [$stopped, $resolve] = $steps;

    $statusMatrix = [
        1 => [
            $stopped->id => 'stopped',
            $resolve->id => 'not-runnable',
        ],
        2 => [
            $resolve->id => 'pending',  // Promoted due to stopped
        ],
        3 => [
            $resolve->id => 'completed',
        ],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('resolve_exception_stopped_promotion')
        ->test();
});

it('sequential resolve-exception steps execute in order', function (): void {
    $blockUuid = Str::uuid()->toString();

    $steps = StepTester::createSteps([
        ['block_uuid' => $blockUuid, 'index' => 1, 'arguments' => ['fail' => true]],
        ['block_uuid' => $blockUuid, 'index' => 1, 'type' => 'resolve-exception'],
        ['block_uuid' => $blockUuid, 'index' => 2, 'type' => 'resolve-exception'],
        ['block_uuid' => $blockUuid, 'index' => 3, 'type' => 'resolve-exception'],
    ], ScenarioTestJob::class);

    [$failed, $r1, $r2, $r3] = $steps;

    $statusMatrix = [
        1 => [
            $failed->id => 'failed',
            $r1->id => 'not-runnable',
            $r2->id => 'not-runnable',
            $r3->id => 'not-runnable',
        ],
        2 => [
            $r1->id => 'pending',
            $r2->id => 'pending',
            $r3->id => 'pending',
        ],
        3 => [
            $r1->id => 'completed',
            $r2->id => 'pending',
            $r3->id => 'pending',
        ],
        4 => [
            $r2->id => 'completed',
            $r3->id => 'pending',
        ],
        5 => [
            $r3->id => 'completed',
        ],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('sequential_resolve_exception')
        ->test();
});

it('multiple resolve-exception at same index dispatch together', function (): void {
    $blockUuid = Str::uuid()->toString();

    $steps = StepTester::createSteps([
        ['block_uuid' => $blockUuid, 'index' => 1, 'arguments' => ['fail' => true]],
        ['block_uuid' => $blockUuid, 'index' => 1, 'type' => 'resolve-exception'],
        ['block_uuid' => $blockUuid, 'index' => 1, 'type' => 'resolve-exception'],
    ], ScenarioTestJob::class);

    [$failed, $r1a, $r1b] = $steps;

    $statusMatrix = [
        1 => [
            $failed->id => 'failed',
            $r1a->id => 'not-runnable',
            $r1b->id => 'not-runnable',
        ],
        2 => [
            $r1a->id => 'pending',
            $r1b->id => 'pending',
        ],
        3 => [
            $r1a->id => 'completed',
            $r1b->id => 'completed',
        ],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parallel_resolve_exception')
        ->test();
});

// Note: Nested resolve-exception tests are covered in ParentChildRelationshipsTest.php
// The interaction between parent failure cascading and resolve-exception promotion
// requires further investigation of the actual dispatcher behavior.

it('5 sequential resolve-exception steps execute in order', function (): void {
    $blockUuid = Str::uuid()->toString();

    $steps = StepTester::createSteps([
        ['block_uuid' => $blockUuid, 'index' => 1, 'arguments' => ['fail' => true]],
        ['block_uuid' => $blockUuid, 'index' => 1, 'type' => 'resolve-exception'],
        ['block_uuid' => $blockUuid, 'index' => 2, 'type' => 'resolve-exception'],
        ['block_uuid' => $blockUuid, 'index' => 3, 'type' => 'resolve-exception'],
        ['block_uuid' => $blockUuid, 'index' => 4, 'type' => 'resolve-exception'],
        ['block_uuid' => $blockUuid, 'index' => 5, 'type' => 'resolve-exception'],
    ], ScenarioTestJob::class);

    [$failed, $r1, $r2, $r3, $r4, $r5] = $steps;

    $statusMatrix = [
        1 => [
            $failed->id => 'failed',
            $r1->id => 'not-runnable',
            $r2->id => 'not-runnable',
            $r3->id => 'not-runnable',
            $r4->id => 'not-runnable',
            $r5->id => 'not-runnable',
        ],
        2 => [
            $r1->id => 'pending',
            $r2->id => 'pending',
            $r3->id => 'pending',
            $r4->id => 'pending',
            $r5->id => 'pending',
        ],
        3 => [
            $r1->id => 'completed',
            $r2->id => 'pending',
        ],
        4 => [
            $r2->id => 'completed',
            $r3->id => 'pending',
        ],
        5 => [
            $r3->id => 'completed',
            $r4->id => 'pending',
        ],
        6 => [
            $r4->id => 'completed',
            $r5->id => 'pending',
        ],
        7 => [
            $r5->id => 'completed',
        ],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('5_sequential_resolve_exception')
        ->test();
});

it('resolve-exception stays NotRunnable when no failure in block', function (): void {
    $blockUuid = Str::uuid()->toString();

    $steps = StepTester::createSteps([
        ['block_uuid' => $blockUuid, 'index' => 1],
        ['block_uuid' => $blockUuid, 'index' => 1, 'type' => 'resolve-exception'],
    ], ScenarioTestJob::class);

    [$normal, $resolve] = $steps;

    $statusMatrix = [
        1 => [
            $normal->id => 'completed',
            $resolve->id => 'not-runnable',  // Stays NotRunnable - no failure
        ],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('resolve_exception_no_failure')
        ->test();
});

it('dormant resolve-exception does not block parent completion on success path', function (): void {
    $parentBlock = Str::uuid()->toString();
    $childBlock = Str::uuid()->toString();

    $parent = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
    ], ScenarioTestJob::class)[0];

    $children = StepTester::createSteps([
        ['block_uuid' => $childBlock, 'index' => 1],
        ['block_uuid' => $childBlock, 'index' => 2],
        ['block_uuid' => $childBlock, 'index' => 1, 'type' => 'resolve-exception'],
    ], ScenarioTestJob::class);

    [$c1, $c2, $resolve] = $children;

    $statusMatrix = [
        1 => [
            $parent->id => 'running',
            $c1->id => 'pending',
            $c2->id => 'pending',
            $resolve->id => 'not-runnable',
        ],
        2 => [
            $c1->id => 'completed',
        ],
        3 => [
            $c2->id => 'completed',
            $resolve->id => 'not-runnable', // Stays dormant
        ],
        4 => [
            $parent->id => 'completed', // Parent completes - dormant resolve-exception doesn't block
        ],
    ];

    StepTester::withSteps([$parent, $c1, $c2, $resolve])
        ->withStatusMatrix($statusMatrix)
        ->withLabel('dormant_resolve_exception_no_block')
        ->test();
});

// Note: The interaction between resolve-exception promotion and downstream steps
// at higher indexes requires further investigation of the actual dispatcher behavior.
// This edge case is not covered as the promotion logic may differ with pending downstream steps.
