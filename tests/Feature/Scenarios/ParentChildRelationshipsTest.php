<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\Tests\Support\StepTester;
use StepDispatcher\Tests\Fixtures\ScenarioTestJob;

/*
|--------------------------------------------------------------------------
| Parent-Child Relationships Tests
|--------------------------------------------------------------------------
|
| These tests verify the step dispatcher correctly handles:
|
| - Parent steps spawning child blocks
| - Children inheriting parent's group
| - Parent waiting for children to complete
| - Parent transitioning to completed/failed based on children
| - Deep nesting up to 5 levels
|
*/

it('dispatches child step when parent is running', function (): void {
    $parentBlock = Str::uuid()->toString();
    $childBlock = Str::uuid()->toString();

    $steps = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
        ['block_uuid' => $childBlock, 'index' => 1],
    ], ScenarioTestJob::class);

    [$parent, $child] = $steps;

    $statusMatrix = [
        1 => [
            $parent->id => 'running',
            $child->id => 'pending',
        ],
        2 => [
            $child->id => 'completed',
        ],
        3 => [
            $parent->id => 'completed',
        ],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parent_child_basic')
        ->test();
});

it('dispatches multiple children when parent is running', function (): void {
    $parentBlock = Str::uuid()->toString();
    $childBlock = Str::uuid()->toString();

    $steps = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
        ['block_uuid' => $childBlock, 'index' => 1],
        ['block_uuid' => $childBlock, 'index' => 1],
        ['block_uuid' => $childBlock, 'index' => 1],
    ], ScenarioTestJob::class);

    [$parent, $c1, $c2, $c3] = $steps;

    $statusMatrix = [
        1 => [
            $parent->id => 'running',
            $c1->id => 'pending',
            $c2->id => 'pending',
            $c3->id => 'pending',
        ],
        2 => [
            $c1->id => 'completed',
            $c2->id => 'completed',
            $c3->id => 'completed',
        ],
        3 => [
            $parent->id => 'completed',
        ],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parent_multiple_children')
        ->test();
});

it('parent transitions to completed when all children complete', function (): void {
    $parentBlock = Str::uuid()->toString();
    $childBlock = Str::uuid()->toString();

    $steps = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
        ['block_uuid' => $childBlock, 'index' => 1],
        ['block_uuid' => $childBlock, 'index' => 2],
    ], ScenarioTestJob::class);

    [$parent, $c1, $c2] = $steps;

    $statusMatrix = [
        1 => [
            $parent->id => 'running',
            $c1->id => 'pending',
            $c2->id => 'pending',
        ],
        2 => [
            $c1->id => 'completed',
            $c2->id => 'pending',
        ],
        3 => [
            $c2->id => 'completed',
        ],
        4 => [
            $parent->id => 'completed',
        ],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parent_complete_when_children_done')
        ->test();
});

it('parent transitions to failed when child fails', function (): void {
    $parentBlock = Str::uuid()->toString();
    $childBlock = Str::uuid()->toString();

    $steps = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
        ['block_uuid' => $childBlock, 'index' => 1, 'arguments' => ['fail' => true]],
    ], ScenarioTestJob::class);

    [$parent, $child] = $steps;

    $statusMatrix = [
        1 => [
            $parent->id => 'running',
            $child->id => 'pending',
        ],
        2 => [
            $child->id => 'failed',
        ],
        3 => [
            $parent->id => 'failed',
        ],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parent_fails_when_child_fails')
        ->test();
});

it('parent transitions to completed when children are mix of completed and skipped', function (): void {
    $parentBlock = Str::uuid()->toString();
    $childBlock = Str::uuid()->toString();

    $steps = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
        ['block_uuid' => $childBlock, 'index' => 1],
        ['block_uuid' => $childBlock, 'index' => 2, 'arguments' => ['skip' => true]],
    ], ScenarioTestJob::class);

    [$parent, $c1, $c2] = $steps;

    $statusMatrix = [
        1 => [
            $parent->id => 'running',
            $c1->id => 'pending',
            $c2->id => 'pending',
        ],
        2 => [
            $c1->id => 'completed',
            $c2->id => 'pending',
        ],
        3 => [
            $c2->id => 'skipped',
        ],
        4 => [
            $parent->id => 'completed',
        ],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parent_complete_with_skipped_child')
        ->test();
});

