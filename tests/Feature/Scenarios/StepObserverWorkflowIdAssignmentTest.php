<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\Models\StepsDispatcher;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;

/*
|--------------------------------------------------------------------------
| StepObserver Workflow ID Assignment Tests
|--------------------------------------------------------------------------
|
| These tests verify that the StepObserver correctly assigns workflow_id to steps
| for tracking entire step graphs/trees. The rules are:
|
| 1. Explicit workflow_id passed → use it (genesis step)
| 2. Parent step exists → inherit from parent
| 3. Sibling step in same block_uuid has one → inherit from sibling
| 4. None of above → generate new UUID
|
| workflow_id allows simple queries like: SELECT * FROM steps WHERE workflow_id = ?
| instead of complex recursive CTEs to traverse parent-child relationships.
|
*/

beforeEach(function (): void {
    // Clean up steps from previous tests
    Step::query()->delete();

    // Seed steps_dispatcher groups for round-robin selection
    $groups = ['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta', 'iota', 'kappa'];
    foreach ($groups as $group) {
        StepsDispatcher::firstOrCreate(
            ['group' => $group],
            ['can_dispatch' => true, 'last_selected_at' => null]
        );
    }
    StepsDispatcher::query()->update(['last_selected_at' => null]);
});

/*
|--------------------------------------------------------------------------
| Basic Workflow ID Assignment Tests
|--------------------------------------------------------------------------
*/

test('root step without explicit workflow_id gets new UUID generated', function (): void {
    $step = Step::factory()->create([
        'block_uuid' => Str::uuid()->toString(),
        'workflow_id' => null,
    ]);

    // Should have generated a UUID
    expect($step->workflow_id)->not->toBeNull();
    expect(Str::isUuid($step->workflow_id))->toBeTrue();
});

test('root step with explicit workflow_id keeps that workflow_id', function (): void {
    $explicitWorkflowId = Str::uuid()->toString();

    $step = Step::factory()->create([
        'block_uuid' => Str::uuid()->toString(),
        'workflow_id' => $explicitWorkflowId,
    ]);

    expect($step->workflow_id)->toBe($explicitWorkflowId);
});

test('second step in same block_uuid inherits workflow_id from sibling', function (): void {
    $blockUuid = Str::uuid()->toString();

    $step1 = Step::factory()->create([
        'block_uuid' => $blockUuid,
        'index' => 1,
        'workflow_id' => null,
    ]);

    $step2 = Step::factory()->create([
        'block_uuid' => $blockUuid,
        'index' => 2,
        'workflow_id' => null,
    ]);

    // Both should have same workflow_id
    expect($step1->workflow_id)->not->toBeNull();
    expect($step2->workflow_id)->toBe($step1->workflow_id);
});

test('child step inherits workflow_id from parent', function (): void {
    $parentBlockUuid = Str::uuid()->toString();
    $childBlockUuid = Str::uuid()->toString();

    $parent = Step::factory()->create([
        'block_uuid' => $parentBlockUuid,
        'child_block_uuid' => $childBlockUuid,
        'workflow_id' => null,
    ]);

    $child = Step::factory()->create([
        'block_uuid' => $childBlockUuid,
        'workflow_id' => null,
    ]);

    // Child should inherit parent's workflow_id
    expect($parent->workflow_id)->not->toBeNull();
    expect($child->workflow_id)->toBe($parent->workflow_id);
});

/*
|--------------------------------------------------------------------------
| Deep Nesting Tests
|--------------------------------------------------------------------------
*/

test('grandchild step inherits workflow_id from root (2 levels deep)', function (): void {
    $rootBlockUuid = Str::uuid()->toString();
    $level1BlockUuid = Str::uuid()->toString();
    $level2BlockUuid = Str::uuid()->toString();

    // Root parent
    $rootStep = Step::factory()->create([
        'block_uuid' => $rootBlockUuid,
        'child_block_uuid' => $level1BlockUuid,
        'workflow_id' => null,
    ]);

    $rootWorkflowId = $rootStep->workflow_id;
    expect($rootWorkflowId)->not->toBeNull();

    // Level 1 (child of root, parent of level 2)
    $level1Step = Step::factory()->create([
        'block_uuid' => $level1BlockUuid,
        'child_block_uuid' => $level2BlockUuid,
        'workflow_id' => null,
    ]);

    expect($level1Step->workflow_id)->toBe($rootWorkflowId);

    // Level 2 (grandchild of root)
    $level2Step = Step::factory()->create([
        'block_uuid' => $level2BlockUuid,
        'workflow_id' => null,
    ]);

    expect($level2Step->workflow_id)->toBe($rootWorkflowId);
});

