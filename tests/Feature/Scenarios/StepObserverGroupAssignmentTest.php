<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\Models\StepsDispatcher;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;

/*
|--------------------------------------------------------------------------
| StepObserver Group Assignment Tests
|--------------------------------------------------------------------------
|
| These tests verify that the StepObserver correctly assigns groups to steps
| for workflow-level parallelism. The rules are:
|
| 1. Root step (no parent) → group selected via round-robin from steps_dispatcher
| 2. Child step → inherits parent's group
| 3. Deep nesting → all descendants share root's group
|
*/

beforeEach(function (): void {
    // Clean up steps and dispatcher groups from previous tests
    Step::query()->delete();
    StepsDispatcher::query()->delete();

    // Seed steps_dispatcher groups for round-robin selection (all 10 groups)
    $groups = ['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta', 'iota', 'kappa'];
    foreach ($groups as $group) {
        StepsDispatcher::query()->forceCreate([
            'group' => $group,
            'can_dispatch' => true,
            'last_selected_at' => null,
        ]);
    }
});

test('root step gets a group from steps_dispatcher via round-robin', function (): void {
    $step = Step::factory()->create([
        'block_uuid' => Str::uuid()->toString(),
        'group' => null,
    ]);

    // Should get a group from steps_dispatcher (not block_uuid)
    expect($step->group)->toBeIn(['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta', 'iota', 'kappa']);
});

test('root step with explicit group keeps that group', function (): void {
    $customGroup = 'my-custom-group';

    $step = Step::factory()->create([
        'block_uuid' => Str::uuid()->toString(),
        'group' => $customGroup,
    ]);

    expect($step->group)->toBe($customGroup);
});

test('child step inherits parent group', function (): void {
    // Create parent step with child_block_uuid
    $parentBlockUuid = Str::uuid()->toString();
    $childBlockUuid = Str::uuid()->toString();

    $parent = Step::factory()->create([
        'block_uuid' => $parentBlockUuid,
        'child_block_uuid' => $childBlockUuid,
        'group' => null,
    ]);

    // Parent should get a group from steps_dispatcher
    expect($parent->group)->toBeIn(['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta', 'iota', 'kappa']);

    // Create child step in the child block
    $child = Step::factory()->create([
        'block_uuid' => $childBlockUuid,
        'group' => null,
    ]);

    // Child should inherit parent's group
    expect($child->group)->toBe($parent->group);
});

test('grandchild step inherits root group (2 levels deep)', function (): void {
    $rootBlockUuid = Str::uuid()->toString();
    $level1BlockUuid = Str::uuid()->toString();
    $level2BlockUuid = Str::uuid()->toString();

    // Root parent step
    $rootStep = Step::factory()->create([
        'block_uuid' => $rootBlockUuid,
        'child_block_uuid' => $level1BlockUuid,
        'group' => null,
    ]);

    $rootGroup = $rootStep->group;
    expect($rootGroup)->toBeIn(['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta', 'iota', 'kappa']);

    // Level 1 parent (child of root)
    $level1Step = Step::factory()->create([
        'block_uuid' => $level1BlockUuid,
        'child_block_uuid' => $level2BlockUuid,
        'group' => null,
    ]);

    expect($level1Step->group)->toBe($rootGroup);

    // Level 2 child (grandchild of root)
    $level2Step = Step::factory()->create([
        'block_uuid' => $level2BlockUuid,
        'group' => null,
    ]);

    expect($level2Step->group)->toBe($rootGroup);
});

