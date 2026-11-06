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
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Tests\Fixtures\ScenarioTestJob;

/*
|--------------------------------------------------------------------------
| Group Isolation Tests
|--------------------------------------------------------------------------
|
| These tests verify that different groups (workflows) are isolated and
| do not interfere with each other. Key behaviors:
|
| - Each workflow gets its own group
| - Dispatching with group filter only affects that group
| - Failures in one group don't cascade to other groups
| - Parallel processing of multiple groups is possible
|
*/

beforeEach(function (): void {
    StepsDispatcher::query()->delete();
    Queue::fake();

    // Seed named groups for round-robin tests
    $groups = ['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta', 'iota', 'kappa'];
    foreach ($groups as $group) {
        StepsDispatcher::query()->forceCreate(['group' => $group, 'can_dispatch' => true, 'last_selected_at' => null]);
    }
});

test('dispatching with group filter only affects that group', function (): void {
    $group1 = Str::uuid()->toString();
    $group2 = Str::uuid()->toString();

    // Steps in group 1
    $step1Group1 = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $group1,
        'index' => 1,
        'state' => Pending::class,
        'group' => $group1,
    ]);

    // Steps in group 2
    $step1Group2 = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $group2,
        'index' => 1,
        'state' => Pending::class,
        'group' => $group2,
    ]);

    // Dispatch only group 1
    StepDispatcher::dispatch($group1);

    // Group 1 step dispatched
    expect($step1Group1->fresh()->state)->toBeInstanceOf(Dispatched::class);

    // Group 2 step unchanged
    expect($step1Group2->fresh()->state)->toBeInstanceOf(Pending::class);
});

test('failure in one group does not affect other groups', function (): void {
    $group1 = Str::uuid()->toString();
    $group2 = Str::uuid()->toString();

    // Group 1: Failed step
    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $group1,
        'index' => 1,
        'state' => Failed::class,
        'group' => $group1,
    ]);

    $step2Group1 = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $group1,
        'index' => 2,
        'state' => Pending::class,
        'group' => $group1,
    ]);

    // Group 2: Normal pending step
    $step1Group2 = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $group2,
        'index' => 1,
        'state' => Pending::class,
        'group' => $group2,
    ]);

    // Dispatch group 1
    StepDispatcher::dispatch($group1);

    // Group 1: Step cancelled due to failure
    expect($step2Group1->fresh()->state)->toBeInstanceOf(Cancelled::class);

    // Group 2: Unaffected
    expect($step1Group2->fresh()->state)->toBeInstanceOf(Pending::class);

    // Dispatch group 2
    StepDispatcher::dispatch($group2);

    // Group 2: Step dispatched normally
    expect($step1Group2->fresh()->state)->toBeInstanceOf(Dispatched::class);
});

test('parent completion in one group does not affect other groups', function (): void {
    $group1 = Str::uuid()->toString();
    $childBlock1 = Str::uuid()->toString();
    $group2 = Str::uuid()->toString();
    $childBlock2 = Str::uuid()->toString();

    // Group 1: Parent with completed children
    $parent1 = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $group1,
        'child_block_uuid' => $childBlock1,
        'state' => Running::class,
        'group' => $group1,
    ]);

    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $childBlock1,
        'state' => Completed::class,
        'group' => $group1,
    ]);

    // Group 2: Parent with pending children
    $parent2 = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $group2,
        'child_block_uuid' => $childBlock2,
        'state' => Running::class,
        'group' => $group2,
    ]);

    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $childBlock2,
        'state' => Pending::class,
        'group' => $group2,
    ]);

    // Dispatch group 1
    StepDispatcher::dispatch($group1);

    // Group 1: Parent completed
    expect($parent1->fresh()->state)->toBeInstanceOf(Completed::class);

    // Group 2: Parent still running
    expect($parent2->fresh()->state)->toBeInstanceOf(Running::class);
});

