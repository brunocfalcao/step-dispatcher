<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\ModelStates\Exceptions\TransitionNotAllowed;
use StepDispatcher\Models\Step;
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
use StepDispatcher\Tests\Fixtures\GuardedTestJob;
use StepDispatcher\Transitions\PendingToDispatched;

/*
|--------------------------------------------------------------------------
| The Skipped Parent Rule
|--------------------------------------------------------------------------
|
| Skipped on a parent means everything inside it was skipped. Two things
| follow, and this file pins both:
|
|   1. Nothing starts under a Skipped ancestor, at any depth. Neither the
|      dispatcher nor a worker holding an already Dispatched job may start it.
|   2. A parent cannot become Skipped once anything inside it has started.
|      Its job's skip is refused, its own work does not run, it stays Running
|      and ends by the usual parent rule.
|
*/

const RULE_GROUP = 'skip-rule';

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
    GuardedTestJob::reset();
});

afterEach(function (): void {
    GuardedTestJob::reset();
});

/**
 * @param  array<string, mixed>  $columns  columns written directly, after creation
 */
function ruleStep(string $state, ?string $block = null, ?string $childBlock = null, array $columns = []): Step
{
    $step = Step::create([
        'class' => GuardedTestJob::class,
        'type' => $columns['type'] ?? 'default',
        'queue' => 'default',
        'group' => RULE_GROUP,
        'index' => array_key_exists('index', $columns) ? $columns['index'] : 1,
        'block_uuid' => $block ?? (string) Str::uuid(),
        'child_block_uuid' => $childBlock,
    ]);

    unset($columns['type'], $columns['index']);

    Step::query()->whereKey($step->id)->update(array_merge(['state' => $state], $columns));

    return $step->fresh();
}

/**
 * Builds a chain from the top down and returns the steps in that order. Each
 * step except the last is the parent of the next.
 *
 * @param  list<string>  $states
 * @return list<Step>
 */
function ruleChain(array $states): array
{
    $steps = [];
    $block = (string) Str::uuid();

    foreach ($states as $position => $state) {
        $childBlock = $position === array_key_last($states) ? null : (string) Str::uuid();
        $steps[] = ruleStep($state, $block, $childBlock);
        $block = $childBlock;
    }

    return $steps;
}

/**
 * @return array{batch: bool, cache: bool, database: bool}
 */
function ruleVerdicts(Step $step): array
{
    $pending = collect([$step]);
    $cache = StepDispatcher::buildStepsCache($pending, RULE_GROUP);

    return [
        'batch' => StepDispatcher::computeDispatchableSteps($pending, $cache)->contains('id', $step->id),
        'cache' => (new PendingToDispatched($step, $cache))->canTransition(),
        'database' => (new PendingToDispatched($step))->canTransition(),
    ];
}

function ruleRun(Step $step): Step
{
    $job = new GuardedTestJob;
    $job->step = $step;
    $job->handle();

    return $step->fresh();
}

function ruleState(Step $step): string
{
    return get_class($step->fresh()->state);
}

$nobodyDispatches = ['batch' => false, 'cache' => false, 'database' => false];
$everyoneDispatches = ['batch' => true, 'cache' => true, 'database' => true];

/*
|--------------------------------------------------------------------------
| Nothing is Dispatched under a Skipped ancestor
|--------------------------------------------------------------------------
*/

it('does not dispatch a step whose ancestor is Skipped, whatever the steps between them are doing', function (array $above) use ($nobodyDispatches): void {
    $chain = ruleChain([...$above, Pending::class]);

    expect(ruleVerdicts($chain[array_key_last($chain)]))->toBe($nobodyDispatches);
})->with([
    'parent Skipped' => [[Skipped::class]],
    'grandparent Skipped, parent Running' => [[Skipped::class, Running::class]],
    'grandparent Skipped, parent Completed' => [[Skipped::class, Completed::class]],
    'three levels up Skipped' => [[Skipped::class, Completed::class, Running::class]],
    'four levels up Skipped' => [[Skipped::class, Completed::class, Completed::class, Running::class]],
    'Skipped in the middle of the chain' => [[Completed::class, Skipped::class, Running::class]],
]);