it('handles 2 levels deep: grandchild completes then child then parent', function (): void {
    $l1Block = Str::uuid()->toString();
    $l2Block = Str::uuid()->toString();
    $l3Block = Str::uuid()->toString();

    $steps = StepTester::createSteps([
        ['block_uuid' => $l1Block, 'index' => 1, 'child_block_uuid' => $l2Block],
        ['block_uuid' => $l2Block, 'index' => 1, 'child_block_uuid' => $l3Block],
        ['block_uuid' => $l3Block, 'index' => 1],
    ], ScenarioTestJob::class);

    [$l1, $l2, $l3] = $steps;

    $statusMatrix = [
        1 => [
            $l1->id => 'running',
            $l2->id => 'pending',
            $l3->id => 'pending',
        ],
        2 => [
            $l2->id => 'running',
            $l3->id => 'pending',
        ],
        3 => [
            $l3->id => 'completed',
        ],
        4 => [
            $l2->id => 'completed',
        ],
        5 => [
            $l1->id => 'completed',
        ],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('2_levels_deep')
        ->test();
});

it('handles 3 levels deep hierarchy completes correctly', function (): void {
    $l1Block = Str::uuid()->toString();
    $l2Block = Str::uuid()->toString();
    $l3Block = Str::uuid()->toString();
    $l4Block = Str::uuid()->toString();

    $steps = StepTester::createSteps([
        ['block_uuid' => $l1Block, 'index' => 1, 'child_block_uuid' => $l2Block],
        ['block_uuid' => $l2Block, 'index' => 1, 'child_block_uuid' => $l3Block],
        ['block_uuid' => $l3Block, 'index' => 1, 'child_block_uuid' => $l4Block],
        ['block_uuid' => $l4Block, 'index' => 1],
    ], ScenarioTestJob::class);

    [$l1, $l2, $l3, $l4] = $steps;

    $statusMatrix = [
        1 => [
            $l1->id => 'running',
            $l2->id => 'pending',
            $l3->id => 'pending',
            $l4->id => 'pending',
        ],
        2 => [$l2->id => 'running'],
        3 => [$l3->id => 'running'],
        4 => [$l4->id => 'completed'],
        5 => [$l3->id => 'completed'],
        6 => [$l2->id => 'completed'],
        7 => [$l1->id => 'completed'],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('3_levels_deep')
        ->test();
});

it('handles 5 levels deep hierarchy completes correctly', function (): void {
    $l1Block = Str::uuid()->toString();
    $l2Block = Str::uuid()->toString();
    $l3Block = Str::uuid()->toString();
    $l4Block = Str::uuid()->toString();
    $l5Block = Str::uuid()->toString();
    $l6Block = Str::uuid()->toString();

    $steps = StepTester::createSteps([
        ['block_uuid' => $l1Block, 'index' => 1, 'child_block_uuid' => $l2Block],
        ['block_uuid' => $l2Block, 'index' => 1, 'child_block_uuid' => $l3Block],
        ['block_uuid' => $l3Block, 'index' => 1, 'child_block_uuid' => $l4Block],
        ['block_uuid' => $l4Block, 'index' => 1, 'child_block_uuid' => $l5Block],
        ['block_uuid' => $l5Block, 'index' => 1, 'child_block_uuid' => $l6Block],
        ['block_uuid' => $l6Block, 'index' => 1],
    ], ScenarioTestJob::class);

    [$l1, $l2, $l3, $l4, $l5, $l6] = $steps;

    $statusMatrix = [
        1 => [
            $l1->id => 'running',
            $l2->id => 'pending',
            $l3->id => 'pending',
            $l4->id => 'pending',
            $l5->id => 'pending',
            $l6->id => 'pending',
        ],
        2 => [$l2->id => 'running'],
        3 => [$l3->id => 'running'],
        4 => [$l4->id => 'running'],
        5 => [$l5->id => 'running'],
        6 => [$l6->id => 'completed'],
        7 => [$l5->id => 'completed'],
        8 => [$l4->id => 'completed'],
        9 => [$l3->id => 'completed'],
        10 => [$l2->id => 'completed'],
        11 => [$l1->id => 'completed'],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('5_levels_deep')
        ->test();
});

it('handles 5 levels deep with multiple children per level', function (): void {
    $l1Block = Str::uuid()->toString();
    $l2Block = Str::uuid()->toString();
    $l3Block = Str::uuid()->toString();
    $l4Block = Str::uuid()->toString();
    $l5Block = Str::uuid()->toString();
    $l6Block = Str::uuid()->toString();

    $steps = StepTester::createSteps([
        // Level 1: parent with child block
        ['block_uuid' => $l1Block, 'index' => 1, 'child_block_uuid' => $l2Block],
        // Level 2: 2 steps - one parent, one leaf
        ['block_uuid' => $l2Block, 'index' => 1, 'child_block_uuid' => $l3Block],
        ['block_uuid' => $l2Block, 'index' => 1],
        // Level 3: parent step
        ['block_uuid' => $l3Block, 'index' => 1, 'child_block_uuid' => $l4Block],
        // Level 4: parent step
        ['block_uuid' => $l4Block, 'index' => 1, 'child_block_uuid' => $l5Block],
        // Level 5: parent step
        ['block_uuid' => $l5Block, 'index' => 1, 'child_block_uuid' => $l6Block],
        // Level 6: 2 leaf steps
        ['block_uuid' => $l6Block, 'index' => 1],
        ['block_uuid' => $l6Block, 'index' => 1],
    ], ScenarioTestJob::class);

    [$l1, $l2a, $l2b, $l3, $l4, $l5, $l6a, $l6b] = $steps;

    $statusMatrix = [
        1 => [
            $l1->id => 'running',
            $l2a->id => 'pending',
            $l2b->id => 'pending',
            $l3->id => 'pending',
            $l4->id => 'pending',
            $l5->id => 'pending',
            $l6a->id => 'pending',
            $l6b->id => 'pending',
        ],
        2 => [
            $l2a->id => 'running',
            $l2b->id => 'completed',
        ],
        3 => [$l3->id => 'running'],
        4 => [$l4->id => 'running'],
        5 => [$l5->id => 'running'],
        6 => [
            $l6a->id => 'completed',
            $l6b->id => 'completed',
        ],
        7 => [$l5->id => 'completed'],
        8 => [$l4->id => 'completed'],
        9 => [$l3->id => 'completed'],
        10 => [$l2a->id => 'completed'],
        11 => [$l1->id => 'completed'],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('5_levels_multiple_children')
        ->test();
});

it('handles 5 levels deep failure cascades up', function (): void {
    $l1Block = Str::uuid()->toString();
    $l2Block = Str::uuid()->toString();
    $l3Block = Str::uuid()->toString();
    $l4Block = Str::uuid()->toString();
    $l5Block = Str::uuid()->toString();
    $l6Block = Str::uuid()->toString();

    $steps = StepTester::createSteps([
        ['block_uuid' => $l1Block, 'index' => 1, 'child_block_uuid' => $l2Block],
        ['block_uuid' => $l2Block, 'index' => 1, 'child_block_uuid' => $l3Block],
        ['block_uuid' => $l3Block, 'index' => 1, 'child_block_uuid' => $l4Block],
        ['block_uuid' => $l4Block, 'index' => 1, 'child_block_uuid' => $l5Block],
        ['block_uuid' => $l5Block, 'index' => 1, 'child_block_uuid' => $l6Block],
        ['block_uuid' => $l6Block, 'index' => 1, 'arguments' => ['fail' => true]],
    ], ScenarioTestJob::class);

    [$l1, $l2, $l3, $l4, $l5, $l6] = $steps;

    $statusMatrix = [
        1 => [
            $l1->id => 'running',
            $l2->id => 'pending',
            $l3->id => 'pending',
            $l4->id => 'pending',
            $l5->id => 'pending',
            $l6->id => 'pending',
        ],
        2 => [$l2->id => 'running'],
        3 => [$l3->id => 'running'],
        4 => [$l4->id => 'running'],
        5 => [$l5->id => 'running'],
        6 => [$l6->id => 'failed'],
        7 => [$l5->id => 'failed'],
        8 => [$l4->id => 'failed'],
        9 => [$l3->id => 'failed'],
        10 => [$l2->id => 'failed'],
        11 => [$l1->id => 'failed'],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('5_levels_failure_cascade')
        ->test();
});

it('parent with sequential children completes after all children done', function (): void {
    $parentBlock = Str::uuid()->toString();
    $childBlock = Str::uuid()->toString();

    $steps = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
        ['block_uuid' => $childBlock, 'index' => 1],
        ['block_uuid' => $childBlock, 'index' => 2],
        ['block_uuid' => $childBlock, 'index' => 3],
    ], ScenarioTestJob::class);

    [$parent, $c1, $c2, $c3] = $steps;

    $statusMatrix = [
        1 => [
            $parent->id => 'running',
            $c1->id => 'pending',
            $c2->id => 'pending',
            $c3->id => 'pending',
        ],
        2 => [
            $c1->id => 'completed',
            $c2->id => 'pending',
        ],
        3 => [
            $c2->id => 'completed',
            $c3->id => 'pending',
        ],
        4 => [
            $c3->id => 'completed',
        ],
        5 => [
            $parent->id => 'completed',
        ],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parent_sequential_children')
        ->test();
});

it('handles parent with mix of parallel and sequential children', function (): void {
    $parentBlock = Str::uuid()->toString();
    $childBlock = Str::uuid()->toString();

    $steps = StepTester::createSteps([
        ['block_uuid' => $parentBlock, 'index' => 1, 'child_block_uuid' => $childBlock],
        // Index 1: 2 parallel children
        ['block_uuid' => $childBlock, 'index' => 1],
        ['block_uuid' => $childBlock, 'index' => 1],
        // Index 2: 1 child
        ['block_uuid' => $childBlock, 'index' => 2],
    ], ScenarioTestJob::class);

    [$parent, $c1a, $c1b, $c2] = $steps;

    $statusMatrix = [
        1 => [
            $parent->id => 'running',
            $c1a->id => 'pending',
            $c1b->id => 'pending',
            $c2->id => 'pending',
        ],
        2 => [
            $c1a->id => 'completed',
            $c1b->id => 'completed',
            $c2->id => 'pending',
        ],
        3 => [
            $c2->id => 'completed',
        ],
        4 => [
            $parent->id => 'completed',
        ],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('parent_mixed_children')
        ->test();
});

it('handles sibling parents with their own child blocks', function (): void {
    $mainBlock = Str::uuid()->toString();
    $child1Block = Str::uuid()->toString();
    $child2Block = Str::uuid()->toString();

    $steps = StepTester::createSteps([
        // Two parallel parents
        ['block_uuid' => $mainBlock, 'index' => 1, 'child_block_uuid' => $child1Block],
        ['block_uuid' => $mainBlock, 'index' => 1, 'child_block_uuid' => $child2Block],
        // Children of first parent
        ['block_uuid' => $child1Block, 'index' => 1],
        // Children of second parent
        ['block_uuid' => $child2Block, 'index' => 1],
    ], ScenarioTestJob::class);

    [$p1, $p2, $c1, $c2] = $steps;

    $statusMatrix = [
        1 => [
            $p1->id => 'running',
            $p2->id => 'running',
            $c1->id => 'pending',
            $c2->id => 'pending',
        ],
        2 => [
            $c1->id => 'completed',
            $c2->id => 'completed',
        ],
        3 => [
            $p1->id => 'completed',
            $p2->id => 'completed',
        ],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('sibling_parents')
        ->test();
});

it('handles sequential parents at different indexes', function (): void {
    $mainBlock = Str::uuid()->toString();
    $child1Block = Str::uuid()->toString();
    $child2Block = Str::uuid()->toString();

    $steps = StepTester::createSteps([
        // Sequential parents
        ['block_uuid' => $mainBlock, 'index' => 1, 'child_block_uuid' => $child1Block],
        ['block_uuid' => $mainBlock, 'index' => 2, 'child_block_uuid' => $child2Block],
        // Children
        ['block_uuid' => $child1Block, 'index' => 1],
        ['block_uuid' => $child2Block, 'index' => 1],
    ], ScenarioTestJob::class);

    [$p1, $p2, $c1, $c2] = $steps;

    $statusMatrix = [
        1 => [
            $p1->id => 'running',
            $p2->id => 'pending',
            $c1->id => 'pending',
            $c2->id => 'pending',
        ],
        2 => [
            $c1->id => 'completed',
        ],
        3 => [
            $p1->id => 'completed',
        ],
        4 => [
            $p2->id => 'running',
        ],
        5 => [
            $c2->id => 'completed',
        ],
        6 => [
            $p2->id => 'completed',
        ],
    ];

    StepTester::withSteps($steps)
        ->withStatusMatrix($statusMatrix)
        ->withLabel('sequential_parents')
        ->test();
});

// Model method tests (non-dispatch)
it('step isParent returns true when has child_block_uuid', function (): void {
    $step = Step::factory()->create([
        'child_block_uuid' => Str::uuid()->toString(),
    ]);

    expect($step->isParent())->toBeTrue();
});

it('step isParent returns false when no child_block_uuid', function (): void {
    $step = Step::factory()->create([
        'child_block_uuid' => null,
    ]);

    expect($step->isParent())->toBeFalse();
});

it('step isChild returns true when parent exists', function (): void {
    $parentBlock = Str::uuid()->toString();
    $childBlock = Str::uuid()->toString();

    Step::factory()->create([
        'block_uuid' => $parentBlock,
        'child_block_uuid' => $childBlock,
    ]);

    $child = Step::factory()->create([
        'block_uuid' => $childBlock,
    ]);

    expect($child->isChild())->toBeTrue();
});

it('step isChild returns false when no parent exists', function (): void {
    $step = Step::factory()->create([
        'block_uuid' => Str::uuid()->toString(),
    ]);

    expect($step->isChild())->toBeFalse();
});

it('step isOrphan returns true when no parent and no children', function (): void {
    $step = Step::factory()->create([
        'block_uuid' => Str::uuid()->toString(),
        'child_block_uuid' => null,
    ]);

    expect($step->isOrphan())->toBeTrue();
});

it('step isOrphan returns false when has parent', function (): void {
    $parentBlock = Str::uuid()->toString();
    $childBlock = Str::uuid()->toString();

    Step::factory()->create([
        'block_uuid' => $parentBlock,
        'child_block_uuid' => $childBlock,
    ]);

    $child = Step::factory()->create([
        'block_uuid' => $childBlock,
        'child_block_uuid' => null,
    ]);

    expect($child->isOrphan())->toBeFalse();
});

it('step isOrphan returns false when has children', function (): void {
    $step = Step::factory()->create([
        'child_block_uuid' => Str::uuid()->toString(),
    ]);

    expect($step->isOrphan())->toBeFalse();
});