test('skipped parent in one group does not skip children in other groups', function (): void {
    $group1 = Str::uuid()->toString();
    $childBlock1 = Str::uuid()->toString();
    $group2 = Str::uuid()->toString();
    $childBlock2 = Str::uuid()->toString();

    // Group 1: Skipped parent
    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $group1,
        'child_block_uuid' => $childBlock1,
        'state' => Skipped::class,
        'group' => $group1,
    ]);

    $child1 = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $childBlock1,
        'state' => Pending::class,
        'group' => $group1,
    ]);

    // Group 2: Normal parent and child
    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $group2,
        'child_block_uuid' => $childBlock2,
        'state' => Running::class,
        'group' => $group2,
    ]);

    $child2 = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $childBlock2,
        'state' => Pending::class,
        'group' => $group2,
    ]);

    // Dispatch group 1
    StepDispatcher::dispatch($group1);

    // Group 1: Child skipped
    expect($child1->fresh()->state)->toBeInstanceOf(Skipped::class);

    // Group 2: Child unchanged
    expect($child2->fresh()->state)->toBeInstanceOf(Pending::class);

    // Dispatch group 2
    StepDispatcher::dispatch($group2);

    // Group 2: Child dispatched
    expect($child2->fresh()->state)->toBeInstanceOf(Dispatched::class);
});

test('multiple groups can be processed independently', function (): void {
    $groups = [];
    $steps = [];

    // Create 5 independent groups
    for ($i = 1; $i <= 5; $i++) {
        $groups[$i] = Str::uuid()->toString();
        $steps[$i] = Step::factory()->create([
            'class' => ScenarioTestJob::class,
            'block_uuid' => $groups[$i],
            'index' => 1,
            'state' => Pending::class,
            'group' => $groups[$i],
        ]);
    }

    // Process each group independently
    foreach ($groups as $i => $group) {
        StepDispatcher::dispatch($group);

        // Only current group's step should be dispatched
        expect($steps[$i]->fresh()->state)->toBeInstanceOf(Dispatched::class);

        // Other groups unaffected
        foreach ($steps as $j => $step) {
            if ($j <= $i) {
                continue;
            }

            expect($step->fresh()->state)->toBeInstanceOf(Pending::class);
        }
    }
});

test('resolve-exception promotion is isolated per group', function (): void {
    $group1 = Str::uuid()->toString();
    $group2 = Str::uuid()->toString();

    // Group 1: Failed with resolve-exception
    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $group1,
        'type' => 'default',
        'state' => Failed::class,
        'group' => $group1,
    ]);

    $resolve1 = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $group1,
        'type' => 'resolve-exception',
        'state' => NotRunnable::class,
        'group' => $group1,
    ]);

    // Group 2: No failure, has resolve-exception
    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $group2,
        'type' => 'default',
        'state' => Completed::class,
        'group' => $group2,
    ]);

    $resolve2 = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $group2,
        'type' => 'resolve-exception',
        'state' => NotRunnable::class,
        'group' => $group2,
    ]);

    // Dispatch group 1
    StepDispatcher::dispatch($group1);

    // Group 1: Resolve-exception promoted
    expect($resolve1->fresh()->state)->toBeInstanceOf(Pending::class);

    // Group 2: Resolve-exception unchanged
    expect($resolve2->fresh()->state)->toBeInstanceOf(NotRunnable::class);
});

test('sequential ordering is maintained per group', function (): void {
    $group1 = Str::uuid()->toString();
    $group2 = Str::uuid()->toString();

    // Group 1: Index 1 completed, index 2 pending
    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $group1,
        'index' => 1,
        'state' => Completed::class,
        'group' => $group1,
    ]);

    $step2Group1 = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $group1,
        'index' => 2,
        'state' => Pending::class,
        'group' => $group1,
    ]);

    // Group 2: Index 1 still pending, index 2 pending
    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $group2,
        'index' => 1,
        'state' => Pending::class,
        'group' => $group2,
    ]);

    $step2Group2 = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $group2,
        'index' => 2,
        'state' => Pending::class,
        'group' => $group2,
    ]);

    // Dispatch group 1
    StepDispatcher::dispatch($group1);

    // Group 1: Index 2 can dispatch
    expect($step2Group1->fresh()->state)->toBeInstanceOf(Dispatched::class);

    // Dispatch group 2
    StepDispatcher::dispatch($group2);

    // Group 2: Index 2 cannot dispatch (index 1 not done)
    expect($step2Group2->fresh()->state)->toBeInstanceOf(Pending::class);
});

