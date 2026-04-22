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