test('5 levels deep all inherit root workflow_id', function (): void {
    $rootBlockUuid = Str::uuid()->toString();
    $level1BlockUuid = Str::uuid()->toString();
    $level2BlockUuid = Str::uuid()->toString();
    $level3BlockUuid = Str::uuid()->toString();
    $level4BlockUuid = Str::uuid()->toString();
    $level5BlockUuid = Str::uuid()->toString();

    // Root parent
    $rootStep = Step::factory()->create([
        'block_uuid' => $rootBlockUuid,
        'child_block_uuid' => $level1BlockUuid,
        'workflow_id' => null,
    ]);

    $rootWorkflowId = $rootStep->workflow_id;
    expect($rootWorkflowId)->not->toBeNull();

    // Level 1
    $level1Step = Step::factory()->create([
        'block_uuid' => $level1BlockUuid,
        'child_block_uuid' => $level2BlockUuid,
        'workflow_id' => null,
    ]);

    // Level 2
    $level2Step = Step::factory()->create([
        'block_uuid' => $level2BlockUuid,
        'child_block_uuid' => $level3BlockUuid,
        'workflow_id' => null,
    ]);

    // Level 3
    $level3Step = Step::factory()->create([
        'block_uuid' => $level3BlockUuid,
        'child_block_uuid' => $level4BlockUuid,
        'workflow_id' => null,
    ]);

    // Level 4
    $level4Step = Step::factory()->create([
        'block_uuid' => $level4BlockUuid,
        'child_block_uuid' => $level5BlockUuid,
        'workflow_id' => null,
    ]);

    // Level 5 (leaf)
    $level5Step = Step::factory()->create([
        'block_uuid' => $level5BlockUuid,
        'workflow_id' => null,
    ]);

    // All steps should share the root workflow_id
    expect($level1Step->workflow_id)->toBe($rootWorkflowId);
    expect($level2Step->workflow_id)->toBe($rootWorkflowId);
    expect($level3Step->workflow_id)->toBe($rootWorkflowId);
    expect($level4Step->workflow_id)->toBe($rootWorkflowId);
    expect($level5Step->workflow_id)->toBe($rootWorkflowId);
});

/*
|--------------------------------------------------------------------------
| Parallel Steps Tests
|--------------------------------------------------------------------------
*/

test('multiple parallel root steps at same index get same workflow_id via sibling inheritance', function (): void {
    $blockUuid = Str::uuid()->toString();

    // Create 5 parallel steps at index 1 (like in production)
    $step1 = Step::factory()->create([
        'block_uuid' => $blockUuid,
        'index' => 1,
        'workflow_id' => null,
    ]);

    $step2 = Step::factory()->create([
        'block_uuid' => $blockUuid,
        'index' => 1,
        'workflow_id' => null,
    ]);

    $step3 = Step::factory()->create([
        'block_uuid' => $blockUuid,
        'index' => 1,
        'workflow_id' => null,
    ]);

    $step4 = Step::factory()->create([
        'block_uuid' => $blockUuid,
        'index' => 1,
        'workflow_id' => null,
    ]);

    $step5 = Step::factory()->create([
        'block_uuid' => $blockUuid,
        'index' => 1,
        'workflow_id' => null,
    ]);

    // All should share the workflow_id from the first step
    $workflowId = $step1->workflow_id;
    expect($workflowId)->not->toBeNull();
    expect($step2->workflow_id)->toBe($workflowId);
    expect($step3->workflow_id)->toBe($workflowId);
    expect($step4->workflow_id)->toBe($workflowId);
    expect($step5->workflow_id)->toBe($workflowId);
});

