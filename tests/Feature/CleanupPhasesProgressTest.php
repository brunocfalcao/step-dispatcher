<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Failed;
use StepDispatcher\States\NotRunnable;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Skipped;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob;

beforeEach(function () {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

/**
 * Local seeder mirroring the helper in ParentResolutionContractsTest. Bypasses
 * the round-robin group assignment that the StepObserver applies when a row
 * is created without a group, since these tests target the dispatcher cleanup
 * phases by group and need deterministic placement.
 */
function seedCleanupStep(array $attrs): Step
{
    return Step::create(array_merge([
        'class' => 'App\\Jobs\\TestJob',
        'type' => 'default',
        'queue' => 'default',
        'group' => 'cleanup-test',
        'index' => 1,
        'block_uuid' => (string) Str::uuid(),
    ], $attrs));
}

function forceCleanupState(Step $step, string $stateClass, array $extra = []): void
{
    Step::withoutEvents(function () use ($step, $stateClass, $extra) {
        Step::where('id', $step->id)->update(array_merge(['state' => $stateClass], $extra));
    });
}

/**
 * Cleanup-phase contract: every dispatcher tick cleanup phase MUST return
 * `true` only when it has actually advanced state. Returning `true` on a
 * positive entry condition without any effective work blocks the dispatch
 * phase forever, exactly as observed in production on 2026-04-25 (eta /
 * beta / iota / kappa wedged ~16h on Skipped parents whose child blocks
 * had no live work).
 *
 * The two scenarios below are the load-bearing failure modes of phase 0
 * (`skipAllChildStepsOnParentAndChildSingleStep`):
 *
 *   1. Skipped parent points at a block that contains zero child rows.
 *      `collectAllNestedChildBlocks` still returns the block UUID (because
 *      the parent row itself has `child_block_uuid = X`), so the empty-
 *      blocks early return doesn't fire. The descendant fetch comes back
 *      empty, the batch transition is skipped, and the phase falls
 *      through to its unconditional `return true` — wedge.
 *
 *   2. Skipped parent's child block is fully populated but every
 *      descendant is already in a terminal state. The batch transition
 *      runs but `terminal -> Skipped` is rejected by the state machine,
 *      so nothing actually moves. The phase still hits `return true`.
 *
 * Either case produces the identical operational symptom: dispatcher tick
 * exits at phase 0, never reaches the dispatch phase, Pending steps
 * accumulate forever in the affected group.
 */
it('skipAllChildStepsOnParentAndChildSingleStep returns false when the child block contains no rows', function () {
    $childBlock = (string) Str::uuid();

    $parent = seedCleanupStep([
        'child_block_uuid' => $childBlock,
    ]);
    forceCleanupState($parent, Skipped::class);

    $result = StepDispatcher::skipAllChildStepsOnParentAndChildSingleStep('cleanup-test');

    expect($result)->toBeFalse(
        'Phase 0 must not claim work-done when the Skipped parent points at an empty child block — '
        .'returning true blocks the dispatch phase for the entire group.'
    );
});

it('skipAllChildStepsOnParentAndChildSingleStep returns false when every descendant is already terminal', function () {
    $childBlock = (string) Str::uuid();

    $parent = seedCleanupStep([
        'child_block_uuid' => $childBlock,
    ]);
    forceCleanupState($parent, Skipped::class);

    // Spread the four terminal states across descendants so the test pins
    // the contract regardless of which terminal sibling appeared first.
    foreach ([Completed::class, Skipped::class, Cancelled::class, Failed::class] as $i => $terminalState) {
        $child = seedCleanupStep([
            'block_uuid' => $childBlock,
            'index' => $i + 1,
        ]);
        forceCleanupState($child, $terminalState);
    }

    $result = StepDispatcher::skipAllChildStepsOnParentAndChildSingleStep('cleanup-test');

    expect($result)->toBeFalse(
        'Phase 0 must not claim work-done when no descendant can actually transition — '
        .'terminal -> Skipped is rejected by the state machine, so the phase did nothing useful '
        .'and the tick must continue to phases 1-7.'
    );
});

/**
 * promoteResolveExceptionSteps has the same contract: only a transition that
 * really happened counts as work. A resolver that cannot be promoted must not
 * end the tick, and must not hide the failing blocks behind it either.
 */
function seedFailingBlockWithResolver(): Step
{
    $block = (string) Str::uuid();

    forceCleanupState(seedCleanupStep(['block_uuid' => $block]), Failed::class);

    $resolver = seedCleanupStep(['block_uuid' => $block, 'type' => 'resolve-exception', 'index' => null]);
    forceCleanupState($resolver, NotRunnable::class);

    return $resolver->fresh();
}

function refuseWritesTo(Step $resolver): void
{
    Step::updating(static function (Step $step) use ($resolver): void {
        if ($step->id === $resolver->id) {
            throw new RuntimeException('write refused');
        }
    });
}

it('promoteResolveExceptionSteps claims no work when no resolver could be promoted', function () {
    $resolver = seedFailingBlockWithResolver();
    refuseWritesTo($resolver);

    expect(StepDispatcher::promoteResolveExceptionSteps('cleanup-test'))->toBeFalse()
        ->and($resolver->fresh()->state)->toBeInstanceOf(NotRunnable::class);
});

it('promoteResolveExceptionSteps moves past a block it could not promote', function () {
    $stuck = seedFailingBlockWithResolver();
    $next = seedFailingBlockWithResolver();
    refuseWritesTo($stuck);

    expect(StepDispatcher::promoteResolveExceptionSteps('cleanup-test'))->toBeTrue()
        ->and($stuck->fresh()->state)->toBeInstanceOf(NotRunnable::class)
        ->and($next->fresh()->state)->toBeInstanceOf(Pending::class);
});

it('a tick still dispatches work past a resolver it could not promote', function () {
    Queue::fake();

    refuseWritesTo(seedFailingBlockWithResolver());
    $runnable = seedCleanupStep(['class' => PrefixCarryingTestJob::class, 'index' => 1]);

    StepDispatcher::dispatch('cleanup-test');

    expect($runnable->fresh()->state)->toBeInstanceOf(Dispatched::class);
});

/**
 * F2 regression (code-review 01-P1): a Dispatched descendant of a Skipped
 * parent previously had no Dispatched -> Skipped edge. The sweep reselected
 * it every tick, the transition threw (swallowed), and the unconditional
 * `return true` starved the dispatch phase forever. Now the edge exists AND
 * the phase reports honest progress from the actual transition count.
 */
it('skips a Dispatched descendant of a Skipped parent and reports real progress', function () {
    $childBlock = (string) Str::uuid();

    $parent = seedCleanupStep(['child_block_uuid' => $childBlock]);
    forceCleanupState($parent, Skipped::class);

    $dispatchedChild = seedCleanupStep(['block_uuid' => $childBlock]);
    forceCleanupState($dispatchedChild, Dispatched::class);

    // First tick: real work — the Dispatched child transitions to Skipped.
    expect(StepDispatcher::skipAllChildStepsOnParentAndChildSingleStep('cleanup-test'))->toBeTrue()
        ->and($dispatchedChild->fresh()->state)->toBeInstanceOf(Skipped::class);

    // Second tick: nothing left to do — must yield to the dispatch phase.
    expect(StepDispatcher::skipAllChildStepsOnParentAndChildSingleStep('cleanup-test'))->toBeFalse();
});

it('skips a NotRunnable dormant resolver under a Skipped parent', function () {
    $childBlock = (string) Str::uuid();

    $parent = seedCleanupStep(['child_block_uuid' => $childBlock]);
    forceCleanupState($parent, Skipped::class);

    $dormantResolver = seedCleanupStep([
        'block_uuid' => $childBlock,
        'type' => 'resolve-exception',
    ]);
    forceCleanupState($dormantResolver, NotRunnable::class);

    expect(StepDispatcher::skipAllChildStepsOnParentAndChildSingleStep('cleanup-test'))->toBeTrue()
        ->and($dormantResolver->fresh()->state)->toBeInstanceOf(Skipped::class)
        ->and(StepDispatcher::skipAllChildStepsOnParentAndChildSingleStep('cleanup-test'))->toBeFalse();
});

it('batchTransitionSteps returns the count of transitions that actually happened', function () {
    $movable = seedCleanupStep([]);
    forceCleanupState($movable, Dispatched::class);

    // Completed -> Skipped is unregistered: contributes zero to the count.
    $immovable = seedCleanupStep([]);
    forceCleanupState($immovable, Completed::class);

    $count = StepDispatcher::batchTransitionSteps([$movable->id, $immovable->id], Skipped::class);

    expect($count)->toBe(1)
        ->and($movable->fresh()->state)->toBeInstanceOf(Skipped::class)
        ->and($immovable->fresh()->state)->toBeInstanceOf(Completed::class);
});

it('batchTransitionSteps counts a step that no longer exists as no work', function () {
    $movable = seedCleanupStep([]);
    forceCleanupState($movable, Dispatched::class);

    $count = StepDispatcher::batchTransitionSteps([$movable->id + 1000, $movable->id], Skipped::class);

    expect($count)->toBe(1)
        ->and($movable->fresh()->state)->toBeInstanceOf(Skipped::class);
});

it('batchTransitionSteps keeps a step moved when a reaction fails, counts only the clean move, and carries on', function () {
    $reacting = seedCleanupStep([]);
    forceCleanupState($reacting, Dispatched::class);

    $movable = seedCleanupStep([]);
    forceCleanupState($movable, Dispatched::class);

    Step::updated(static function (Step $step) use ($reacting): void {
        if ($step->id === $reacting->id) {
            throw new RuntimeException('listener failed');
        }
    });

    $count = StepDispatcher::batchTransitionSteps([$reacting->id, $movable->id], Cancelled::class);

    expect($count)->toBe(1)
        ->and($reacting->fresh()->state)->toBeInstanceOf(Cancelled::class)
        ->and($movable->fresh()->state)->toBeInstanceOf(Cancelled::class);
});

it('batchTransitionSteps leaves a step as it was when its save never happened', function () {
    $refused = seedCleanupStep([]);
    forceCleanupState($refused, Dispatched::class);

    Step::updating(static function (Step $step) use ($refused): void {
        if ($step->id === $refused->id) {
            throw new RuntimeException('save refused');
        }
    });

    expect(StepDispatcher::batchTransitionSteps([$refused->id], Cancelled::class))->toBe(0)
        ->and($refused->fresh()->state)->toBeInstanceOf(Dispatched::class);
});

it('does not starve dispatchable priority work while cleanup advances another workflow', function () {
    Queue::fake();

    $childBlock = (string) Str::uuid();
    $parent = seedCleanupStep(['child_block_uuid' => $childBlock]);
    forceCleanupState($parent, Skipped::class);

    $cleanupChild = seedCleanupStep(['block_uuid' => $childBlock]);
    forceCleanupState($cleanupChild, Dispatched::class);

    $priorityStep = seedCleanupStep([
        'class' => PrefixCarryingTestJob::class,
        'index' => null,
        'priority' => 'high',
        'queue' => 'priority',
    ]);

    StepDispatcher::dispatch('cleanup-test');

    expect($cleanupChild->fresh()->state)->toBeInstanceOf(Skipped::class)
        ->and($priorityStep->fresh()->state)->toBeInstanceOf(Dispatched::class);
});

it('does not dispatch priority work whose failed predecessor still requires cleanup', function () {
    Queue::fake();

    $block = (string) Str::uuid();
    $failedPredecessor = seedCleanupStep([
        'block_uuid' => $block,
        'index' => 1,
    ]);
    forceCleanupState($failedPredecessor, Failed::class);

    $prioritySuccessor = seedCleanupStep([
        'class' => PrefixCarryingTestJob::class,
        'block_uuid' => $block,
        'index' => 2,
        'priority' => 'high',
        'queue' => 'priority',
    ]);

    StepDispatcher::dispatch('cleanup-test');

    expect($prioritySuccessor->fresh()->state)->toBeInstanceOf(Cancelled::class);
    Queue::assertNothingPushed();
});