test('5 levels deep all inherit root group', function (): void {
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
        'group' => null,
    ]);

    $rootGroup = $rootStep->group;

    // Level 1 parent
    $level1Step = Step::factory()->create([
        'block_uuid' => $level1BlockUuid,
        'child_block_uuid' => $level2BlockUuid,
        'group' => null,
    ]);

    // Level 2 parent
    $level2Step = Step::factory()->create([
        'block_uuid' => $level2BlockUuid,
        'child_block_uuid' => $level3BlockUuid,
        'group' => null,
    ]);

    // Level 3 parent
    $level3Step = Step::factory()->create([
        'block_uuid' => $level3BlockUuid,
        'child_block_uuid' => $level4BlockUuid,
        'group' => null,
    ]);

    // Level 4 parent
    $level4Step = Step::factory()->create([
        'block_uuid' => $level4BlockUuid,
        'child_block_uuid' => $level5BlockUuid,
        'group' => null,
    ]);

    // Level 5 child (leaf)
    $level5Step = Step::factory()->create([
        'block_uuid' => $level5BlockUuid,
        'group' => null,
    ]);

    // All steps should share the root group
    expect($rootStep->group)->toBe($rootGroup);
    expect($level1Step->group)->toBe($rootGroup);
    expect($level2Step->group)->toBe($rootGroup);
    expect($level3Step->group)->toBe($rootGroup);
    expect($level4Step->group)->toBe($rootGroup);
    expect($level5Step->group)->toBe($rootGroup);
});

test('multiple children in same block all inherit parent group', function (): void {
    $parentBlockUuid = Str::uuid()->toString();
    $childBlockUuid = Str::uuid()->toString();

    $parent = Step::factory()->create([
        'block_uuid' => $parentBlockUuid,
        'child_block_uuid' => $childBlockUuid,
        'group' => null,
    ]);

    $parentGroup = $parent->group;

    // Create multiple children in the same block
    $child1 = Step::factory()->create([
        'block_uuid' => $childBlockUuid,
        'index' => 1,
        'group' => null,
    ]);

    $child2 = Step::factory()->create([
        'block_uuid' => $childBlockUuid,
        'index' => 2,
        'group' => null,
    ]);

    $child3 = Step::factory()->create([
        'block_uuid' => $childBlockUuid,
        'index' => 3,
        'group' => null,
    ]);

    expect($child1->group)->toBe($parentGroup);
    expect($child2->group)->toBe($parentGroup);
    expect($child3->group)->toBe($parentGroup);
});

test('parallel children at same index all inherit parent group', function (): void {
    $parentBlockUuid = Str::uuid()->toString();
    $childBlockUuid = Str::uuid()->toString();

    $parent = Step::factory()->create([
        'block_uuid' => $parentBlockUuid,
        'child_block_uuid' => $childBlockUuid,
        'group' => null,
    ]);

    $parentGroup = $parent->group;

    // Create parallel children (same index = parallel execution)
    $parallelChild1 = Step::factory()->create([
        'block_uuid' => $childBlockUuid,
        'index' => 1,
        'group' => null,
    ]);

    $parallelChild2 = Step::factory()->create([
        'block_uuid' => $childBlockUuid,
        'index' => 1,
        'group' => null,
    ]);

    $parallelChild3 = Step::factory()->create([
        'block_uuid' => $childBlockUuid,
        'index' => 1,
        'group' => null,
    ]);

    expect($parallelChild1->group)->toBe($parentGroup);
    expect($parallelChild2->group)->toBe($parentGroup);
    expect($parallelChild3->group)->toBe($parentGroup);
});

test('group is preserved on step update', function (): void {
    $step = Step::factory()->create([
        'block_uuid' => Str::uuid()->toString(),
        'group' => null,
        'state' => Pending::class,
    ]);

    $originalGroup = $step->group;
    expect($originalGroup)->toBeIn(['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta', 'iota', 'kappa']);

    // Update step state
    $step->state->transitionTo(Running::class);
    $step->refresh();

    // Group should still be preserved
    expect($step->group)->toBe($originalGroup);
});

test('round-robin cycles through groups', function (): void {
    // Create multiple root steps and verify round-robin cycles through groups
    $groups = [];
    for ($i = 0; $i < 10; $i++) {
        $step = Step::factory()->create([
            'block_uuid' => Str::uuid()->toString(),
            'group' => null,
        ]);
        $groups[] = $step->group;
    }

    // Should have cycled through all 10 different groups
    $uniqueGroups = array_unique($groups);
    expect(count($uniqueGroups))->toBe(10);
});