test('parallel children all inherit parent workflow_id', function (): void {
    $parentBlockUuid = Str::uuid()->toString();
    $childBlockUuid = Str::uuid()->toString();

    $parent = Step::factory()->create([
        'block_uuid' => $parentBlockUuid,
        'child_block_uuid' => $childBlockUuid,
        'workflow_id' => null,
    ]);

    $parentWorkflowId = $parent->workflow_id;

    // Create parallel children (same index = parallel execution)
    $child1 = Step::factory()->create([
        'block_uuid' => $childBlockUuid,
        'index' => 1,
        'workflow_id' => null,
    ]);

    $child2 = Step::factory()->create([
        'block_uuid' => $childBlockUuid,
        'index' => 1,
        'workflow_id' => null,
    ]);

    $child3 = Step::factory()->create([
        'block_uuid' => $childBlockUuid,
        'index' => 1,
        'workflow_id' => null,
    ]);

    expect($child1->workflow_id)->toBe($parentWorkflowId);
    expect($child2->workflow_id)->toBe($parentWorkflowId);
    expect($child3->workflow_id)->toBe($parentWorkflowId);
});

test('sequential children all inherit parent workflow_id', function (): void {
    $parentBlockUuid = Str::uuid()->toString();
    $childBlockUuid = Str::uuid()->toString();

    $parent = Step::factory()->create([
        'block_uuid' => $parentBlockUuid,
        'child_block_uuid' => $childBlockUuid,
        'workflow_id' => null,
    ]);

    $parentWorkflowId = $parent->workflow_id;

    // Create sequential children (different indices)
    $child1 = Step::factory()->create([
        'block_uuid' => $childBlockUuid,
        'index' => 1,
        'workflow_id' => null,
    ]);

    $child2 = Step::factory()->create([
        'block_uuid' => $childBlockUuid,
        'index' => 2,
        'workflow_id' => null,
    ]);

    $child3 = Step::factory()->create([
        'block_uuid' => $childBlockUuid,
        'index' => 3,
        'workflow_id' => null,
    ]);

    expect($child1->workflow_id)->toBe($parentWorkflowId);
    expect($child2->workflow_id)->toBe($parentWorkflowId);
    expect($child3->workflow_id)->toBe($parentWorkflowId);
});

/*
|--------------------------------------------------------------------------
| Isolation Tests - Different Workflows Should Have Different IDs
|--------------------------------------------------------------------------
*/

test('two separate root blocks get different workflow_ids', function (): void {
    $blockUuid1 = Str::uuid()->toString();
    $blockUuid2 = Str::uuid()->toString();

    $step1 = Step::factory()->create([
        'block_uuid' => $blockUuid1,
        'workflow_id' => null,
    ]);

    $step2 = Step::factory()->create([
        'block_uuid' => $blockUuid2,
        'workflow_id' => null,
    ]);

    // Different blocks should have different workflow_ids
    expect($step1->workflow_id)->not->toBeNull();
    expect($step2->workflow_id)->not->toBeNull();
    expect($step1->workflow_id)->not->toBe($step2->workflow_id);
});

test('two separate workflows with children are fully isolated', function (): void {
    // Workflow 1
    $wf1RootBlock = Str::uuid()->toString();
    $wf1ChildBlock = Str::uuid()->toString();

    $wf1Root = Step::factory()->create([
        'block_uuid' => $wf1RootBlock,
        'child_block_uuid' => $wf1ChildBlock,
        'workflow_id' => null,
    ]);

    $wf1Child = Step::factory()->create([
        'block_uuid' => $wf1ChildBlock,
        'workflow_id' => null,
    ]);

    // Workflow 2
    $wf2RootBlock = Str::uuid()->toString();
    $wf2ChildBlock = Str::uuid()->toString();

    $wf2Root = Step::factory()->create([
        'block_uuid' => $wf2RootBlock,
        'child_block_uuid' => $wf2ChildBlock,
        'workflow_id' => null,
    ]);

    $wf2Child = Step::factory()->create([
        'block_uuid' => $wf2ChildBlock,
        'workflow_id' => null,
    ]);

    // Within workflow 1, all share same ID
    expect($wf1Child->workflow_id)->toBe($wf1Root->workflow_id);

    // Within workflow 2, all share same ID
    expect($wf2Child->workflow_id)->toBe($wf2Root->workflow_id);

    // Between workflows, IDs are different
    expect($wf1Root->workflow_id)->not->toBe($wf2Root->workflow_id);
});

