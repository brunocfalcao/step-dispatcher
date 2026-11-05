<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\Models\StepsDispatcher;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Failed;
use StepDispatcher\States\NotRunnable;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\States\Skipped;
use StepDispatcher\States\Stopped;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Tests\Fixtures\ScenarioTestJob;

/*
|--------------------------------------------------------------------------
| Cascaded Cancellations and Skipped Steps Tests
|--------------------------------------------------------------------------
|
| These tests verify the step dispatcher correctly handles:
|
| - Cascaded cancellations: Failed step cancels downstream steps (higher index)
| - Skipped parent cascade: Skipped parent cascades skip to all descendants
| - Failure cascade to children: Failed parent fails all non-terminal children
|
*/

beforeEach(function (): void {
    StepsDispatcher::query()->delete();
    Queue::fake();
});

/*
|--------------------------------------------------------------------------
| Cascaded Cancellations (Failed Step → Cancel Downstream)
|--------------------------------------------------------------------------
*/

test('failed step cancels downstream steps at higher index', function (): void {
    $blockUuid = Str::uuid()->toString();
    $group = $blockUuid;

    // Step at index 1 - failed
    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $blockUuid,
        'index' => 1,
        'state' => Failed::class,
        'group' => $group,
    ]);

    // Steps at index 2 and 3 - should be cancelled
    $step2 = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $blockUuid,
        'index' => 2,
        'state' => Pending::class,
        'group' => $group,
    ]);

    $step3 = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $blockUuid,
        'index' => 3,
        'state' => Pending::class,
        'group' => $group,
    ]);

    StepDispatcher::dispatch($group);

    expect($step2->fresh()->state)->toBeInstanceOf(Cancelled::class);
    expect($step3->fresh()->state)->toBeInstanceOf(Cancelled::class);
});

test('stopped step also cancels downstream steps', function (): void {
    $blockUuid = Str::uuid()->toString();
    $group = $blockUuid;

    // Step at index 1 - stopped
    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $blockUuid,
        'index' => 1,
        'state' => Stopped::class,
        'group' => $group,
    ]);

    // Step at index 2 - should be cancelled
    $step2 = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $blockUuid,
        'index' => 2,
        'state' => Pending::class,
        'group' => $group,
    ]);

    StepDispatcher::dispatch($group);

    expect($step2->fresh()->state)->toBeInstanceOf(Cancelled::class);
});

test('failed step does not cancel steps at same index', function (): void {
    $blockUuid = Str::uuid()->toString();
    $group = $blockUuid;

    // Step at index 1 - failed
    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $blockUuid,
        'index' => 1,
        'state' => Failed::class,
        'group' => $group,
    ]);

    // Another step at index 1 - should NOT be cancelled
    $parallelStep = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $blockUuid,
        'index' => 1,
        'state' => Running::class,
        'group' => $group,
    ]);

    StepDispatcher::dispatch($group);

    expect($parallelStep->fresh()->state)->toBeInstanceOf(Running::class);
});

test('failed step does not cancel steps at lower index', function (): void {
    $blockUuid = Str::uuid()->toString();
    $group = $blockUuid;

    // Step at index 1 - completed
    $step1 = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $blockUuid,
        'index' => 1,
        'state' => Completed::class,
        'group' => $group,
    ]);

    // Step at index 2 - failed
    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $blockUuid,
        'index' => 2,
        'state' => Failed::class,
        'group' => $group,
    ]);

    StepDispatcher::dispatch($group);

    // Step 1 should remain completed
    expect($step1->fresh()->state)->toBeInstanceOf(Completed::class);
});

test('failed step cancels 5 downstream steps', function (): void {
    $blockUuid = Str::uuid()->toString();
    $group = $blockUuid;

    // Step at index 1 - failed
    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $blockUuid,
        'index' => 1,
        'state' => Failed::class,
        'group' => $group,
    ]);

    // 5 downstream steps
    $downstreamSteps = [];
    for ($i = 2; $i <= 6; $i++) {
        $downstreamSteps[$i] = Step::factory()->create([
            'class' => ScenarioTestJob::class,
            'block_uuid' => $blockUuid,
            'index' => $i,
            'state' => Pending::class,
            'group' => $group,
        ]);
    }

    StepDispatcher::dispatch($group);

    foreach ($downstreamSteps as $step) {
        expect($step->fresh()->state)->toBeInstanceOf(Cancelled::class);
    }
});

