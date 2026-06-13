<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob;
use StepDispatcher\Tests\Fixtures\SpawningParentTestJob;

/*
|--------------------------------------------------------------------------
| Parent Completion Guard
|--------------------------------------------------------------------------
|
| A Running parent must not complete while its children are unconcluded.
| That rule lives in RunningToCompleted::canTransition() so the state
| machine is honest: an attempt against an unconcluded parent throws
| CouldNotPerformTransition instead of silently doing nothing while the
| framework still fires StateChanged (the pre-fix behavior — callers and
| event listeners observed a Running→Completed "transition" that never
| happened).
|
| The job lifecycle knows the rule too: a parent job that spawned children
| finishes its run still Running, and the dispatcher's
| transitionParentsToComplete sweep completes it later.
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
});

function makeRunningParentWithChild(string $childState = Pending::class): Step
{
    $childBlockUuid = 'completion-guard-children-'.uniqid();

    $parent = Step::create([
        'class' => PrefixCarryingTestJob::class,
        'block_uuid' => 'completion-guard-'.uniqid(),
        'child_block_uuid' => $childBlockUuid,
        'index' => 1,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'state' => Pending::class,
    ]);

    $child = Step::create([
        'class' => PrefixCarryingTestJob::class,
        'block_uuid' => $childBlockUuid,
        'index' => 1,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'state' => Pending::class,
    ]);

    DB::table(Step::tableName())->where('id', $parent->id)->update(['state' => Running::class]);
    DB::table(Step::tableName())->where('id', $child->id)->update(['state' => $childState]);

    return $parent->fresh();
}

it('refuses to complete a parent whose children are unconcluded', function (): void {
    $parent = makeRunningParentWithChild(Pending::class);

    expect(fn () => $parent->state->transitionTo(Completed::class))
        ->toThrow(CouldNotPerformTransition::class);

    expect($parent->fresh()->state)->toBeInstanceOf(Running::class);
});

it('completes a parent once all children are concluded', function (): void {
    $parent = makeRunningParentWithChild(Completed::class);

    $parent->state->transitionTo(Completed::class);

    expect($parent->fresh()->state)->toBeInstanceOf(Completed::class);
});

it('leaves a spawning parent Running after its job run, without throwing', function (): void {
    $parent = Step::create([
        'class' => SpawningParentTestJob::class,
        'block_uuid' => 'spawning-parent-'.uniqid(),
        'child_block_uuid' => 'spawned-children-'.uniqid(),
        'index' => 1,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'test-group',
        'state' => Pending::class,
    ]);

    // Sync queue: the parent job executes inline and spawns its child.
    StepDispatcher::dispatch('test-group');

    $parent->refresh();
    expect($parent->state)->toBeInstanceOf(Running::class)
        ->and($parent->error_message)->toBeNull()
        ->and(Step::where('block_uuid', $parent->child_block_uuid)->count())->toBe(1);
});