/*
|--------------------------------------------------------------------------
| Edge Cases
|--------------------------------------------------------------------------
*/

test('explicit workflow_id overrides any inheritance', function (): void {
    $parentBlockUuid = Str::uuid()->toString();
    $childBlockUuid = Str::uuid()->toString();
    $explicitWorkflowId = Str::uuid()->toString();

    $parent = Step::factory()->create([
        'block_uuid' => $parentBlockUuid,
        'child_block_uuid' => $childBlockUuid,
        'workflow_id' => null,
    ]);

    // Child with explicit workflow_id should NOT inherit
    $child = Step::factory()->create([
        'block_uuid' => $childBlockUuid,
        'workflow_id' => $explicitWorkflowId,
    ]);

    expect($child->workflow_id)->toBe($explicitWorkflowId);
    expect($child->workflow_id)->not->toBe($parent->workflow_id);
});

test('sibling created after parent inheritance still works', function (): void {
    $parentBlockUuid = Str::uuid()->toString();
    $childBlockUuid = Str::uuid()->toString();

    $parent = Step::factory()->create([
        'block_uuid' => $parentBlockUuid,
        'child_block_uuid' => $childBlockUuid,
        'workflow_id' => null,
    ]);

    $parentWorkflowId = $parent->workflow_id;

    // First child inherits from parent
    $child1 = Step::factory()->create([
        'block_uuid' => $childBlockUuid,
        'index' => 1,
        'workflow_id' => null,
    ]);

    expect($child1->workflow_id)->toBe($parentWorkflowId);

    // Second child should inherit from sibling (or parent - same result)
    $child2 = Step::factory()->create([
        'block_uuid' => $childBlockUuid,
        'index' => 2,
        'workflow_id' => null,
    ]);

    expect($child2->workflow_id)->toBe($parentWorkflowId);
});

test('workflow_id is preserved on step state transitions', function (): void {
    $step = Step::factory()->create([
        'block_uuid' => Str::uuid()->toString(),
        'workflow_id' => null,
        'state' => Pending::class,
    ]);

    $originalWorkflowId = $step->workflow_id;
    expect($originalWorkflowId)->not->toBeNull();

    // Transition to Running
    $step->state->transitionTo(Running::class);
    $step->refresh();
    expect($step->workflow_id)->toBe($originalWorkflowId);

    // Transition to Completed
    $step->state->transitionTo(Completed::class);
    $step->refresh();
    expect($step->workflow_id)->toBe($originalWorkflowId);
});

test('late-created children still inherit correct workflow_id', function (): void {
    // This simulates lazy child creation (parent starts running, then creates children)
    $parentBlockUuid = Str::uuid()->toString();
    $childBlockUuid = Str::uuid()->toString();

    $parent = Step::factory()->create([
        'block_uuid' => $parentBlockUuid,
        'child_block_uuid' => $childBlockUuid,
        'workflow_id' => null,
        'state' => Pending::class,
    ]);

    $parentWorkflowId = $parent->workflow_id;

    // Parent transitions to running
    $parent->state->transitionTo(Running::class);
    $parent->save();

    // Now children are created (late/lazy creation)
    $child1 = Step::factory()->create([
        'block_uuid' => $childBlockUuid,
        'workflow_id' => null,
    ]);

    $child2 = Step::factory()->create([
        'block_uuid' => $childBlockUuid,
        'workflow_id' => null,
    ]);

    // Children should still inherit from parent
    expect($child1->workflow_id)->toBe($parentWorkflowId);
    expect($child2->workflow_id)->toBe($parentWorkflowId);
});

/*
|--------------------------------------------------------------------------
| Query Tests - Verify workflow_id enables simple graph queries
|--------------------------------------------------------------------------
*/