test('failed step does not cancel NotRunnable resolve-exception steps', function (): void {
    $blockUuid = Str::uuid()->toString();
    $group = $blockUuid;

    // Step at index 1 - failed
    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $blockUuid,
        'index' => 1,
        'state' => Failed::class,
        'group' => $group,
    ]);

    // Resolve-exception at index 2 - NotRunnable (should NOT be cancelled)
    $resolveStep = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $blockUuid,
        'index' => 2,
        'type' => 'resolve-exception',
        'state' => NotRunnable::class,
        'group' => $group,
    ]);

    StepDispatcher::dispatch($group);

    // Should be promoted, not cancelled
    expect($resolveStep->fresh()->state)->toBeInstanceOf(Pending::class);
});

test('cancelled step does not trigger further cancellations', function (): void {
    $blockUuid = Str::uuid()->toString();
    $group = $blockUuid;

    // Step at index 1 - failed (triggers cancellation)
    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $blockUuid,
        'index' => 1,
        'state' => Failed::class,
        'group' => $group,
    ]);

    // Step at index 2 - will be cancelled
    $step2 = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $blockUuid,
        'index' => 2,
        'state' => Pending::class,
        'group' => $group,
    ]);

    // Step at index 3 - should also be cancelled (by the failed step, not by cancelled step)
    $step3 = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $blockUuid,
        'index' => 3,
        'state' => Pending::class,
        'group' => $group,
    ]);

    StepDispatcher::dispatch($group);

    expect($step2->fresh()->state)->toBeInstanceOf(Cancelled::class);
    expect($step3->fresh()->state)->toBeInstanceOf(Cancelled::class);
});

test('failed parent step cancels pending children', function (): void {
    $parentBlockUuid = Str::uuid()->toString();
    $childBlockUuid = Str::uuid()->toString();
    $group = $parentBlockUuid;

    // Step at index 1 - completed
    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $parentBlockUuid,
        'index' => 1,
        'state' => Completed::class,
        'group' => $group,
    ]);

    // Step at index 2 - failed (parent)
    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $parentBlockUuid,
        'index' => 2,
        'state' => Failed::class,
        'group' => $group,
    ]);

    // Parent step at index 3 - should be cancelled (downstream of failure)
    $parentStep = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $parentBlockUuid,
        'child_block_uuid' => $childBlockUuid,
        'index' => 3,
        'state' => Pending::class,
        'group' => $group,
    ]);

    // Child step - should be cancelled (parent's children)
    $childStep = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $childBlockUuid,
        'index' => 1,
        'state' => Pending::class,
        'group' => $group,
    ]);

    StepDispatcher::dispatch($group);

    expect($parentStep->fresh()->state)->toBeInstanceOf(Cancelled::class);
    expect($childStep->fresh()->state)->toBeInstanceOf(Cancelled::class);
});

/*
|--------------------------------------------------------------------------
| Skipped Parent Cascade
|--------------------------------------------------------------------------
*/

test('skipped parent cascades skip to all children', function (): void {
    $parentBlockUuid = Str::uuid()->toString();
    $childBlockUuid = Str::uuid()->toString();
    $group = $parentBlockUuid;

    // Skipped parent
    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $parentBlockUuid,
        'child_block_uuid' => $childBlockUuid,
        'state' => Skipped::class,
        'group' => $group,
    ]);

    // Children should be skipped
    $child1 = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $childBlockUuid,
        'index' => 1,
        'state' => Pending::class,
        'group' => $group,
    ]);

    $child2 = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $childBlockUuid,
        'index' => 2,
        'state' => Pending::class,
        'group' => $group,
    ]);

    StepDispatcher::dispatch($group);

    expect($child1->fresh()->state)->toBeInstanceOf(Skipped::class);
    expect($child2->fresh()->state)->toBeInstanceOf(Skipped::class);
});