test('null group step is not processed when specific group dispatched', function (): void {
    $group1 = Str::uuid()->toString();

    // Step with specific group
    $stepWithGroup = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $group1,
        'index' => 1,
        'state' => Pending::class,
        'group' => $group1,
    ]);

    // Step with null group (observer should assign one, but let's test edge case)
    $nullGroupBlock = Str::uuid()->toString();
    $stepNullGroup = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $nullGroupBlock,
        'index' => 1,
        'state' => Pending::class,
        'group' => null,  // Will be assigned by observer
    ]);

    // The observer assigns group = block_uuid, so it won't be null
    // But dispatch with group1 should only affect group1
    StepDispatcher::dispatch($group1);

    expect($stepWithGroup->fresh()->state)->toBeInstanceOf(Dispatched::class);
    // stepNullGroup has its own group, so should be unchanged
    expect($stepNullGroup->fresh()->state)->toBeInstanceOf(Pending::class);
});

test('complex workflow with multiple nested groups stays isolated', function (): void {
    // Workflow 1: 3-level deep hierarchy
    $workflow1Root = Str::uuid()->toString();
    $workflow1Level1 = Str::uuid()->toString();
    $workflow1Level2 = Str::uuid()->toString();
    $group1 = $workflow1Root;

    $w1Root = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $workflow1Root,
        'child_block_uuid' => $workflow1Level1,
        'state' => Running::class,
        'group' => $group1,
    ]);

    $w1L1 = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $workflow1Level1,
        'child_block_uuid' => $workflow1Level2,
        'state' => Running::class,
        'group' => $group1,
    ]);

    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $workflow1Level2,
        'state' => Completed::class,
        'group' => $group1,
    ]);

    // Workflow 2: Simple pending step
    $workflow2Root = Str::uuid()->toString();
    $group2 = $workflow2Root;

    $w2Root = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $workflow2Root,
        'index' => 1,
        'state' => Pending::class,
        'group' => $group2,
    ]);

    // Process workflow 1 - should complete hierarchy
    StepDispatcher::dispatch($group1);
    expect($w1L1->fresh()->state)->toBeInstanceOf(Completed::class);

    StepDispatcher::dispatch($group1);
    expect($w1Root->fresh()->state)->toBeInstanceOf(Completed::class);

    // Workflow 2 should be completely unaffected
    expect($w2Root->fresh()->state)->toBeInstanceOf(Pending::class);

    // Process workflow 2
    StepDispatcher::dispatch($group2);
    expect($w2Root->fresh()->state)->toBeInstanceOf(Dispatched::class);
});

test('get active groups returns only groups with non-terminal steps', function (): void {
    $activeGroup1 = Str::uuid()->toString();
    $activeGroup2 = Str::uuid()->toString();
    $terminalGroup = Str::uuid()->toString();

    // Active groups (have pending steps)
    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $activeGroup1,
        'state' => Pending::class,
        'group' => $activeGroup1,
    ]);

    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $activeGroup2,
        'state' => Running::class,
        'group' => $activeGroup2,
    ]);

    // Terminal group (only completed steps)
    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $terminalGroup,
        'state' => Completed::class,
        'group' => $terminalGroup,
    ]);

    // Get active groups
    $activeGroups = Step::query()
        ->whereNotIn('state', Step::terminalStepStates())
        ->whereNotNull('group')
        ->distinct()
        ->pluck('group')
        ->all();

    expect($activeGroups)->toContain($activeGroup1);
    expect($activeGroups)->toContain($activeGroup2);
    expect($activeGroups)->not->toContain($terminalGroup);
});

