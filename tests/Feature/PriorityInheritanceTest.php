<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;

beforeEach(function () {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

/**
 * Priority inheritance — child steps spawned by a high-priority parent
 * MUST inherit priority='high' so the entire workflow chain stays on
 * the priority lane.
 *
 * Without inheritance, the priority routing is exactly one step deep:
 * the parent runs on the priority queue, but every child it spawns
 * defaults to priority=null and falls back into the normal FIFO group
 * — defeating the latency isolation that priority='high' was meant
 * to provide.
 *
 * Production trigger (2026-05-01): an observer-dispatched
 * PreparePositionReplacementJob (priority='high') ran on the priority
 * queue, spawned VerifyPositionExistsOnExchangeJob and
 * SmartReplaceOrdersJob children — both without priority. The
 * SmartReplaceOrdersJob landed in group `beta` behind 150 pending
 * non-priority steps, stalling the SL recreation by minutes.
 *
 * Contract: when a child step is created (block_uuid matches a
 * parent's child_block_uuid) and the parent has priority='high', the
 * child inherits priority='high' unless it explicitly set its own
 * priority. Inheritance must NOT override an explicit priority
 * value (so a non-priority child of a priority parent can still
 * opt out by passing priority='low' or priority='normal' explicitly).
 */
it('child steps inherit priority=high from their parent step', function () {
    $parentBlock = Str::uuid()->toString();
    $childBlock = Str::uuid()->toString();

    // Parent step with priority='high'. Spawns a child block.
    Step::create([
        'class' => 'App\\Jobs\\TestJob',
        'queue' => 'priority',
        'priority' => 'high',
        'group' => 'priority-inheritance-test',
        'block_uuid' => $parentBlock,
        'child_block_uuid' => $childBlock,
        'index' => 1,
        'state' => Pending::class,
    ]);

    // Child step in the parent's child block, no explicit priority.
    // Expectation: StepObserver::creating sees the parent (matched by
    // child_block_uuid) has priority='high' and propagates.
    $child = Step::create([
        'class' => 'App\\Jobs\\TestJob',
        'group' => 'priority-inheritance-test',
        'block_uuid' => $childBlock,
        'index' => 1,
        'state' => Pending::class,
    ]);

    expect($child->fresh()->priority)->toBe('high',
        'A child step (block_uuid matches parent.child_block_uuid) must inherit '
        .'priority=high when the parent is priority=high. Without this, the '
        .'priority lane is one-step-deep and the rest of the workflow falls '
        .'back into normal FIFO group ordering.'
    );
});

it('child steps do not inherit priority when parent is not priority-high', function () {
    $parentBlock = Str::uuid()->toString();
    $childBlock = Str::uuid()->toString();

    // Parent without priority='high' — normal step.
    Step::create([
        'class' => 'App\\Jobs\\TestJob',
        'group' => 'priority-inheritance-test',
        'block_uuid' => $parentBlock,
        'child_block_uuid' => $childBlock,
        'index' => 1,
        'state' => Pending::class,
    ]);

    $child = Step::create([
        'class' => 'App\\Jobs\\TestJob',
        'group' => 'priority-inheritance-test',
        'block_uuid' => $childBlock,
        'index' => 1,
        'state' => Pending::class,
    ]);

    expect($child->fresh()->priority)->toBeNull(
        'A child of a non-priority parent must remain non-priority. '
        .'Inheritance only fires when the parent is priority=high.'
    );
});

it('explicit child priority is not overridden by inheritance', function () {
    $parentBlock = Str::uuid()->toString();
    $childBlock = Str::uuid()->toString();

    Step::create([
        'class' => 'App\\Jobs\\TestJob',
        'queue' => 'priority',
        'priority' => 'high',
        'group' => 'priority-inheritance-test',
        'block_uuid' => $parentBlock,
        'child_block_uuid' => $childBlock,
        'index' => 1,
        'state' => Pending::class,
    ]);

    // Child explicitly opts out of high priority.
    $child = Step::create([
        'class' => 'App\\Jobs\\TestJob',
        'priority' => 'normal',
        'group' => 'priority-inheritance-test',
        'block_uuid' => $childBlock,
        'index' => 1,
        'state' => Pending::class,
    ]);

    expect($child->fresh()->priority)->toBe('normal',
        'When a child step explicitly sets its own priority, the inheritance '
        .'logic must not override it. Explicit always wins.'
    );
});

it('grandchild steps inherit priority=high through a chain of priority-high parents', function () {
    $rootBlock = Str::uuid()->toString();
    $childBlock = Str::uuid()->toString();
    $grandchildBlock = Str::uuid()->toString();

    Step::create([
        'class' => 'App\\Jobs\\TestJob',
        'queue' => 'priority',
        'priority' => 'high',
        'group' => 'priority-inheritance-test',
        'block_uuid' => $rootBlock,
        'child_block_uuid' => $childBlock,
        'index' => 1,
        'state' => Pending::class,
    ]);

    // Child inherits priority='high' from root, AND spawns its own
    // grandchild block.
    Step::create([
        'class' => 'App\\Jobs\\TestJob',
        'group' => 'priority-inheritance-test',
        'block_uuid' => $childBlock,
        'child_block_uuid' => $grandchildBlock,
        'index' => 1,
        'state' => Pending::class,
    ]);

    $grandchild = Step::create([
        'class' => 'App\\Jobs\\TestJob',
        'group' => 'priority-inheritance-test',
        'block_uuid' => $grandchildBlock,
        'index' => 1,
        'state' => Pending::class,
    ]);

    expect($grandchild->fresh()->priority)->toBe('high',
        'Priority must propagate through every level of the workflow chain. '
        .'A grandchild of a priority=high root, via an intermediate child '
        .'that itself inherited priority=high, must also be priority=high.'
    );
});