test('can query entire workflow graph with single where clause', function (): void {
    // Create a complex workflow: root -> 2 children -> 1 grandchild each
    $rootBlockUuid = Str::uuid()->toString();
    $child1BlockUuid = Str::uuid()->toString();
    $child2BlockUuid = Str::uuid()->toString();
    $grandchild1BlockUuid = Str::uuid()->toString();
    $grandchild2BlockUuid = Str::uuid()->toString();

    // Root
    $root = Step::factory()->create([
        'block_uuid' => $rootBlockUuid,
        'child_block_uuid' => $child1BlockUuid,
        'workflow_id' => null,
    ]);

    $workflowId = $root->workflow_id;

    // Child 1 (parent of grandchild 1)
    Step::factory()->create([
        'block_uuid' => $child1BlockUuid,
        'child_block_uuid' => $grandchild1BlockUuid,
        'workflow_id' => null,
    ]);

    // Child 2 at index 2 (parent of grandchild 2)
    // First need another root for child_block_uuid = child2BlockUuid
    $root2 = Step::factory()->create([
        'block_uuid' => $rootBlockUuid,
        'child_block_uuid' => $child2BlockUuid,
        'index' => 2,
        'workflow_id' => null,
    ]);

    Step::factory()->create([
        'block_uuid' => $child2BlockUuid,
        'child_block_uuid' => $grandchild2BlockUuid,
        'workflow_id' => null,
    ]);

    // Grandchildren
    Step::factory()->create([
        'block_uuid' => $grandchild1BlockUuid,
        'workflow_id' => null,
    ]);

    Step::factory()->create([
        'block_uuid' => $grandchild2BlockUuid,
        'workflow_id' => null,
    ]);

    // Query all steps in this workflow
    $workflowSteps = Step::where('workflow_id', $workflowId)->get();

    // Should have all 6 steps
    expect($workflowSteps)->toHaveCount(6);

    // All should have the same workflow_id
    $workflowSteps->each(function (Step $step) use ($workflowId): void {
        expect($step->workflow_id)->toBe($workflowId);
    });
});

test('workflow_id query does not include steps from other workflows', function (): void {
    // Workflow A
    $wfABlockUuid = Str::uuid()->toString();
    $wfAChildBlockUuid = Str::uuid()->toString();

    $wfARoot = Step::factory()->create([
        'block_uuid' => $wfABlockUuid,
        'child_block_uuid' => $wfAChildBlockUuid,
        'workflow_id' => null,
    ]);

    Step::factory()->create([
        'block_uuid' => $wfAChildBlockUuid,
        'workflow_id' => null,
    ]);

    // Workflow B
    $wfBBlockUuid = Str::uuid()->toString();
    $wfBChildBlockUuid = Str::uuid()->toString();

    $wfBRoot = Step::factory()->create([
        'block_uuid' => $wfBBlockUuid,
        'child_block_uuid' => $wfBChildBlockUuid,
        'workflow_id' => null,
    ]);

    Step::factory()->create([
        'block_uuid' => $wfBChildBlockUuid,
        'workflow_id' => null,
    ]);

    // Query workflow A
    $wfASteps = Step::where('workflow_id', $wfARoot->workflow_id)->get();
    expect($wfASteps)->toHaveCount(2);

    // Verify none have workflow B's ID
    $wfASteps->each(function (Step $step) use ($wfBRoot): void {
        expect($step->workflow_id)->not->toBe($wfBRoot->workflow_id);
    });

    // Query workflow B
    $wfBSteps = Step::where('workflow_id', $wfBRoot->workflow_id)->get();
    expect($wfBSteps)->toHaveCount(2);

    // Verify none have workflow A's ID
    $wfBSteps->each(function (Step $step) use ($wfARoot): void {
        expect($step->workflow_id)->not->toBe($wfARoot->workflow_id);
    });
});

/*
|--------------------------------------------------------------------------
| Mixed Scenarios
|--------------------------------------------------------------------------
*/