test('10 concurrent workflows with different states', function (): void {
    $workflows = [];
    $steps = [];

    // Create 10 workflows with different states
    for ($i = 1; $i <= 10; $i++) {
        $workflows[$i] = Str::uuid()->toString();

        // Alternate between different initial states
        $state = match ($i % 3) {
            0 => Pending::class,
            1 => Running::class,
            2 => Completed::class,
        };

        $steps[$i] = Step::factory()->create([
            'class' => ScenarioTestJob::class,
            'block_uuid' => $workflows[$i],
            'state' => $state,
            'group' => $workflows[$i],
        ]);
    }

    // Process only pending workflows
    foreach ($workflows as $i => $group) {
        if (! ($steps[$i]->state instanceof Pending)) {
            continue;
        }

        StepDispatcher::dispatch($group);
    }

    // Verify states
    foreach ($steps as $i => $step) {
        $originalState = match ($i % 3) {
            0 => Dispatched::class,  // Pending -> Dispatched
            1 => Running::class,      // Running stays Running
            2 => Completed::class,    // Completed stays Completed
        };

        expect($step->fresh()->state)->toBeInstanceOf($originalState);
    }
});

/*
|--------------------------------------------------------------------------
| Named Group Parallelism Tests
|--------------------------------------------------------------------------
|
| These tests verify that the named groups (alpha, beta, etc.) work correctly
| with round-robin assignment and parallel processing.
|
*/

test('named groups are assigned via round-robin', function (): void {
    // Reset last_selected_at for predictable order
    StepsDispatcher::query()->update(['last_selected_at' => null]);

    $assignedGroups = [];
    for ($i = 0; $i < 10; $i++) {
        $step = Step::factory()->create([
            'class' => ScenarioTestJob::class,
            'block_uuid' => Str::uuid()->toString(),
            'group' => null,
        ]);
        $assignedGroups[] = $step->group;
    }

    // All 10 named groups should be assigned
    $uniqueGroups = array_unique($assignedGroups);
    expect(count($uniqueGroups))->toBe(10);

    // Each should be one of the named groups
    $namedGroups = ['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta', 'iota', 'kappa'];
    foreach ($uniqueGroups as $group) {
        expect($namedGroups)->toContain($group);
    }
});

test('workflows in same named group are processed together', function (): void {
    // Reset for predictable assignment
    StepsDispatcher::query()->update(['last_selected_at' => null]);

    // Create 2 workflows that will both get 'alpha' (first one gets alpha, then next gets beta, etc.)
    // We manually assign to same group to test this behavior
    $step1 = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => Str::uuid()->toString(),
        'index' => 1,
        'state' => Pending::class,
        'group' => 'alpha',
    ]);

    $step2 = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => Str::uuid()->toString(),
        'index' => 1,
        'state' => Pending::class,
        'group' => 'alpha',
    ]);

    // Dispatch 'alpha' group - both steps should be dispatched
    StepDispatcher::dispatch('alpha');

    expect($step1->fresh()->state)->toBeInstanceOf(Dispatched::class);
    expect($step2->fresh()->state)->toBeInstanceOf(Dispatched::class);
});

test('workflows in different named groups are isolated', function (): void {
    $stepAlpha = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => Str::uuid()->toString(),
        'index' => 1,
        'state' => Pending::class,
        'group' => 'alpha',
    ]);

    $stepBeta = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => Str::uuid()->toString(),
        'index' => 1,
        'state' => Pending::class,
        'group' => 'beta',
    ]);

    // Dispatch alpha only
    StepDispatcher::dispatch('alpha');

    expect($stepAlpha->fresh()->state)->toBeInstanceOf(Dispatched::class);
    expect($stepBeta->fresh()->state)->toBeInstanceOf(Pending::class);

    // Dispatch beta
    StepDispatcher::dispatch('beta');

    expect($stepBeta->fresh()->state)->toBeInstanceOf(Dispatched::class);
});