it('still dispatches a step when no ancestor is Skipped', function (array $above) use ($everyoneDispatches): void {
    $chain = ruleChain([...$above, Pending::class]);

    expect(ruleVerdicts($chain[array_key_last($chain)]))->toBe($everyoneDispatches);
})->with([
    'parent Running' => [[Running::class]],
    'parent Completed' => [[Completed::class]],
    'grandparent Running, parent Running' => [[Running::class, Running::class]],
    'grandparent Completed, parent Running' => [[Completed::class, Running::class]],
    'three levels, none Skipped' => [[Completed::class, Completed::class, Running::class]],
]);

it('does not dispatch a resolver whose parent or grandparent is Skipped', function (?int $index, bool $viaGrandparent) use ($nobodyDispatches): void {
    $childBlock = (string) Str::uuid();

    if ($viaGrandparent) {
        $middleBlock = (string) Str::uuid();
        ruleStep(Skipped::class, null, $middleBlock);
        ruleStep(Running::class, $middleBlock, $childBlock);
    } else {
        ruleStep(Skipped::class, null, $childBlock);
    }

    $resolver = ruleStep(Pending::class, $childBlock, null, ['type' => 'resolve-exception', 'index' => $index]);

    expect(ruleVerdicts($resolver))->toBe($nobodyDispatches);
})->with([
    'no position, parent Skipped' => [null, false],
    'no position, grandparent Skipped' => [null, true],
    'first position, parent Skipped' => [1, false],
    'first position, grandparent Skipped' => [1, true],
]);

it('still dispatches a resolver with no position under a Running parent', function () use ($everyoneDispatches): void {
    $childBlock = (string) Str::uuid();
    ruleStep(Running::class, null, $childBlock);

    expect(ruleVerdicts(ruleStep(Pending::class, $childBlock, null, ['type' => 'resolve-exception', 'index' => null])))
        ->toBe($everyoneDispatches);
});

it('gives an answer when parents form a loop', function () use ($nobodyDispatches): void {
    $blockA = (string) Str::uuid();
    $blockB = (string) Str::uuid();

    $first = ruleStep(Pending::class, $blockA, $blockB);
    ruleStep(Pending::class, $blockB, $blockA);

    expect(ruleVerdicts($first))->toBe($nobodyDispatches);
});