test('complex workflow with parallel and sequential steps all share workflow_id', function (): void {
    $rootBlockUuid = Str::uuid()->toString();
    $childBlockUuid = Str::uuid()->toString();

    // Root with explicit workflow_id (genesis)
    $explicitWorkflowId = Str::uuid()->toString();

    $root = Step::factory()->create([
        'block_uuid' => $rootBlockUuid,
        'child_block_uuid' => $childBlockUuid,
        'workflow_id' => $explicitWorkflowId,
    ]);

    // 3 parallel children at index 1
    $parallel1 = Step::factory()->create([
        'block_uuid' => $childBlockUuid,
        'index' => 1,
        'workflow_id' => null,
    ]);

    $parallel2 = Step::factory()->create([
        'block_uuid' => $childBlockUuid,
        'index' => 1,
        'workflow_id' => null,
    ]);

    $parallel3 = Step::factory()->create([
        'block_uuid' => $childBlockUuid,
        'index' => 1,
        'workflow_id' => null,
    ]);

    // 2 sequential children at index 2 and 3
    $sequential1 = Step::factory()->create([
        'block_uuid' => $childBlockUuid,
        'index' => 2,
        'workflow_id' => null,
    ]);

    $sequential2 = Step::factory()->create([
        'block_uuid' => $childBlockUuid,
        'index' => 3,
        'workflow_id' => null,
    ]);

    // All should share the explicit workflow_id
    expect($root->workflow_id)->toBe($explicitWorkflowId);
    expect($parallel1->workflow_id)->toBe($explicitWorkflowId);
    expect($parallel2->workflow_id)->toBe($explicitWorkflowId);
    expect($parallel3->workflow_id)->toBe($explicitWorkflowId);
    expect($sequential1->workflow_id)->toBe($explicitWorkflowId);
    expect($sequential2->workflow_id)->toBe($explicitWorkflowId);

    // Query should return all 6
    $allSteps = Step::where('workflow_id', $explicitWorkflowId)->get();
    expect($allSteps)->toHaveCount(6);
});

test('resolve-exception steps inherit workflow_id from same block', function (): void {
    $blockUuid = Str::uuid()->toString();

    // Default step
    $defaultStep = Step::factory()->create([
        'block_uuid' => $blockUuid,
        'type' => 'default',
        'workflow_id' => null,
    ]);

    $workflowId = $defaultStep->workflow_id;

    // Resolve-exception step in same block
    $resolveStep = Step::factory()->create([
        'block_uuid' => $blockUuid,
        'type' => 'resolve-exception',
        'workflow_id' => null,
    ]);

    expect($resolveStep->workflow_id)->toBe($workflowId);
});

/*
|--------------------------------------------------------------------------
| Stress Tests
|--------------------------------------------------------------------------
*/

test('100 steps in same workflow all share workflow_id', function (): void {
    $blockUuid = Str::uuid()->toString();

    $steps = [];
    for ($i = 0; $i < 100; $i++) {
        $steps[] = Step::factory()->create([
            'block_uuid' => $blockUuid,
            'index' => ($i % 10) + 1, // Distribute across 10 indices
            'workflow_id' => null,
        ]);
    }

    $workflowId = $steps[0]->workflow_id;
    expect($workflowId)->not->toBeNull();

    // All should share the same workflow_id
    foreach ($steps as $step) {
        expect($step->workflow_id)->toBe($workflowId);
    }

    // Query should return all 100
    $queriedSteps = Step::where('workflow_id', $workflowId)->get();
    expect($queriedSteps)->toHaveCount(100);
});

test('many independent workflows remain isolated', function (): void {
    $workflowIds = [];

    // Create 20 independent workflows
    for ($i = 0; $i < 20; $i++) {
        $step = Step::factory()->create([
            'block_uuid' => Str::uuid()->toString(),
            'workflow_id' => null,
        ]);
        $workflowIds[] = $step->workflow_id;
    }

    // All workflow_ids should be unique
    $uniqueIds = array_unique($workflowIds);
    expect(count($uniqueIds))->toBe(20);

    // Each workflow should have exactly 1 step
    foreach ($workflowIds as $wfId) {
        $count = Step::where('workflow_id', $wfId)->count();
        expect($count)->toBe(1);
    }
});