test('failure in alpha does not affect beta', function (): void {
    // Alpha: has a failed step
    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => 'alpha-workflow',
        'index' => 1,
        'state' => Failed::class,
        'group' => 'alpha',
    ]);

    $alphaPending = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => 'alpha-workflow',
        'index' => 2,
        'state' => Pending::class,
        'group' => 'alpha',
    ]);

    // Beta: normal workflow
    $betaPending = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => 'beta-workflow',
        'index' => 1,
        'state' => Pending::class,
        'group' => 'beta',
    ]);

    // Dispatch alpha - downstream should be cancelled
    StepDispatcher::dispatch('alpha');
    expect($alphaPending->fresh()->state)->toBeInstanceOf(Cancelled::class);

    // Beta should be unaffected
    expect($betaPending->fresh()->state)->toBeInstanceOf(Pending::class);

    // Dispatch beta - should work normally
    StepDispatcher::dispatch('beta');
    expect($betaPending->fresh()->state)->toBeInstanceOf(Dispatched::class);
});

test('all 10 named groups can process in parallel', function (): void {
    $namedGroups = ['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta', 'iota', 'kappa'];
    $steps = [];

    // Create a pending step in each named group
    foreach ($namedGroups as $group) {
        $steps[$group] = Step::factory()->create([
            'class' => ScenarioTestJob::class,
            'block_uuid' => "{$group}-workflow",
            'index' => 1,
            'state' => Pending::class,
            'group' => $group,
        ]);
    }

    // Dispatch all groups
    foreach ($namedGroups as $group) {
        StepDispatcher::dispatch($group);
    }

    // All steps should be dispatched
    foreach ($namedGroups as $group) {
        expect($steps[$group]->fresh()->state)->toBeInstanceOf(Dispatched::class);
    }
});

test('children inherit named group from parent', function (): void {
    $childBlock = Str::uuid()->toString();

    // Parent in 'gamma' group
    $parent = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => 'gamma-root',
        'child_block_uuid' => $childBlock,
        'state' => Running::class,
        'group' => 'gamma',
    ]);

    // Child should inherit 'gamma'
    $child = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $childBlock,
        'index' => 1,
        'state' => Pending::class,
        'group' => null,  // Will inherit from parent
    ]);

    expect($child->group)->toBe('gamma');

    // Dispatch gamma - child should be dispatched
    StepDispatcher::dispatch('gamma');
    expect($child->fresh()->state)->toBeInstanceOf(Dispatched::class);
});

test('complex workflow with named groups stays isolated', function (): void {
    $alphaChild = Str::uuid()->toString();
    $betaChild = Str::uuid()->toString();

    // Alpha workflow: 2-level hierarchy
    $alphaParent = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => 'alpha-root',
        'child_block_uuid' => $alphaChild,
        'state' => Running::class,
        'group' => 'alpha',
    ]);

    Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $alphaChild,
        'state' => Completed::class,
        'group' => 'alpha',
    ]);

    // Beta workflow: 2-level hierarchy with pending child
    $betaParent = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => 'beta-root',
        'child_block_uuid' => $betaChild,
        'state' => Running::class,
        'group' => 'beta',
    ]);

    $betaChildStep = Step::factory()->create([
        'class' => ScenarioTestJob::class,
        'block_uuid' => $betaChild,
        'state' => Pending::class,
        'group' => 'beta',
    ]);

    // Dispatch alpha - parent should complete (all children done)
    StepDispatcher::dispatch('alpha');
    expect($alphaParent->fresh()->state)->toBeInstanceOf(Completed::class);

    // Beta should be unaffected
    expect($betaParent->fresh()->state)->toBeInstanceOf(Running::class);
    expect($betaChildStep->fresh()->state)->toBeInstanceOf(Pending::class);
});