it('lets a high priority grandchild of a Skipped grandparent be skipped, never started, on a real tick', function (): void {
    Queue::fake();

    [$top, $middle, $child] = ruleChain([Skipped::class, Running::class, Pending::class]);
    Step::query()->whereKey($child->id)->update(['priority' => 'high', 'queue' => 'priority']);

    StepDispatcher::dispatch(RULE_GROUP);

    Queue::assertNothingPushed();
    expect(ruleState($child))->toBe(Skipped::class)
        ->and(GuardedTestJob::$computeRuns)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| No worker starts a step under a Skipped ancestor
|--------------------------------------------------------------------------
*/

it('does not start a Dispatched step whose ancestor is Skipped, and the step ends Skipped', function (array $above): void {
    $chain = ruleChain([...$above, Dispatched::class]);
    $child = $chain[array_key_last($chain)];

    $fresh = ruleRun($child);

    expect(get_class($fresh->state))->toBe(Skipped::class)
        ->and($fresh->started_at)->toBeNull()
        ->and(GuardedTestJob::$computeRuns)->toBe(0);
})->with([
    'parent Skipped' => [[Skipped::class]],
    'grandparent Skipped, parent Running' => [[Skipped::class, Running::class]],
    'grandparent Skipped, parent Completed' => [[Skipped::class, Completed::class]],
]);

it('ends a refused step Skipped when its Skipped parent belongs to another dispatcher group', function (): void {
    $childBlock = (string) Str::uuid();
    ruleStep(Skipped::class, null, $childBlock, ['group' => 'another-group']);
    $child = ruleStep(Dispatched::class, $childBlock);

    expect(get_class(ruleRun($child)->state))->toBe(Skipped::class)
        ->and(GuardedTestJob::$computeRuns)->toBe(0);
});

it('starts a Dispatched step when no ancestor is Skipped', function (array $above): void {
    $chain = ruleChain([...$above, Dispatched::class]);

    expect(get_class(ruleRun($chain[array_key_last($chain)])->state))->toBe(Completed::class)
        ->and(GuardedTestJob::$computeRuns)->toBe(1);
})->with([
    'no parent' => [[]],
    'parent Running' => [[Running::class]],
    'grandparent Completed, parent Running' => [[Completed::class, Running::class]],
]);

/*
|--------------------------------------------------------------------------
| A parent cannot become Skipped once anything inside it has started
|--------------------------------------------------------------------------
*/

it('refuses the skip when a child has started, and the parent stays Running without doing its work', function (string $childState, array $columns): void {
    GuardedTestJob::$skip = true;

    $childBlock = (string) Str::uuid();
    $parent = ruleStep(Dispatched::class, null, $childBlock);
    $child = ruleStep($childState, $childBlock, null, $columns);

    $fresh = ruleRun($parent);

    expect(get_class($fresh->state))->toBe(Running::class)
        ->and($fresh->completed_at)->toBeNull()
        ->and(GuardedTestJob::$computeRuns)->toBe(0)
        ->and(ruleState($child))->toBe($childState);
})->with([
    'Dispatched' => [Dispatched::class, []],
    'Running' => [Running::class, []],
    'Completed' => [Completed::class, []],
    'Failed' => [Failed::class, []],
    'Stopped' => [Stopped::class, []],
    'Pending after a retry' => [Pending::class, ['retries' => 1]],
    'Pending after a reschedule' => [Pending::class, ['is_throttled' => true]],
    'Cancelled after a retry' => [Cancelled::class, ['retries' => 1]],
    'Skipped after a retry' => [Skipped::class, ['retries' => 1]],
    'Cancelled after a reschedule' => [Cancelled::class, ['was_throttled' => true]],
    'Skipped after a reschedule' => [Skipped::class, ['was_throttled' => true]],
]);

it('refuses the skip when something deeper inside has started', function (string $grandchildState): void {
    GuardedTestJob::$skip = true;

    $childBlock = (string) Str::uuid();
    $grandchildBlock = (string) Str::uuid();
    $parent = ruleStep(Dispatched::class, null, $childBlock);
    ruleStep(Skipped::class, $childBlock, $grandchildBlock);
    ruleStep($grandchildState, $grandchildBlock);

    expect(get_class(ruleRun($parent)->state))->toBe(Running::class)
        ->and(GuardedTestJob::$computeRuns)->toBe(0);
})->with([
    'Dispatched' => [Dispatched::class],
    'Running' => [Running::class],
    'Completed' => [Completed::class],
]);

it('lets the parent go Skipped when nothing inside has started', function (array $children): void {
    GuardedTestJob::$skip = true;

    $childBlock = (string) Str::uuid();
    $parent = ruleStep(Dispatched::class, null, $childBlock);

    foreach ($children as $child) {
        ruleStep($child[0], $childBlock, null, $child[1]);
    }

    expect(get_class(ruleRun($parent)->state))->toBe(Skipped::class)
        ->and(GuardedTestJob::$computeRuns)->toBe(0);
})->with([
    'no children yet' => [[]],
    'Pending' => [[[Pending::class, []]]],
    'NotRunnable' => [[[NotRunnable::class, []]]],
    'Cancelled' => [[[Cancelled::class, []]]],
    'Skipped by its own start check' => [[[Skipped::class, ['started_at' => '2026-01-01 00:00:00']]]],
    'a mix of them' => [[[Pending::class, []], [NotRunnable::class, []], [Cancelled::class, []], [Skipped::class, []]]],
]);

it('still skips a step that is not a parent', function (): void {
    GuardedTestJob::$skip = true;

    expect(get_class(ruleRun(ruleStep(Dispatched::class))->state))->toBe(Skipped::class);
});

it('still skips a parent, then its Pending children on the next tick', function (): void {
    Queue::fake();
    GuardedTestJob::$skip = true;

    $childBlock = (string) Str::uuid();
    $parent = ruleStep(Dispatched::class, null, $childBlock);
    $child = ruleStep(Pending::class, $childBlock);

    expect(get_class(ruleRun($parent)->state))->toBe(Skipped::class);

    StepDispatcher::dispatch(RULE_GROUP);

    Queue::assertNothingPushed();
    expect(ruleState($child))->toBe(Skipped::class);
});

it('ends a refused parent by the usual rule once its children conclude', function (string $childState, string $parentEnds): void {
    Queue::fake();
    GuardedTestJob::$skip = true;

    $childBlock = (string) Str::uuid();
    $parent = ruleStep(Dispatched::class, null, $childBlock);
    ruleStep($childState, $childBlock);

    ruleRun($parent);

    for ($tick = 0; $tick < 10 && ruleState($parent) === Running::class; $tick++) {
        StepDispatcher::dispatch(RULE_GROUP);
    }

    expect(ruleState($parent))->toBe($parentEnds)
        ->and(GuardedTestJob::$computeRuns)->toBe(0);
})->with([
    'child Completed' => [Completed::class, Completed::class],
    'child Failed' => [Failed::class, Failed::class],
    'child Stopped' => [Stopped::class, Stopped::class],
]);

it('refuses a direct move to Skipped too, so the rule holds for any caller', function (): void {
    $childBlock = (string) Str::uuid();
    $parent = ruleStep(Running::class, null, $childBlock);
    ruleStep(Running::class, $childBlock);

    expect(fn () => $parent->state->transitionTo(Skipped::class))->toThrow(TransitionNotAllowed::class)
        ->and(ruleState($parent))->toBe(Running::class);
});

it('allows a direct move to Skipped when nothing inside has started', function (): void {
    $childBlock = (string) Str::uuid();
    $parent = ruleStep(Running::class, null, $childBlock);
    ruleStep(Pending::class, $childBlock);

    $parent->state->transitionTo(Skipped::class);

    expect(ruleState($parent))->toBe(Skipped::class);
});

it('does not count a parent as its own child when its block loops back to itself', function (): void {
    GuardedTestJob::$skip = true;

    $block = (string) Str::uuid();
    $parent = ruleStep(Dispatched::class, $block, $block);

    expect(get_class(ruleRun($parent)->state))->toBe(Skipped::class);
});

it('gives an answer when the blocks below a parent form a loop', function (): void {
    GuardedTestJob::$skip = true;

    $looping = (string) Str::uuid();
    $below = (string) Str::uuid();

    $parent = ruleStep(Dispatched::class, null, $looping);
    ruleStep(Pending::class, $looping, $below);
    ruleStep(Pending::class, $below, $looping);

    expect(get_class(ruleRun($parent)->state))->toBe(Skipped::class);
});

/*
|--------------------------------------------------------------------------
| The skip sweep leaves a step that has started alone
|--------------------------------------------------------------------------
*/

it('never marks a Running step Skipped, and lets it finish with its real result', function (): void {
    Queue::fake();

    $childBlock = (string) Str::uuid();
    ruleStep(Skipped::class, null, $childBlock);
    $child = ruleStep(Running::class, $childBlock);

    expect(StepDispatcher::skipAllChildStepsOnParentAndChildSingleStep(RULE_GROUP))->toBeFalse()
        ->and(ruleState($child))->toBe(Running::class);

    $child->fresh()->state->transitionTo(Completed::class);

    expect(ruleState($child))->toBe(Completed::class);
});

it('skips what has not started beside a Running step', function (string $state): void {
    $childBlock = (string) Str::uuid();
    ruleStep(Skipped::class, null, $childBlock);
    $running = ruleStep(Running::class, $childBlock);
    $other = ruleStep($state, $childBlock);

    expect(StepDispatcher::skipAllChildStepsOnParentAndChildSingleStep(RULE_GROUP))->toBeTrue()
        ->and(ruleState($other))->toBe(Skipped::class)
        ->and(ruleState($running))->toBe(Running::class);
})->with([
    'Pending' => [Pending::class],
    'Dispatched' => [Dispatched::class],
    'NotRunnable' => [NotRunnable::class],
]);

it('leaves a step alone when it is already Running by the time the batch reaches it', function (): void {
    $step = ruleStep(Running::class);

    expect(StepDispatcher::batchTransitionSteps([$step->id], Skipped::class, [Running::class]))->toBe(0)
        ->and(ruleState($step))->toBe(Running::class);
});