test('skipped parent cascades skip to nested grandchildren', function (): void {
    $rootBlockUuid = Str::uuid()->toString();
    $level1BlockUuid = Str::uuid()->toString();
    $level2BlockUuid = Str::uuid()->toString();
    $group = $rootBlockUuid;

    // Skipped root parent
    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $rootBlockUuid,
        'child_block_uuid' => $level1BlockUuid,
        'state' => Skipped::class,
        'group' => $group,
    ]);

    // Level 1 child (also parent)
    $level1Step = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $level1BlockUuid,
        'child_block_uuid' => $level2BlockUuid,
        'state' => Pending::class,
        'group' => $group,
    ]);

    // Level 2 grandchild
    $level2Step = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $level2BlockUuid,
        'state' => Pending::class,
        'group' => $group,
    ]);

    StepDispatcher::dispatch($group);

    // Both should be skipped
    expect($level1Step->fresh()->state)->toBeInstanceOf(Skipped::class);
    expect($level2Step->fresh()->state)->toBeInstanceOf(Skipped::class);
});

test('skipped parent at 5 levels deep skips all descendants', function (): void {
    $blocks = [];
    for ($i = 0; $i <= 5; $i++) {
        $blocks[$i] = Str::uuid()->toString();
    }
    $group = $blocks[0];

    // Skipped root
    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $blocks[0],
        'child_block_uuid' => $blocks[1],
        'state' => Skipped::class,
        'group' => $group,
    ]);

    $steps = [];
    // Create 5 levels of descendants
    for ($i = 1; $i < 5; $i++) {
        $steps[$i] = Step::factory()->create([
            'class' => ScenarioTestJob::class,
            'block_uuid' => $blocks[$i],
            'child_block_uuid' => $blocks[$i + 1],
            'state' => Pending::class,
            'group' => $group,
        ]);
    }

    // Leaf step
    $steps[5] = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $blocks[5],
        'state' => Pending::class,
        'group' => $group,
    ]);

    StepDispatcher::dispatch($group);

    // All should be skipped
    foreach ($steps as $step) {
        expect($step->fresh()->state)->toBeInstanceOf(Skipped::class);
    }
});

test('skipped parent only affects its own descendants', function (): void {
    $parentBlockUuid = Str::uuid()->toString();
    $childBlockUuid = Str::uuid()->toString();
    $unrelatedBlockUuid = Str::uuid()->toString();
    $group = $parentBlockUuid;

    // Skipped parent
    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $parentBlockUuid,
        'child_block_uuid' => $childBlockUuid,
        'state' => Skipped::class,
        'group' => $group,
    ]);

    // Child - should be skipped
    $child = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $childBlockUuid,
        'state' => Pending::class,
        'group' => $group,
    ]);

    // Unrelated step in different block - should NOT be skipped
    $unrelated = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $unrelatedBlockUuid,
        'state' => Pending::class,
        'group' => $unrelatedBlockUuid,  // Different group
    ]);

    StepDispatcher::dispatch($group);

    expect($child->fresh()->state)->toBeInstanceOf(Skipped::class);
    expect($unrelated->fresh()->state)->toBeInstanceOf(Pending::class);
});

/*
|--------------------------------------------------------------------------
| Failure Cascade to Children
|--------------------------------------------------------------------------
*/

test('failed parent cascades cancellation to non-terminal children', function (): void {
    $parentBlockUuid = Str::uuid()->toString();
    $childBlockUuid = Str::uuid()->toString();
    $group = $parentBlockUuid;

    // Failed parent
    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $parentBlockUuid,
        'child_block_uuid' => $childBlockUuid,
        'state' => Failed::class,
        'group' => $group,
    ]);

    // Children in various non-terminal states
    $pendingChild = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $childBlockUuid,
        'index' => 1,
        'state' => Pending::class,
        'group' => $group,
    ]);

    $dispatchedChild = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $childBlockUuid,
        'index' => 2,
        'state' => Dispatched::class,
        'group' => $group,
    ]);

    StepDispatcher::dispatch($group);

    // Children that never ran should be Cancelled, not Failed
    // (Failed means "ran and errored", Cancelled means "never had a chance to run")
    expect($pendingChild->fresh()->state)->toBeInstanceOf(Cancelled::class);
    // Dispatched can transition to Cancelled (job queued but not yet running)
    expect($dispatchedChild->fresh()->state)->toBeInstanceOf(Cancelled::class);
});