test('orphan step defaults to index 1', function (): void {
    $step = Step::factory()->create([
        'block_uuid' => Str::uuid()->toString(),
        'index' => null,
    ]);

    expect($step->index)->toBe(1);
});

test('orphan step with index 0 gets index 1', function (): void {
    $step = Step::factory()->create([
        'block_uuid' => Str::uuid()->toString(),
        'index' => 0,
    ]);

    expect($step->index)->toBe(1);
});

test('step with explicit index keeps that index', function (): void {
    $step = Step::factory()->create([
        'block_uuid' => Str::uuid()->toString(),
        'index' => 5,
    ]);

    expect($step->index)->toBe(5);
});

/*
|--------------------------------------------------------------------------
| Round-Robin Distribution Tests
|--------------------------------------------------------------------------
|
| These tests verify that step group assignment distributes evenly across
| all available groups, and that the lockForUpdate() mechanism works correctly.
|
*/

test('many steps distribute evenly across all 10 groups', function (): void {
    // Create 30 root steps (3 per group expected)
    for ($i = 0; $i < 30; $i++) {
        Step::factory()->create([
            'block_uuid' => Str::uuid()->toString(),
            'group' => null,
        ]);
    }

    // Get distribution counts per group
    $distribution = Step::query()
        ->selectRaw('`group`, COUNT(*) as count')
        ->groupBy('group')
        ->pluck('count', 'group')
        ->toArray();

    // Should have all 10 groups
    expect($distribution)->toHaveCount(10);

    // Each group should have exactly 3 steps (30 steps / 10 groups)
    foreach ($distribution as $group => $count) {
        expect($count)->toBe(3, "Group {$group} should have 3 steps, got {$count}");
    }
});

test('100 steps distribute evenly across all 10 groups', function (): void {
    // Create 100 root steps (10 per group expected)
    for ($i = 0; $i < 100; $i++) {
        Step::factory()->create([
            'block_uuid' => Str::uuid()->toString(),
            'group' => null,
        ]);
    }

    // Get distribution counts per group
    $distribution = Step::query()
        ->selectRaw('`group`, COUNT(*) as count')
        ->groupBy('group')
        ->pluck('count', 'group')
        ->toArray();

    // Should have all 10 groups
    expect($distribution)->toHaveCount(10);

    // Each group should have exactly 10 steps (100 steps / 10 groups)
    foreach ($distribution as $group => $count) {
        expect($count)->toBe(10, "Group {$group} should have 10 steps, got {$count}");
    }
});

test('steps created without transaction wrapper distribute correctly', function (): void {
    // When Steps are created outside a transaction wrapper,
    // each Step::create() runs its own getNextGroup() transaction independently
    $createdSteps = [];

    for ($i = 0; $i < 20; $i++) {
        $createdSteps[] = Step::factory()->create([
            'block_uuid' => Str::uuid()->toString(),
            'group' => null,
        ]);
    }

    // Get unique groups assigned
    $assignedGroups = collect($createdSteps)->pluck('group')->unique()->values()->toArray();

    // Should have used all 10 groups (20 steps / 10 groups = 2 complete cycles)
    expect($assignedGroups)->toHaveCount(10);
});

test('steps created inside collection each() distribute correctly', function (): void {
    // Steps created inside collection each() (NOT wrapped in DB::transaction())
    $blockUuids = collect(range(1, 20))->map(function () {
        return Str::uuid()->toString();
    });

    $blockUuids->each(function (string $blockUuid): void {
        Step::factory()->create([
            'block_uuid' => $blockUuid,
            'group' => null,
        ]);
    });

    // Get distribution counts per group
    $distribution = Step::query()
        ->selectRaw('`group`, COUNT(*) as count')
        ->groupBy('group')
        ->pluck('count', 'group')
        ->toArray();

    // Should have all 10 groups used
    expect($distribution)->toHaveCount(10);

    // Each group should have 2 steps
    foreach ($distribution as $group => $count) {
        expect($count)->toBe(2, "Group {$group} should have 2 steps, got {$count}");
    }
});
