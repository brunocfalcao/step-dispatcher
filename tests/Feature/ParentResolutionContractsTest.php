<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Running;
use StepDispatcher\Support\StepDispatcher;

beforeEach(function () {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

/**
 * Seed a bare step row without firing observer events (bypasses the
 * MySQL-specific round-robin group assignment that breaks on SQLite).
 */
function seedParentResolutionStep(array $attrs): Step
{
    $step = Step::create(array_merge([
        'class' => 'App\\Jobs\\TestJob',
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'index' => 1,
        'block_uuid' => (string) Str::uuid(),
    ], $attrs));

    return $step;
}

function forceState(Step $step, string $stateClass, array $extra = []): void
{
    Step::withoutEvents(function () use ($step, $stateClass, $extra) {
        Step::where('id', $step->id)->update(array_merge(['state' => $stateClass], $extra));
    });
}

/**
 * M2 — transitionParentsToComplete silently swallows exceptions.
 *
 * The catch block at `StepDispatcher.php:299-301` is empty. If
 * `transitionTo(Completed::class)` throws (DB deadlock, state-machine
 * TransitionNotFound, etc.), the exception vanishes — no log entry, no
 * alert, no error_message on the step. Operators see a parent stuck
 * Running with no trace of why, and the dispatcher happily keeps running
 * as though nothing went wrong.
 *
 * Contract: when an exception is thrown inside the transition call, the
 * package MUST surface it through Laravel's Log facade so operators can
 * diagnose stuck parents.
 */
it('logs an exception when transitionParentsToComplete fails to transition a parent', function () {
    // Arrange: a parent with a concluded child, so the "should complete"
    // path fires inside transitionParentsToComplete.
    $childBlock = (string) Str::uuid();
    $parent = seedParentResolutionStep([
        'child_block_uuid' => $childBlock,
    ]);
    forceState($parent, Running::class, ['started_at' => now()->subMinute()]);

    $child = seedParentResolutionStep([
        'block_uuid' => $childBlock,
    ]);
    forceState($child, Completed::class, ['completed_at' => now()]);

    // Inject the fault: when the state-machine transition tries to save
    // the parent with state=Completed, a DB-level failure is simulated by
    // throwing from the saving observer. This is functionally equivalent
    // to what the real world throws at us (DB deadlock, constraint
    // violation, stale state). Without the fix, the outer catch swallows
    // it and the dispatcher silently moves on — the parent stays Running
    // with no trace of why, and the operator has nothing to grep for.
    Step::saving(function (Step $saving) use ($parent) {
        if ($saving->id === $parent->id && $saving->state instanceof Completed) {
            throw new \RuntimeException('Simulated DB failure on parent completion');
        }
    });

    Log::spy();

    // Act
    StepDispatcher::transitionParentsToComplete('test-group');

    // Assert — without the fix the catch is a silent no-op (`// Log exception
    // if needed` with no body). The contract says surface it through Log so
    // operators can diagnose a parent stuck Running.
    Log::shouldHaveReceived('error')
        ->atLeast()->once();
});

/**
 * M3 — parent with all children Cancelled stays stuck in Running.
 *
 * `childStepsAreConcluded` only treats Completed/Skipped as "concluded".
 * Cancelled children are terminal but not concluded, so the parent's
 * Running → Completed transition is blocked inside
 * `RunningToCompleted::handle()`. And because there's no dispatcher-tick
 * method that notices "parent Running, all children Cancelled" and acts
 * on it, the parent stays Running permanently. The workflow tree never
 * settles and the dispatcher tick re-examines the same stuck parent
 * every second.
 *
 * Contract: when a parent's entire child tree is in a terminal state
 * (of any kind), the parent MUST leave Running within one dispatcher
 * tick. Whether it lands on Completed, Cancelled, or Failed is a design
 * choice — the non-negotiable part is that it doesn't stay Running.
 */
/**
 * Z1 — pre-set child_block_uuid with zero children = zombie parent.
 *
 * If a step is created with `child_block_uuid` populated but no child
 * row is ever inserted under that block, `childStepsAreConcludedFromMap`
 * returns false (empty children → not concluded) and the parent stays
 * Running forever. The dispatcher's `transitionParentsToComplete` pass
 * inspects this parent every tick and burns budget without ever
 * resolving it, eventually wedging the whole group.
 *
 * Contract: framework treats this as a permanent NOT-concluded — there
 * is no way to distinguish "block intentionally empty" from "children
 * not yet inserted, race in flight." So the consumer-side rule is
 * non-negotiable: do NOT pre-set `child_block_uuid` at Step::create
 * time. Use `$step->makeItAParent()` from inside compute() at the
 * moment children are actually being spawned.
 *
 * This test locks the framework behavior down so any future "loosen
 * the contract to auto-conclude empty blocks" change is caught — that
 * would silently mask consumer-side zombies again.
 */
it('leaves a parent stuck Running when child_block_uuid is set but no children exist', function () {
    $childBlock = (string) Str::uuid();
    $parent = seedParentResolutionStep([
        'child_block_uuid' => $childBlock,
    ]);
    forceState($parent, Running::class, ['started_at' => now()->subMinute()]);

    StepDispatcher::transitionParentsToFailed('test-group');
    StepDispatcher::transitionParentsToComplete('test-group');

    $fresh = Step::find($parent->id);

    expect($fresh->state)->toBeInstanceOf(
        Running::class,
        'framework intentionally treats empty child block as NOT concluded; the consumer is responsible for only pre-setting child_block_uuid when it has actually committed to spawning children'
    );
});

/**
 * Z2 — `makeItAParent()` is the only sanctioned way to elect a step as
 * a parent. The helper MUST persist the generated UUID on the step row
 * (so the dispatcher's parent-resolution pass can find children via
 * `block_uuid = child_block_uuid`) and return that same UUID for the
 * caller to use when creating children.
 *
 * Lock this contract down: if a future refactor makes the helper
 * forget to write to the row (returning a UUID without persisting it),
 * children would be inserted under a UUID the parent doesn't know
 * about, recreating the zombie pattern from a different angle.
 */
it('persists child_block_uuid on the step row when makeItAParent is called', function () {
    $parent = seedParentResolutionStep([]);

    expect($parent->child_block_uuid)->toBeNull();

    $childBlockUuid = $parent->makeItAParent();
    $fresh = Step::find($parent->id);

    expect($fresh->child_block_uuid)
        ->not->toBeNull()
        ->toBe($childBlockUuid);
});

it('does not leave a parent stuck in Running when every child ended Cancelled', function () {
    $childBlock = (string) Str::uuid();
    $parent = seedParentResolutionStep([
        'child_block_uuid' => $childBlock,
    ]);
    forceState($parent, Running::class, ['started_at' => now()->subMinute()]);

    $childA = seedParentResolutionStep(['block_uuid' => $childBlock]);
    forceState($childA, Cancelled::class);

    $childB = seedParentResolutionStep(['block_uuid' => $childBlock]);
    forceState($childB, Cancelled::class);

    // One dispatcher tick's worth of parent-resolution passes.
    StepDispatcher::transitionParentsToFailed('test-group');
    StepDispatcher::transitionParentsToComplete('test-group');

    $fresh = Step::find($parent->id);

    expect($fresh->state)->not->toBeInstanceOf(
        Running::class,
        'parent must not stay Running when every child is in a terminal state'
    );
});