test('failed parent does not change terminal children states', function (): void {
    $parentBlockUuid = Str::uuid()->toString();
    $childBlockUuid = Str::uuid()->toString();
    $group = $parentBlockUuid;

    // Failed parent
    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $parentBlockUuid,
        'child_block_uuid' => $childBlockUuid,
        'state' => Failed::class,
        'group' => $group,
    ]);

    // Children in terminal states
    $completedChild = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $childBlockUuid,
        'index' => 1,
        'state' => Completed::class,
        'group' => $group,
    ]);

    $skippedChild = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $childBlockUuid,
        'index' => 2,
        'state' => Skipped::class,
        'group' => $group,
    ]);

    $cancelledChild = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $childBlockUuid,
        'index' => 3,
        'state' => Cancelled::class,
        'group' => $group,
    ]);

    StepDispatcher::dispatch($group);

    // Terminal states should remain unchanged
    expect($completedChild->fresh()->state)->toBeInstanceOf(Completed::class);
    expect($skippedChild->fresh()->state)->toBeInstanceOf(Skipped::class);
    expect($cancelledChild->fresh()->state)->toBeInstanceOf(Cancelled::class);
});

test('stopped parent also cascades cancellation to children', function (): void {
    $parentBlockUuid = Str::uuid()->toString();
    $childBlockUuid = Str::uuid()->toString();
    $group = $parentBlockUuid;

    // Stopped parent
    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $parentBlockUuid,
        'child_block_uuid' => $childBlockUuid,
        'state' => Stopped::class,
        'group' => $group,
    ]);

    // Pending child
    $child = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $childBlockUuid,
        'state' => Pending::class,
        'group' => $group,
    ]);

    StepDispatcher::dispatch($group);

    // Children that never ran should be Cancelled, not Failed
    expect($child->fresh()->state)->toBeInstanceOf(Cancelled::class);
});

test('multiple failures in same block only trigger cancellation once', function (): void {
    $blockUuid = Str::uuid()->toString();
    $group = $blockUuid;

    // Multiple failed steps at index 1
    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $blockUuid,
        'index' => 1,
        'state' => Failed::class,
        'group' => $group,
    ]);

    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $blockUuid,
        'index' => 1,
        'state' => Failed::class,
        'group' => $group,
    ]);

    // Downstream step
    $downstream = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $blockUuid,
        'index' => 2,
        'state' => Pending::class,
        'group' => $group,
    ]);

    StepDispatcher::dispatch($group);

    // Should be cancelled (not failed multiple times)
    expect($downstream->fresh()->state)->toBeInstanceOf(Cancelled::class);
});

test('completed step is not affected by later failure', function (): void {
    $blockUuid = Str::uuid()->toString();
    $group = $blockUuid;

    // Step at index 1 - completed first
    $step1 = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $blockUuid,
        'index' => 1,
        'state' => Completed::class,
        'group' => $group,
    ]);

    // Step at index 2 - failed later
    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $blockUuid,
        'index' => 2,
        'state' => Failed::class,
        'group' => $group,
    ]);

    // Step at index 3 - pending
    $step3 = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $blockUuid,
        'index' => 3,
        'state' => Pending::class,
        'group' => $group,
    ]);

    StepDispatcher::dispatch($group);

    // Step 1 unchanged, step 3 cancelled
    expect($step1->fresh()->state)->toBeInstanceOf(Completed::class);
    expect($step3->fresh()->state)->toBeInstanceOf(Cancelled::class);
});
