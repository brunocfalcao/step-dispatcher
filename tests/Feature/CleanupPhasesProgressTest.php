<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Skipped;
use StepDispatcher\Support\StepDispatcher;

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
 * promoteResolveExceptionSteps has the same `return true` shape on a path
 * that did no work. Direct simulation of the race is awkward (it requires
 * a state mutation between two SELECT queries inside the static method),
 * so instead this test pins the contract via source-level inspection: the
 * method body must not unconditionally `return true` after the
 * `batchTransitionSteps` call — the return must be gated on whether work
 * was actually done. Defensive against a regression that re-introduces
 * the bug from a different angle.
 */
it('promoteResolveExceptionSteps source guards against returning true on empty work', function () {
    $reflection = new ReflectionMethod(StepDispatcher::class, 'promoteResolveExceptionSteps');
    $body = file(StepDispatcher::class
        ? (new ReflectionClass(StepDispatcher::class))->getFileName()
        : '');

    $start = $reflection->getStartLine();
    $end = $reflection->getEndLine();
    $source = implode('', array_slice($body, $start - 1, $end - $start + 1));

    // After the fix, the return decision should reference $stepIds (or an
    // explicit work-done flag) — never a bare `return true;` floating
    // outside an `if ($stepIds)` guard. The exact string we forbid is the
    // closing pattern of the buggy version: `}\n        return true;\n    }`
    // immediately following the `batchTransitionSteps` block.
    expect($source)->not->toMatch(
        '/batchTransitionSteps\([^)]*\);\s*}\s*return\s+true;\s*}/s',
        'promoteResolveExceptionSteps must not unconditionally return true after batchTransitionSteps — '
        .'when stepIds is empty (race with a parallel tick that already promoted the resolve-exception), '
        .'returning true blocks the dispatcher just like the skipAll bug.'
    );
});
